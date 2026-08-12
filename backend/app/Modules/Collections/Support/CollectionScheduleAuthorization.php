<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Models\User;
use App\Modules\Collections\Http\Exceptions\RoleNotAuthorizedException;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;

final class CollectionScheduleAuthorization
{
    /** @var array<int, string> */
    private const DRAFT_ROLES = [
        User::ROLE_SALES,
        User::ROLE_ACCOUNTANT,
    ];

    /** @var array<int, string> */
    private const FINANCIAL_COMMAND_ROLES = [
        User::ROLE_ACCOUNTANT,
    ];

    public function assertCanSaveDraft(User $user): void
    {
        if (! $this->canSaveDraft($user)) {
            throw new RoleNotAuthorizedException;
        }
    }

    public function assertCanFinalize(User $user): void
    {
        if (! $this->canFinalize($user)) {
            throw new RoleNotAuthorizedException;
        }
    }

    public function assertCanAmend(User $user): void
    {
        if (! $this->canAmend($user)) {
            throw new RoleNotAuthorizedException;
        }
    }

    public function canSaveDraft(User $user): bool
    {
        return TenantAdministratorAuthority::allows($user)
            || in_array($user->role, self::DRAFT_ROLES, true);
    }

    public function canFinalize(User $user): bool
    {
        return TenantAdministratorAuthority::allows($user)
            || in_array($user->role, self::FINANCIAL_COMMAND_ROLES, true);
    }

    public function canAmend(User $user): bool
    {
        return TenantAdministratorAuthority::allows($user)
            || in_array($user->role, self::FINANCIAL_COMMAND_ROLES, true);
    }
}
