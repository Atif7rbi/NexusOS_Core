<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Actions;

use App\Models\User;
use App\Modules\ContractConsideration\Support\ContractConsiderationAdoption;
use App\Modules\ContractConsideration\Support\ContractConsiderationTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdoptContractConsideration
{
    public function __construct(
        private readonly ContractConsiderationTransaction $transaction,
        private readonly ContractConsiderationAdoption $adoption,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): array
    {
        [$contractId, $operationId] = $this->adoption->identities($tenantId, $input);

        return $this->transaction->run(function () use ($tenantId, $actor, $contractId, $operationId): array {
            $contract = $this->adoption->lockContract($tenantId, $contractId, $actor);
            $existing = $this->adoption->resolve($tenantId, $contract, $operationId);
            if ($existing !== null) {
                return $existing;
            }
            $positionId = (string) Str::ulid();
            $genesisId = (string) Str::ulid();
            $now = CarbonImmutable::now('UTC');
            DB::table('contract_consideration_positions')->insert([
                'id' => $positionId,
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'coordination_adoption_operation_id' => $operationId,
                'status' => 'ADOPTED',
                'consideration_amount' => (string) $contract->total_amount,
                'currency' => 'SAR',
                'adoption_basis' => ContractConsiderationAdoption::BASIS,
                'coordination_scope_version' => ContractConsiderationAdoption::SCOPE,
                'adopted_by' => $actor->id,
                'adopted_at' => $now,
            ]);
            DB::table('contract_consideration_lots')->insert([
                'id' => $genesisId,
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'position_id' => $positionId,
                'transition_id' => null,
                'lot_kind' => 'GENESIS',
                'semantic_position' => 'UNPERFORMED_UNBILLED',
                'amount' => (string) $contract->total_amount,
                'currency' => 'SAR',
                'created_at' => $now,
            ]);

            return ['status' => 'committed', 'position_id' => $positionId, 'genesis_lot_id' => $genesisId];
        });
    }
}
