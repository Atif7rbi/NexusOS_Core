<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractNotDraftException;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Support\Facades\DB;

final class UpdateContractAction
{
    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
        string $totalAmount,
    ): Contract {
        return DB::transaction(function () use (
            $tenantId,
            $contractId,
            $actorId,
            $totalAmount,
        ): Contract {
            $contract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status !== ContractStatus::Draft) {
                throw new ContractNotDraftException();
            }

            $contract->forceFill([
                'total_amount' => $totalAmount,
                'updated_by' => $actorId,
            ])->save();

            return $contract;
        });
    }
}
