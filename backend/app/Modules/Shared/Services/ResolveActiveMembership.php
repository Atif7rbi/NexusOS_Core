<?php

namespace App\Modules\Shared\Services;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Shared\Exceptions\TenantAccessDeniedException;
use App\Modules\Shared\Exceptions\TenantMembershipInvitedException;
use App\Modules\Shared\Exceptions\TenantMembershipMissingException;
use App\Modules\Shared\Exceptions\TenantMembershipPausedException;
use App\Modules\Shared\Exceptions\TenantMembershipRemovedException;
use App\Modules\Shared\Exceptions\TenantMembershipSuspendedException;
use App\Modules\Shared\Exceptions\UserAccessDeniedException;

class ResolveActiveMembership
{
    public function handle(User $user): TenantUser
    {
        if (! $user->isActive()) {
            throw new UserAccessDeniedException($user->status);
        }

        $membership = TenantUser::query()
            ->with('tenant')
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            throw new TenantMembershipMissingException();
        }

        match ($membership->status) {
            TenantUser::STATUS_ACTIVE => null,
            TenantUser::STATUS_INVITED =>
                throw new TenantMembershipInvitedException(),
            TenantUser::STATUS_PAUSED =>
                throw new TenantMembershipPausedException(),
            TenantUser::STATUS_SUSPENDED =>
                throw new TenantMembershipSuspendedException(),
            TenantUser::STATUS_REMOVED =>
                throw new TenantMembershipRemovedException(),
            default =>
                throw new TenantMembershipMissingException(),
        };

        if ($membership->tenant === null) {
            throw new TenantMembershipMissingException();
        }

        if (! $membership->tenant->isActive()) {
            throw new TenantAccessDeniedException(
                $membership->tenant->status,
            );
        }

        return $membership;
    }
}
