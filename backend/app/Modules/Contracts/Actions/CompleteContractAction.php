<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractNotActiveException;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Support\Facades\DB;

final class CompleteContractAction
{
    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
    ): Contract {
        return DB::transaction(function () use (
            $tenantId,
            $contractId,
            $actorId,
        ): Contract {
            $contract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status !== ContractStatus::Active) {
                throw new ContractNotActiveException();
            }

            $contract->forceFill([
                'status' => ContractStatus::Completed,
                'completed_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            return $contract;
        });
    }
}
