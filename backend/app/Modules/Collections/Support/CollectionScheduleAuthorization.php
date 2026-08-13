<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Models\User;
use App\Modules\Collections\Http\Exceptions\RoleNotAuthorizedException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;

final class CollectionScheduleAuthorization
{
    /** @var array<int, string> */
    private const FINANCIAL_COMMAND_ROLES = [
        User::ROLE_ACCOUNTANT,
    ];

    public function assertCanSaveDraft(User $user, Contract $contract): void
    {
        if (! $this->canSaveDraft($user, $contract)) {
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

    public function canSaveDraft(User $user, Contract $contract): bool
    {
        if (TenantAdministratorAuthority::allows($user) || $user->role === User::ROLE_ACCOUNTANT) {
            return true;
        }

        return $user->role === User::ROLE_SALES
            && (int) $contract->reservation?->created_by === (int) $user->id;
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
