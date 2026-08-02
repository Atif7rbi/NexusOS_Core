<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotLostException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAssigneeResolver;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReopenLeadAction
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadAssigneeResolver $assignees,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
    ): Lead {
        return DB::transaction(function () use ($tenantId, $leadId, $actor): Lead {
            $lead = $this->leads->lockForAdministrator($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }

            if ($lead->stage !== LeadStage::Lost) {
                throw new LeadNotLostException();
            }

            $oldAssigneeId = $lead->assigned_to === null
                ? null
                : (int) $lead->assigned_to;
            $newAssigneeId = $oldAssigneeId;

            if (
                $oldAssigneeId !== null
                && ! $this->assignees->isEligible($tenantId, $oldAssigneeId)
            ) {
                $newAssigneeId = null;
            }

            $now = CarbonImmutable::now();
            $lead->forceFill([
                'stage' => LeadStage::New,
                'lost_reason' => null,
                'lost_reason_detail' => null,
                'lost_at' => null,
                'lost_by' => null,
                'assigned_to' => $newAssigneeId,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::StageChange,
                payload: [
                    'from_stage' => LeadStage::Lost->value,
                    'to_stage' => LeadStage::New->value,
                    'reason' => 'reopen',
                ],
                actorId: $actor->id,
                occurredAt: $now,
            );

            if ($oldAssigneeId !== $newAssigneeId) {
                $this->activities->automatic(
                    lead: $lead,
                    type: ActivityType::Assignment,
                    payload: [
                        'from_user_id' => $oldAssigneeId,
                        'to_user_id' => null,
                        'reason' => 'assignee_no_longer_eligible_on_reopen',
                    ],
                    actorId: $actor->id,
                    occurredAt: $now,
                );
            }

            return $lead->refresh();
        }, 3);
    }
}
