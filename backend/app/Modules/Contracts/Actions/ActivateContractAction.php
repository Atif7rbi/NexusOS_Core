<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractNotDraftException;
use App\Modules\Contracts\Exceptions\ContractReservationStateException;
use App\Modules\Contracts\Exceptions\ContractUnitStateException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class ActivateContractAction
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

            if ($contract->status !== ContractStatus::Draft) {
                throw new ContractNotDraftException();
            }

            $reservation = Reservation::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contract->reservation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                throw new ContractReservationStateException();
            }

            $unit = Unit::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($reservation->unit_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($unit->status !== UnitStatus::Reserved) {
                throw new ContractUnitStateException();
            }

            $contract->forceFill([
                'status' => ContractStatus::Active,
                'activated_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            $reservation->forceFill([
                'status' => ReservationStatus::Converted,
            ])->save();

            $unit->update([
                'status' => UnitStatus::Sold,
            ]);

            return $contract;
        });
    }
}
