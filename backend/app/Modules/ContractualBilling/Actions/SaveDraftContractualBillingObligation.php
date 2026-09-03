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

final class SaveDraftContractualBillingObligation
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
            'obligation_operation_id',
        );

        $amount = ContractualBillingFacts::amount($input);

        $dueDate = ContractualBillingFacts::date(
            $input,
            'contractual_due_date',
        );

        $reference = ContractualBillingFacts::text(
            $input,
            'contractual_reference',
        );

        $obligationId = isset($input['obligation_id'])
            ? ContractualBillingFacts::ulid(
                $input,
                'obligation_id',
            )
            : null;

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $scheduleId,
            $actor,
            $operationId,
            $amount,
            $dueDate,
            $reference,
            $obligationId,
        ): string {
            $this->auth->authorizeTransactional(
                $tenantId,
                $actor,
            );

            /*
             * Resolve immutable provenance without locking Schedule yet,
             * then acquire canonical aggregate order:
             *
             * Contract -> Schedule -> Obligation.
             */
            $scheduleIdentity = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->first();

            if ($scheduleIdentity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleIdentity->contract_id)
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
                    'Schedule provenance changed during draft mutation.',
                );
            }

            if ($schedule->status !== 'draft') {
                throw new ContractualBillingConflict(
                    'Only a draft Schedule can be edited.',
                );
            }

            $customerId = DB::table('reservations')
                ->where('tenant_id', $tenantId)
                ->where('id', $contract->reservation_id)
                ->value('customer_id');

            if (
                ! is_string($customerId)
                || $customerId === ''
            ) {
                throw new ContractualBillingConflict(
                    'Contract customer provenance is unavailable.',
                );
            }

            if ($obligationId !== null) {
                $obligation = DB::table(
                    'contractual_billing_obligations',
                )
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligationId)
                    ->lockForUpdate()
                    ->first();

                if (
                    $obligation === null
                    || $obligation->schedule_id !== $scheduleId
                    || $obligation->contract_id !== $contract->id
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel('ContractualBillingObligation');
                }

                if (
                    $obligation->draft_membership_status
                    !== 'included'
                ) {
                    throw new ContractualBillingConflict(
                        'Removed draft Obligations cannot be edited.',
                    );
                }

                if (
                    $obligation->obligation_operation_id
                    !== $operationId
                ) {
                    throw new ContractualBillingConflict(
                        'Obligation operation identity cannot be changed.',
                    );
                }

                DB::table('contractual_billing_obligations')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $obligationId)
                    ->update([
                        'customer_id' => $customerId,
                        'amount' => $amount,
                        'currency' => 'SAR',
                        'contractual_due_date' => $dueDate,
                        'trigger_kind' => 'fixed_date_unconditional',
                        'contractual_reference' => $reference,
                        'updated_at' => now(),
                    ]);

                return $obligationId;
            }

            $existing = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'obligation_operation_id',
                    $operationId,
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (
                    $existing->schedule_id !== $scheduleId
                    || $existing->contract_id !== $contract->id
                    || $existing->customer_id !== $customerId
                    || (string) $existing->amount !== $amount
                    || (string) $existing->contractual_due_date
                        !== $dueDate
                    || $existing->contractual_reference
                        !== $reference
                    || $existing->draft_membership_status
                        !== 'included'
                ) {
                    throw new ContractualBillingConflict(
                        'Obligation operation identity was reused with different facts.',
                    );
                }

                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            $now = now();

            DB::table('contractual_billing_obligations')
                ->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'schedule_id' => $scheduleId,
                    'contract_id' => (string) $contract->id,
                    'obligation_operation_id' => $operationId,
                    'customer_id' => $customerId,
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'contractual_due_date' => $dueDate,
                    'trigger_kind' => 'fixed_date_unconditional',
                    'contractual_reference' => $reference,
                    'draft_membership_status' => 'included',
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            return $id;
        });
    }
}
