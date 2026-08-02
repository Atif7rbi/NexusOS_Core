<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAssigneeResolver;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AssignLeadAction
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadAuthorization $authorization,
        private readonly LeadAssigneeResolver $assignees,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
        ?int $assignedTo,
    ): Lead {
        return DB::transaction(function () use ($tenantId, $leadId, $actor, $assignedTo): Lead {
            $lead = $this->leads->lockForAdministrator($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }

            if (! $lead->isOpen()) {
                throw new LeadNotInOpenStageException();
            }

            $newAssigneeId = $assignedTo === null
                ? null
                : $this->assignees->resolve($tenantId, $assignedTo)->id;
            $oldAssigneeId = $lead->assigned_to === null
                ? null
                : (int) $lead->assigned_to;

            if ($oldAssigneeId === $newAssigneeId) {
                return $lead;
            }

            $now = CarbonImmutable::now();
            $lead->forceFill([
                'assigned_to' => $newAssigneeId,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::Assignment,
                payload: [
                    'from_user_id' => $oldAssigneeId,
                    'to_user_id' => $newAssigneeId,
                    'reason' => 'administrator_assignment',
                ],
                actorId: $actor->id,
                occurredAt: $now,
            );

            return $lead->refresh();
        }, 3);
    }
}
