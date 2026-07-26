<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractAlreadyExistsException;
use App\Modules\Contracts\Exceptions\ContractReservationNotActiveException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use Illuminate\Support\Facades\DB;

final class CreateContractAction
{
    public function execute(
        string $tenantId,
        int|string $actorId,
        string $reservationId,
        string $totalAmount,
    ): Contract {
        return DB::transaction(function () use (
            $tenantId,
            $actorId,
            $reservationId,
            $totalAmount
        ): Contract {
            $reservation = Reservation::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                throw new ContractReservationNotActiveException();
            }

            if (Contract::query()
                ->where('reservation_id', $reservation->id)
                ->exists()) {
                throw new ContractAlreadyExistsException();
            }

            return Contract::query()->create([
                'tenant_id' => $tenantId,
                'reservation_id' => $reservation->id,
                'status' => ContractStatus::Draft,
                'total_amount' => $totalAmount,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });
    }
}
