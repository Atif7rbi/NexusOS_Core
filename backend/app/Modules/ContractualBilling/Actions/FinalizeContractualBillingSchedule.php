<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinalizeContractualBillingSchedule
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        string $scheduleId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($scheduleId)) {
            throw new ContractualBillingValidationFailed(
                'schedule_id must be a ULID.',
            );
        }

        $operationId = ContractualBillingFacts::operation(
            $input,
            'finalization_operation_id',
        );

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $scheduleId,
            $actor,
            $operationId,
        ): string {
            /*
             * ReceivablesAuthorization establishes:
             * membership -> User -> Tenant.
             */
            $this->auth->authorizeTransactional(
                $tenantId,
                $actor,
            );

            $identity = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            /*
             * Aggregate/source order after authorization:
             * Contract -> Schedule -> Obligations ordered by ULID.
             */
            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $schedule = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->lockForUpdate()
                ->first();

            if (
                $schedule === null
                || $schedule->contract_id !== $contract->id
            ) {
                throw new ContractualBillingConflict(
                    'Schedule provenance changed during finalization.',
                );
            }

            if ($schedule->status === 'finalized') {
                if (
                    $schedule->finalization_operation_id
                    !== $operationId
                ) {
                    throw new ContractualBillingConflict(
                        'Schedule is already finalized by another operation.',
                    );
                }

                return $scheduleId;
            }

            if ($schedule->status !== 'draft') {
                throw new ContractualBillingConflict(
                    'Only a draft Schedule can be finalized.',
                );
            }

            if (
                ! in_array(
                    $contract->status,
                    ['active', 'completed'],
                    true,
                )
            ) {
                throw new ContractualBillingConflict(
                    'Schedule finalization requires an active or completed Contract.',
                );
            }

            if ($contract->currency !== 'SAR') {
                throw new ContractualBillingConflict(
                    'Contractual Billing v1 requires a SAR Contract.',
                );
            }

            $obligations = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $scheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $included = $obligations->filter(
                static fn (object $obligation): bool => $obligation->draft_membership_status
                    === 'included',
            );

            if ($included->isEmpty()) {
                throw new ContractualBillingConflict(
                    'Schedule requires at least one included Obligation.',
                );
            }

            $total = BigDecimal::zero();

            foreach ($included as $obligation) {
                if (
                    $obligation->contract_id !== $contract->id
                    || $obligation->currency !== 'SAR'
                    || $obligation->trigger_kind
                        !== 'fixed_date_unconditional'
                ) {
                    throw new ContractualBillingConflict(
                        'Schedule contains an ineligible Obligation.',
                    );
                }

                $total = $total->plus(
                    BigDecimal::of((string) $obligation->amount),
                );
            }

            if (
                $total->compareTo(
                    BigDecimal::of(
                        (string) $contract->total_amount,
                    ),
                ) !== 0
            ) {
                throw new ContractualBillingConflict(
                    'Schedule total must equal Contract total_amount.',
                );
            }

            /*
             * Tenant was already locked by transactional authorization.
             * The DB finalization trigger independently revalidates this
             * authoritative snapshot using FOR UPDATE NOWAIT.
             */
            $timezone = DB::table('tenants')
                ->where('id', $tenantId)
                ->value('timezone');

            if (
                ! is_string($timezone)
                || trim($timezone) === ''
            ) {
                throw new ContractualBillingConflict(
                    'Tenant contractual timezone is unavailable.',
                );
            }

            $now = now();

            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->update([
                    'status' => 'finalized',
                    'contractual_timezone' => $timezone,
                    'finalization_operation_id' => $operationId,
                    'finalized_by' => $actor->id,
                    'finalized_at' => $now,
                    'updated_at' => $now,
                ]);

            return $scheduleId;
        });
    }
}
