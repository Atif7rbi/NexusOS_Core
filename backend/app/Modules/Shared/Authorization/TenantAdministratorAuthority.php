<?php

declare(strict_types=1);

namespace App\Modules\Shared\Authorization;

use App\Models\User;

/**
 * NexusOS Core has two administrative identities with the same tenant authority.
 * Keep this distinction in one place while the stored roles remain unchanged.
 */
final class TenantAdministratorAuthority
{
    public static function allows(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_SYSTEM_OWNER,
            User::ROLE_ADMINISTRATOR,
        ], true);
    }
}
