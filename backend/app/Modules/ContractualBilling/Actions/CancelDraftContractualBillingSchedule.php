<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CancelDraftContractualBillingSchedule
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
            'draft_cancellation_operation_id',
        );

        $reason = ContractualBillingFacts::text(
            $input,
            'draft_cancellation_reason',
        );

        $reference = ContractualBillingFacts::optionalText(
            $input,
            'draft_cancellation_reference',
        );

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $scheduleId,
            $actor,
            $operationId,
            $reason,
            $reference,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            $identity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $schedule = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            /*
             * Serialize against all draft membership/edit operations.
             */
            DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $scheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($schedule->status === 'cancelled') {
                if (
                    $schedule->finalization_operation_id !== null
                    || $schedule->draft_cancellation_operation_id
                        !== $operationId
                    || $schedule->draft_cancellation_reason !== $reason
                    || $schedule->draft_cancellation_reference
                        !== $reference
                ) {
                    throw new ContractualBillingConflict(
                        'Draft Schedule cancellation was replayed with different facts.',
                    );
                }

                return $scheduleId;
            }

            if ($schedule->status !== 'draft') {
                throw new ContractualBillingConflict(
                    'Only a draft Schedule can use draft cancellation.',
                );
            }

            $sameOperation = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'draft_cancellation_operation_id',
                    $operationId,
                )
                ->lockForUpdate()
                ->first();

            if (
                $sameOperation !== null
                && $sameOperation->id !== $scheduleId
            ) {
                throw new ContractualBillingConflict(
                    'Draft cancellation operation identity was reused with different facts.',
                );
            }

            $now = now();

            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->update([
                    'status' => 'cancelled',
                    'draft_cancellation_operation_id' => $operationId,
                    'draft_cancelled_by' => $actor->id,
                    'draft_cancelled_at' => $now,
                    'draft_cancellation_reason' => $reason,
                    'draft_cancellation_reference' => $reference,
                    'updated_at' => $now,
                ]);

            return $scheduleId;
        });
    }
}
