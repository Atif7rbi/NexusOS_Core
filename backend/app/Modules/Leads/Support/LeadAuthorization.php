<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadActionNotAuthorizedException;
use App\Modules\Leads\Models\Lead;

final class LeadAuthorization
{
    /**
     * @var list<string>
     */
    private const CRM_ROLES = [
        User::ROLE_ADMINISTRATOR,
        User::ROLE_SALES,
        User::ROLE_EMPLOYEE,
    ];

    public function assertActor(
        User $actor,
        string $tenantId,
        bool $lockMembership = false,
    ): void
    {
        $membershipQuery = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->where('status', TenantUser::STATUS_ACTIVE);

        if ($lockMembership) {
            $membershipQuery->lockForUpdate();
        }

        $membership = $membershipQuery->first();
        $userQuery = User::query()->whereKey($actor->id);

        if ($lockMembership) {
            $userQuery->lockForUpdate();
        }

        $currentUser = $userQuery->first();

        if (
            $membership === null
            || $currentUser === null
            || $currentUser->status !== User::STATUS_ACTIVE
            || ! in_array($currentUser->role, self::CRM_ROLES, true)
        ) {
            throw new LeadActionNotAuthorizedException();
        }

        $actor->setAttribute('role', $currentUser->role);
        $actor->setAttribute('status', $currentUser->status);
    }

    public function assertAdministrator(User $actor, string $tenantId): void
    {
        $this->assertActor($actor, $tenantId, true);

        if (! $this->isAdministrator($actor)) {
            throw new LeadActionNotAuthorizedException();
        }
    }

    public function assertCanModify(Lead $lead, User $actor): void
    {
        $this->assertActor($actor, (string) $lead->tenant_id);

        if ($this->isAdministrator($actor)) {
            return;
        }

        if ((int) $lead->assigned_to !== $actor->id) {
            throw new LeadActionNotAuthorizedException();
        }
    }

    public function isAdministrator(User $actor): bool
    {
        return $actor->role === User::ROLE_ADMINISTRATOR;
    }

    /**
     * @return array<string, bool>
     */
    public function allowedActions(Lead $lead, User $actor): array
    {
        $administrator = $this->isAdministrator($actor);
        $archived = $lead->isArchived();
        $open = $lead->stage instanceof LeadStage && $lead->stage->isOpen();
        $assignedToActor = $lead->assigned_to !== null
            && (int) $lead->assigned_to === $actor->id;
        $unassigned = $lead->assigned_to === null;
        $canOperate = ! $archived
            && $open
            && ($administrator || $assignedToActor);

        return [
            'can_edit' => $canOperate,
            'can_claim' => ! $archived && $open && $unassigned,
            'can_assign' => $administrator && ! $archived && $open,
            'can_move_stage' => $canOperate,
            'can_move_backward' => $administrator && ! $archived && $open,
            'can_move_to_lost' => $canOperate,
            'can_reopen' => $administrator
                && ! $archived
                && $lead->stage === LeadStage::Lost,
            'can_add_note' => $canOperate,
            'can_archive' => $administrator
                && ! $archived
                && $lead->stage !== LeadStage::Won,
            'can_restore' => $administrator && $archived,
            'can_convert' => ! $archived
                && $open
                && ($administrator || $assignedToActor),
        ];
    }
}
