<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class EntitlementReceivableRecoveryResolver
{
    /**
     * @return array{status:'retryable'|'committed',receivable_id:?string}
     */
    public function resolveEstablishment(
        string $tenantId,
        string $entitlementId,
        string $operationId,
    ): array {
        $this->assertFreshTransaction();

        return DB::transaction(function () use (
            $tenantId,
            $entitlementId,
            $operationId,
        ): array {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $identity = DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->first();

            if ($identity === null) {
                $operationLink = DB::table('entitlement_receivable_links')
                    ->where('tenant_id', $tenantId)
                    ->where(
                        'receivable_establishment_operation_id',
                        $operationId,
                    )
                    ->first();

                $operationReceivable = DB::table('receivables')
                    ->where('tenant_id', $tenantId)
                    ->where('recognition_operation_id', $operationId)
                    ->first();

                if (
                    $operationLink !== null
                    || $operationReceivable !== null
                ) {
                    throw new ContractualBillingConflict(
                        'Receivable establishment recovery found operation truth without its Entitlement.',
                    );
                }

                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingEntitlement');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            $schedule = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->schedule_id)
                ->lockForUpdate()
                ->first();

            $obligation = DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->obligation_id)
                ->lockForUpdate()
                ->first();

            $entitlement = DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->lockForUpdate()
                ->first();

            if (
                $contract === null
                || $schedule === null
                || $obligation === null
                || $entitlement === null
            ) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found missing source truth.',
                );
            }

            $this->assertSourceIdentity(
                $contract,
                $schedule,
                $obligation,
                $entitlement,
            );

            $linkByEntitlement = DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where('entitlement_id', $entitlementId)
                ->first();

            $linkByOperation = DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where(
                    'receivable_establishment_operation_id',
                    $operationId,
                )
                ->first();

            $receivableByOperation = DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('recognition_operation_id', $operationId)
                ->first();

            if (
                $linkByEntitlement === null
                && $linkByOperation === null
                && $receivableByOperation === null
            ) {
                if ($entitlement->status !== 'effective') {
                    throw new ContractualBillingConflict(
                        'Receivable establishment recovery found consumed or ineligible Entitlement without establishment truth.',
                    );
                }

                return [
                    'status' => 'retryable',
                    'receivable_id' => null,
                ];
            }

            if (
                $linkByEntitlement === null
                || $linkByOperation === null
                || $receivableByOperation === null
                || $linkByEntitlement->id !== $linkByOperation->id
                || $linkByEntitlement->entitlement_id !== $entitlementId
                || $linkByEntitlement->receivable_id
                    !== $receivableByOperation->id
            ) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found partial or contradictory durable truth.',
                );
            }

            $receivable = DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableByOperation->id)
                ->lockForUpdate()
                ->first();

            if ($receivable === null) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found missing Receivable truth.',
                );
            }

            $link = DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where('id', $linkByEntitlement->id)
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found missing Link truth.',
                );
            }

            $this->assertCommittedPair(
                $entitlement,
                $receivable,
                $link,
                $operationId,
            );

            return [
                'status' => 'committed',
                'receivable_id' => (string) $receivable->id,
            ];
        });
    }

    private function assertSourceIdentity(
        object $contract,
        object $schedule,
        object $obligation,
        object $entitlement,
    ): void {
        if (
            $schedule->contract_id !== $contract->id
            || $obligation->contract_id !== $contract->id
            || $obligation->schedule_id !== $schedule->id
            || $entitlement->contract_id !== $contract->id
            || $entitlement->schedule_id !== $schedule->id
            || $entitlement->obligation_id !== $obligation->id
            || $entitlement->customer_id !== $obligation->customer_id
            || (string) $entitlement->amount
                !== (string) $obligation->amount
            || $entitlement->currency !== $obligation->currency
            || (string) $entitlement->economic_date
                !== (string) $obligation->contractual_due_date
        ) {
            throw new ContractualBillingConflict(
                'Receivable establishment recovery found inconsistent source provenance.',
            );
        }
    }

    private function assertCommittedPair(
        object $entitlement,
        object $receivable,
        object $link,
        string $operationId,
    ): void {
        if (
            $link->entitlement_id !== $entitlement->id
            || $link->receivable_id !== $receivable->id
            || $link->receivable_establishment_operation_id !== $operationId
            || $receivable->recognition_operation_id !== $operationId
            || $receivable->contract_id !== $entitlement->contract_id
            || $receivable->customer_id !== $entitlement->customer_id
            || $receivable->collection_id !== null
            || (string) $receivable->recognized_amount
                !== (string) $entitlement->amount
            || $receivable->currency !== $entitlement->currency
            || (string) $receivable->due_date
                !== (string) $entitlement->economic_date
        ) {
            throw new ContractualBillingConflict(
                'Receivable establishment recovery found mismatched canonical facts.',
            );
        }

        if ($entitlement->status === 'effective') {
            if (
                $receivable->status !== 'recognized'
                || $link->source_correction_operation_id !== null
            ) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found incoherent effective state.',
                );
            }

            return;
        }

        if ($entitlement->status === 'reversed') {
            if (
                $receivable->status !== 'cancelled'
                || $link->source_correction_operation_id === null
                || $link->source_correction_operation_id
                    !== $entitlement->source_correction_operation_id
            ) {
                throw new ContractualBillingConflict(
                    'Receivable establishment recovery found incoherent historical correction state.',
                );
            }

            return;
        }

        throw new ContractualBillingConflict(
            'Receivable establishment recovery found unsupported Entitlement state.',
        );
    }

    private function assertFreshTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Entitlement Receivable unknown-outcome recovery requires a fresh transaction.',
            );
        }
    }
}
