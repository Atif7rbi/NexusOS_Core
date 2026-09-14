<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Actions;

use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Support\ContractConsiderationAdoption;
use App\Modules\ContractConsideration\Support\ContractConsiderationHistory;
use App\Modules\ContractConsideration\Support\ContractConsiderationTransaction;
use Illuminate\Support\Facades\DB;

final class ReplayContractConsideration
{
    public function __construct(
        private readonly ContractConsiderationTransaction $transaction,
        private readonly ContractConsiderationAdoption $adoption,
        private readonly ContractConsiderationHistory $history,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): array
    {
        [$contractId, $operationId] = $this->adoption->identities($tenantId, $input);

        return $this->transaction->run(function () use ($tenantId, $actor, $contractId, $operationId): array {
            $contract = $this->adoption->lockContract($tenantId, $contractId, $actor);
            if ($this->adoption->resolve($tenantId, $contract, $operationId) === null) {
                throw new ContractConsiderationConflict('Contract has no committed adoption to replay.');
            }
            $position = DB::table('contract_consideration_positions')->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)->first();

            return $this->history->replay($tenantId, $position);
        });
    }
}
