<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationNotActiveException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Shared\Time\TenantClock;
use Illuminate\Support\Facades\DB;

final class UpdateReservationAction
{
    public function __construct(
        private readonly TenantClock $clock,
    ) {
    }

    public function execute(string $tenantId, string $reservationId, int|string $actorId, array $data): Reservation
    {
        return DB::transaction(function () use ($tenantId, $reservationId, $actorId, $data): Reservation {
            $reservation = Reservation::query()
                ->where('tenant_id', $tenantId)->whereKey($reservationId)
                ->lockForUpdate()->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                throw new ReservationNotActiveException();
            }

            if (array_key_exists('expires_at', $data)) {
                $data['expires_at'] = $this->clock->parse(
                    $tenantId,
                    (string) $data['expires_at'],
                );
            }

            $reservation->fill([
                ...array_intersect_key($data, array_flip(['expires_at', 'notes'])),
                'updated_by' => $actorId,
            ]);
            $reservation->save();

            return $reservation;
        });
    }
}
