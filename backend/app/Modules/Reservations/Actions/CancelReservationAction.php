<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationNotActiveException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class CancelReservationAction
{
    public function execute(string $tenantId, string $reservationId, int|string $actorId, ?string $reason): Reservation
    {
        return DB::transaction(function () use ($tenantId, $reservationId, $actorId, $reason): Reservation {
            $reservation = Reservation::query()
                ->where('tenant_id', $tenantId)->whereKey($reservationId)
                ->lockForUpdate()->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                throw new ReservationNotActiveException();
            }

            $unit = Unit::query()
                ->whereKey($reservation->unit_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservation->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'updated_by' => $actorId,
            ])->save();

            $unit->update([
                'status' => UnitStatus::Available,
            ]);

            return $reservation;
        });
    }
}
