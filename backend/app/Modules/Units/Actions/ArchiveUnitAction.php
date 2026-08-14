<?php

declare(strict_types=1);

namespace App\Modules\Units\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Exceptions\UnitAlreadyArchivedException;
use App\Modules\Units\Exceptions\UnitHasLiveDependenciesException;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class ArchiveUnitAction
{
    public function execute(
        string $tenantId,
        string $unitId,
        int|string $actorId,
    ): Unit {
        return DB::transaction(function () use (
            $tenantId,
            $unitId,
            $actorId,
        ): Unit {
            $unit = Unit::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($unitId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($unit->isArchived()) {
                throw new UnitAlreadyArchivedException();
            }

            $hasActiveReservation = Reservation::query()
                ->where('tenant_id', $tenantId)
                ->where('unit_id', $unitId)
                ->where('status', ReservationStatus::Active->value)
                ->exists();

            $hasLiveContract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', [
                    ContractStatus::Draft->value,
                    ContractStatus::Active->value,
                ])
                ->whereHas(
                    'reservation',
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('unit_id', $unitId),
                )
                ->exists();

            if ($hasActiveReservation || $hasLiveContract) {
                throw new UnitHasLiveDependenciesException();
            }

            $unit->forceFill([
                'archived_at' => now(),
                'archived_by' => $actorId,
                'restored_by' => null,
                'updated_by' => $actorId,
            ])->save();

            return $unit;
        });
    }
}
