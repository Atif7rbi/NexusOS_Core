<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractCannotBeCancelledException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class CancelContractAction
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

            if (! in_array($contract->status, [ContractStatus::Draft, ContractStatus::Active], true)) {
                throw new ContractCannotBeCancelledException();
            }

            if ($contract->status === ContractStatus::Active) {
                $reservation = Reservation::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($contract->reservation_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $unit = Unit::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($reservation->unit_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Reservation remains 'converted'; only the unit is released.
                $unit->update([
                    'status' => UnitStatus::Available,
                ]);
            }

            $contract->forceFill([
                'status' => ContractStatus::Cancelled,
                'cancelled_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            return $contract;
        });
    }
}
