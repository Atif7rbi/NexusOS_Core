<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Actions;

use App\Models\User;
use App\Modules\ContractConsideration\Support\ContractConsiderationAdoption;
use App\Modules\ContractConsideration\Support\ContractConsiderationTransaction;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RecoverContractConsiderationAdoption
{
    public function __construct(
        private readonly ContractConsiderationTransaction $transaction,
        private readonly ContractConsiderationAdoption $adoption,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Consideration adoption recovery requires a fresh transaction.');
        }
        [$contractId, $operationId] = $this->adoption->identities($tenantId, $input);

        return $this->transaction->run(function () use ($tenantId, $actor, $contractId, $operationId): array {
            $contract = $this->adoption->lockContract($tenantId, $contractId, $actor);

            return $this->adoption->resolve($tenantId, $contract, $operationId)
                ?? ['status' => 'retryable', 'position_id' => null, 'genesis_lot_id' => null];
        });
    }
}
