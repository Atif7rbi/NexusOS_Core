<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Exceptions\LeadAssigneeNotActiveException;
use App\Modules\Leads\Exceptions\LeadAssigneeRoleNotEligibleException;

final class LeadAssigneeResolver
{
    /**
     * @var list<string>
     */
    private const ELIGIBLE_ROLES = [
        User::ROLE_PROJECT_MANAGER,
        User::ROLE_SALES,
    ];

    public function resolve(string $tenantId, int $userId): User
    {
        $membership = TenantUser::query()
            ->with('user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (
            $membership === null
            || $membership->status !== TenantUser::STATUS_ACTIVE
            || $membership->user === null
            || $membership->user->status !== User::STATUS_ACTIVE
        ) {
            throw new LeadAssigneeNotActiveException();
        }

        if (! in_array($membership->user->role, self::ELIGIBLE_ROLES, true)) {
            throw new LeadAssigneeRoleNotEligibleException();
        }

        return $membership->user;
    }

    public function isEligible(string $tenantId, int $userId): bool
    {
        try {
            $this->resolve($tenantId, $userId);

            return true;
        } catch (LeadAssigneeNotActiveException|LeadAssigneeRoleNotEligibleException) {
            return false;
        }
    }
}
