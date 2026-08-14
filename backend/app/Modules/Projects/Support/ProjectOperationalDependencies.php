<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;

final class ProjectOperationalDependencies
{
    public function hasActiveReservation(
        string $tenantId,
        string $projectId,
    ): bool {
        return Reservation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ReservationStatus::Active->value)
            ->whereHas(
                'unit',
                fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('project_id', $projectId),
            )
            ->exists();
    }

    public function hasLiveContract(
        string $tenantId,
        string $projectId,
    ): bool {
        return Contract::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                ContractStatus::Draft->value,
                ContractStatus::Active->value,
            ])
            ->whereHas(
                'reservation',
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->whereHas(
                'reservation.unit',
                fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('project_id', $projectId),
            )
            ->exists();
    }

    public function hasSoldUnit(
        string $tenantId,
        string $projectId,
    ): bool {
        return Unit::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', UnitStatus::Sold->value)
            ->exists();
    }
}
