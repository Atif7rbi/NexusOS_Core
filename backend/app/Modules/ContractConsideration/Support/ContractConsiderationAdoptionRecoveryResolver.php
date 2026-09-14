<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationIntegrityFault;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationRetryable;
use Illuminate\Support\Facades\DB;

final class ContractConsiderationAdoptionRecoveryResolver
{
    public function __construct(
        private readonly ContractConsiderationTransaction $tx,
        private readonly ContractConsiderationAuthorization $auth,
    ) {}

    public function resolve(
        string $tenantId,
        string $contractId,
        string $operationId,
        User $actor,
    ): string {
        return $this->tx->run(function () use (
            $tenantId,
            $contractId,
            $operationId,
            $actor,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new ContractConsiderationConflict(
                    'Contract Consideration adoption Contract is missing.',
                );
            }

            $byOperation = DB::table('contract_consideration_adoptions')
                ->where('tenant_id', $tenantId)
                ->where('coordination_adoption_operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if ($byOperation !== null) {
                $this->assertCanonicalAdoption(
                    $byOperation,
                    $contractId,
                    (string) $contract->total_amount,
                    (string) $contract->currency,
                );

                $this->assertCommittedState($byOperation);

                return (string) $byOperation->id;
            }

            $byContract = DB::table('contract_consideration_adoptions')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($byContract !== null) {
                throw new ContractConsiderationConflict(
                    'Contract was adopted by a different coordination operation.',
                );
            }

            $positionExists = DB::table('contract_consideration_positions')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->exists();

            $genesisExists = DB::table('contract_consideration_lots')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->exists();

            if ($positionExists || $genesisExists) {
                throw new ContractConsiderationIntegrityFault(
                    'Contract Consideration recovery found partial adoption state.',
                );
            }

            if (
                (string) $contract->currency !== 'SAR'
                || $this->hasPriorSupportedSource($tenantId, $contractId)
            ) {
                throw new ContractConsiderationConflict(
                    'Contract no longer qualifies for clean coordination adoption.',
                );
            }

            throw new ContractConsiderationRetryable(
                'Coordination adoption outcome is absent and remains retryable.',
            );
        });
    }

    public function assertCanonicalAdoption(
        object $adoption,
        string $contractId,
        string $considerationAmount,
        string $currency,
    ): void {
        if (
            $adoption->contract_id !== $contractId
            || (string) $adoption->consideration_amount !== $considerationAmount
            || $adoption->currency !== $currency
            || $adoption->adoption_basis !== ContractConsiderationFacts::ADOPTION_BASIS
            || $adoption->coordination_scope_version !== ContractConsiderationFacts::SCOPE_VERSION
            || $adoption->status !== ContractConsiderationFacts::ADOPTED
        ) {
            throw new ContractConsiderationConflict(
                'Coordination adoption operation identity was reused with different canonical facts.',
            );
        }
    }

    public function assertCommittedState(object $adoption): void
    {
        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $adoption->tenant_id)
            ->where('contract_id', $adoption->contract_id)
            ->lockForUpdate()
            ->first();

        if (
            $position === null
            || $position->adoption_id !== $adoption->id
            || (string) $position->consideration_amount !== (string) $adoption->consideration_amount
            || (string) $position->source_contract_total_snapshot !== (string) $adoption->consideration_amount
            || $position->currency !== $adoption->currency
        ) {
            throw new ContractConsiderationIntegrityFault(
                'Committed coordination adoption has inconsistent Position state.',
            );
        }

        $lots = DB::table('contract_consideration_lots')
            ->where('tenant_id', $adoption->tenant_id)
            ->where('position_root_id', $position->id)
            ->lockForUpdate()
            ->get();

        if ($lots->count() !== 1) {
            throw new ContractConsiderationIntegrityFault(
                'Committed coordination adoption must have exactly one genesis lot.',
            );
        }

        $genesis = $lots->first();

        if (
            $genesis === null
            || $genesis->contract_id !== $adoption->contract_id
            || $genesis->lot_kind !== ContractConsiderationFacts::GENESIS
            || $genesis->semantic_position !== ContractConsiderationFacts::UNPERFORMED_UNBILLED
            || (string) $genesis->amount !== (string) $adoption->consideration_amount
            || $genesis->currency !== $adoption->currency
        ) {
            throw new ContractConsiderationIntegrityFault(
                'Committed coordination adoption has inconsistent genesis state.',
            );
        }
    }

    public function hasPriorSupportedSource(
        string $tenantId,
        string $contractId,
    ): bool {
        if (
            DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->exists()
        ) {
            return true;
        }

        return DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->exists();
    }
}
