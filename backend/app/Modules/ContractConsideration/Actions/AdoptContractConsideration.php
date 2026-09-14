<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Actions;

use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationIntegrityFault;
use App\Modules\ContractConsideration\Support\ContractConsiderationAdoptionRecoveryResolver;
use App\Modules\ContractConsideration\Support\ContractConsiderationAuthorization;
use App\Modules\ContractConsideration\Support\ContractConsiderationFacts;
use App\Modules\ContractConsideration\Support\ContractConsiderationTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdoptContractConsideration
{
    public function __construct(
        private readonly ContractConsiderationTransaction $tx,
        private readonly ContractConsiderationAuthorization $auth,
        private readonly ContractConsiderationAdoptionRecoveryResolver $recovery,
    ) {}

    public function execute(
        string $tenantId,
        string $contractId,
        User $actor,
        array $input,
    ): string {
        $contractId = ContractConsiderationFacts::contractId($contractId);
        $operationId = ContractConsiderationFacts::operation($input);

        $this->auth->authorize($tenantId, $actor);

        try {
            return $this->tx->run(
                function () use (
                    $tenantId,
                    $contractId,
                    $actor,
                    $operationId,
                ): string {
                    return $this->adopt(
                        $tenantId,
                        $contractId,
                        $actor,
                        $operationId,
                    );
                },
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->recovery->resolve(
                $tenantId,
                $contractId,
                $operationId,
                $actor,
            );
        }
    }

    private function adopt(
        string $tenantId,
        string $contractId,
        User $actor,
        string $operationId,
    ): string {
        $this->auth->authorizeTransactional($tenantId, $actor);

        /*
         * Frozen adoption corridor:
         * membership -> User -> Tenant -> Contract.
         *
         * Contract is the common serialization boundary against supported
         * source activation. Coordination rows are never locked first.
         */
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

        $considerationAmount = (string) $contract->total_amount;
        $currency = (string) $contract->currency;

        if ($currency !== 'SAR') {
            throw new ContractConsiderationConflict(
                'Contract Consideration v1 requires a SAR Contract.',
            );
        }

        $byOperation = DB::table('contract_consideration_adoptions')
            ->where('tenant_id', $tenantId)
            ->where('coordination_adoption_operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if ($byOperation !== null) {
            $this->recovery->assertCanonicalAdoption(
                $byOperation,
                $contractId,
                $considerationAmount,
                $currency,
            );

            $this->recovery->assertCommittedState($byOperation);

            return (string) $byOperation->id;
        }

        $byContract = DB::table('contract_consideration_adoptions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->lockForUpdate()
            ->first();

        if ($byContract !== null) {
            throw new ContractConsiderationConflict(
                'Contract already has historical coordination adoption under a different operation identity.',
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
                'Contract has partial Contract Consideration adoption artifacts.',
            );
        }

        if (
            $this->recovery->hasPriorSupportedSource(
                $tenantId,
                $contractId,
            )
        ) {
            throw new ContractConsiderationConflict(
                'Clean coordination adoption is forbidden after supported economic source history exists.',
            );
        }

        $adoptionId = (string) Str::ulid();
        $positionId = (string) Str::ulid();
        $genesisId = (string) Str::ulid();
        $now = CarbonImmutable::now('UTC');

        DB::table('contract_consideration_adoptions')->insert([
            'id' => $adoptionId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'coordination_adoption_operation_id' => $operationId,
            'consideration_amount' => $considerationAmount,
            'currency' => $currency,
            'adoption_basis' => ContractConsiderationFacts::ADOPTION_BASIS,
            'coordination_scope_version' => ContractConsiderationFacts::SCOPE_VERSION,
            'status' => ContractConsiderationFacts::ADOPTED,
            'adopted_by' => $actor->id,
            'adopted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contract_consideration_positions')->insert([
            'id' => $positionId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'adoption_id' => $adoptionId,
            'consideration_amount' => $considerationAmount,
            'currency' => $currency,
            'source_contract_total_snapshot' => $considerationAmount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contract_consideration_lots')->insert([
            'id' => $genesisId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'position_root_id' => $positionId,
            'lot_kind' => ContractConsiderationFacts::GENESIS,
            'amount' => $considerationAmount,
            'currency' => $currency,
            'semantic_position' => ContractConsiderationFacts::UNPERFORMED_UNBILLED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $adoptionId;
    }
}
