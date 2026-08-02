<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Exceptions\LeadNotArchivedException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAssigneeResolver;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RestoreLeadAction
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

            if (! $lead->isArchived()) {
                throw new LeadNotArchivedException();
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

            $previousArchiveReason = $lead->archive_reason?->value;
            $previousArchiveReasonDetail = $lead->archive_reason_detail;
            $now = CarbonImmutable::now();
            $lead->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
                'archive_reason_detail' => null,
                'restored_by' => $actor->id,
                'restored_at' => $now,
                'assigned_to' => $newAssigneeId,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::Restore,
                payload: [
                    'stage' => $lead->stage->value,
                    'previous_archive_reason' => $previousArchiveReason,
                    'previous_archive_reason_detail' => $previousArchiveReasonDetail,
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
                        'reason' => 'assignee_no_longer_eligible_on_restore',
                    ],
                    actorId: $actor->id,
                    occurredAt: $now,
                );
            }

            return $lead->refresh();
        }, 3);
    }
}
