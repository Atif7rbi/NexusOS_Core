<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Support;

use App\Models\User;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Auth\Access\AuthorizationException;

final class ContractAuthorization
{
    public function assertCanCreate(User $actor, Reservation $reservation): void
    {
        $this->assertCanOperate($actor, $reservation);
    }

    public function assertCanUpdateOrActivate(User $actor, Contract $contract): void
    {
        $this->assertCanOperate($actor, $contract->reservation);
    }

    public function assertCanCancel(User $actor, Contract $contract): void
    {
        if ($contract->status === ContractStatus::Active) {
            $this->assertCanGovern($actor);

            return;
        }

        $this->assertCanOperate($actor, $contract->reservation);
    }

    public function assertCanComplete(User $actor): void
    {
        $this->assertCanGovern($actor);
    }

    private function assertCanOperate(User $actor, ?Reservation $reservation): void
    {
        if (TenantAdministratorAuthority::allows($actor)) {
            return;
        }

        if ($actor->role === User::ROLE_SALES && (int) $reservation?->created_by === (int) $actor->id) {
            return;
        }

        $project = $reservation?->unit?->project;
        if ($actor->role === User::ROLE_PROJECT_MANAGER && (int) $project?->project_manager_id === (int) $actor->id) {
            return;
        }

        $this->deny();
    }

    private function assertCanGovern(User $actor): void
    {
        if (! TenantAdministratorAuthority::allows($actor)) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw new AuthorizationException('ليس لديك صلاحية لتنفيذ هذا الإجراء على العقد.');
    }
}
