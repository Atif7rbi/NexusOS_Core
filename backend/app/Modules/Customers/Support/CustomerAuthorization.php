<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Auth\Access\AuthorizationException;

final class CustomerAuthorization
{
    public function assertCanCreate(User $actor): void
    {
        if (TenantAdministratorAuthority::allows($actor) || in_array($actor->role, [
            User::ROLE_SALES,
            User::ROLE_PROJECT_MANAGER,
        ], true)) {
            return;
        }

        $this->deny();
    }

    public function assertCanUpdateOrArchive(User $actor, Customer $customer): void
    {
        if (TenantAdministratorAuthority::allows($actor)) {
            return;
        }

        if ($actor->role === User::ROLE_SALES && (int) $customer->created_by === (int) $actor->id) {
            return;
        }

        $this->deny();
    }

    public function assertCanRestore(User $actor): void
    {
        if (! TenantAdministratorAuthority::allows($actor)) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw new AuthorizationException('ليس لديك صلاحية لتنفيذ هذا الإجراء على العميل.');
    }
}
