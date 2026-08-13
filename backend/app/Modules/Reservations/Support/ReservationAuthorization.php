<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Support;

use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\Units\Models\Unit;
use Illuminate\Auth\Access\AuthorizationException;

final class ReservationAuthorization
{
    public function assertCanCreate(User $actor, Unit $unit): void
    {
        if (TenantAdministratorAuthority::allows($actor) || $actor->role === User::ROLE_SALES) {
            return;
        }

        if ($actor->role === User::ROLE_PROJECT_MANAGER && $this->isOwnProject($actor, $unit)) {
            return;
        }

        $this->deny();
    }

    public function assertCanUpdateOrCancel(User $actor, Reservation $reservation): void
    {
        if (TenantAdministratorAuthority::allows($actor)) {
            return;
        }

        if ($actor->role === User::ROLE_SALES && (int) $reservation->created_by === (int) $actor->id) {
            return;
        }

        if ($actor->role === User::ROLE_PROJECT_MANAGER && $this->isOwnProject($actor, $reservation->unit)) {
            return;
        }

        $this->deny();
    }

    private function isOwnProject(User $actor, ?Unit $unit): bool
    {
        return $unit?->project !== null
            && (int) $unit->project->project_manager_id === (int) $actor->id;
    }

    private function deny(): never
    {
        throw new AuthorizationException('ليس لديك صلاحية لتنفيذ هذا الإجراء على الحجز.');
    }
}
