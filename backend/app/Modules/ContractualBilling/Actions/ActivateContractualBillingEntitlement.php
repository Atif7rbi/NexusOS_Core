<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivateContractualBillingEntitlement
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        string $obligationId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($obligationId)) {
            throw new ContractualBillingValidationFailed(
                'obligation_id must be a ULID.',
            );
        }

        $operationId = ContractualBillingFacts::operation(
            $input,
            'billing_entitlement_operation_id',
        );

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $obligationId,
            $actor,
            $operationId,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            /*
             * Identity reads are intentionally unlocked. Canonical locks
             * follow immediately in the global source order.
             */
            $identity = DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingObligation');
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
                ->where('id', $identity->schedule_id)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $obligation = DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->lockForUpdate()
                ->first();

            if (
                $obligation === null
                || $obligation->contract_id !== $contract->id
                || $obligation->schedule_id !== $schedule->id
            ) {
                throw new ContractualBillingConflict(
                    'Entitlement source provenance changed during activation.',
                );
            }

            $byOperation = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'billing_entitlement_operation_id',
                    $operationId,
                )
                ->lockForUpdate()
                ->first();

            if ($byOperation !== null) {
                if (
                    $byOperation->obligation_id !== $obligationId
                    || $byOperation->schedule_id !== $schedule->id
                    || $byOperation->contract_id !== $contract->id
                    || $byOperation->customer_id !== $obligation->customer_id
                    || (string) $byOperation->amount
                        !== (string) $obligation->amount
                    || $byOperation->currency !== $obligation->currency
                    || (string) $byOperation->economic_date
                        !== (string) $obligation->contractual_due_date
                ) {
                    throw new ContractualBillingConflict(
                        'Entitlement operation identity was reused with different facts.',
                    );
                }

                return (string) $byOperation->id;
            }

            $byObligation = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where('obligation_id', $obligationId)
                ->lockForUpdate()
                ->first();

            if ($byObligation !== null) {
                throw new ContractualBillingConflict(
                    'Obligation already has historical Entitlement truth.',
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
                    'Entitlement activation requires an active or completed Contract.',
                );
            }

            if (
                $schedule->status !== 'finalized'
                || $schedule->contract_id !== $contract->id
            ) {
                throw new ContractualBillingConflict(
                    'Entitlement activation requires the current finalized Schedule.',
                );
            }

            if (
                $obligation->draft_membership_status !== 'included'
                || $obligation->trigger_kind
                    !== 'fixed_date_unconditional'
            ) {
                throw new ContractualBillingConflict(
                    'Obligation is not eligible for Entitlement activation.',
                );
            }

            $timezone = (string) $schedule->contractual_timezone;

            try {
                $businessDate = CarbonImmutable::now($timezone)
                    ->toDateString();
            } catch (\Throwable $exception) {
                throw new ContractualBillingConflict(
                    'Schedule contractual timezone is invalid.',
                    previous: $exception,
                );
            }

            if (
                $businessDate
                < (string) $obligation->contractual_due_date
            ) {
                throw new ContractualBillingConflict(
                    'Entitlement cannot activate before contractual due date.',
                );
            }

            $id = (string) Str::ulid();
            $now = now();

            DB::table('contractual_billing_entitlements')
                ->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'billing_entitlement_operation_id' => $operationId,
                    'schedule_id' => (string) $schedule->id,
                    'obligation_id' => $obligationId,
                    'contract_id' => (string) $contract->id,
                    'customer_id' => (string) $obligation->customer_id,
                    'amount' => (string) $obligation->amount,
                    'currency' => (string) $obligation->currency,
                    'economic_date' => (string) $obligation->contractual_due_date,
                    'effective_at' => $now,
                    'status' => 'effective',
                    'recognized_by' => $actor->id,
                    'recognized_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            return $id;
        });
    }
}
