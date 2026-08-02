<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;

final class LeadResponseAssembler
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lead(Lead $lead, User $actor): array
    {
        $lead->loadMissing([
            'project:id,name,archived_at',
            'unit:id,project_id,unit_number,status,archived_at',
            'assignee:id,name,role,status',
            'assignee.tenantMemberships',
            'customer:id,name,status,archived_at',
        ]);

        $assignee = $lead->assignee;
        $assigneeMembership = $assignee?->tenantMemberships
            ->firstWhere('tenant_id', $lead->tenant_id);

        return [
            'id' => (string) $lead->id,
            'tenant_id' => (string) $lead->tenant_id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'source' => $lead->source->value,
            'source_detail' => $lead->source_detail,
            'project_id' => $lead->project_id,
            'unit_id' => $lead->unit_id,
            'stage' => $lead->stage->value,
            'assigned_to' => $assignee === null
                ? null
                : [
                    'id' => (int) $assignee->id,
                    'name' => $assignee->name,
                    'role' => $assignee->role,
                    'account_status' => $assignee->status,
                    'membership_status' => $assigneeMembership?->status,
                    'is_eligible' => $assignee->status === User::STATUS_ACTIVE
                        && $assigneeMembership?->status === TenantUser::STATUS_ACTIVE
                        && in_array($assignee->role, [
                            User::ROLE_ADMINISTRATOR,
                            User::ROLE_SALES,
                            User::ROLE_EMPLOYEE,
                        ], true),
                ],
            'next_follow_up_at' => $lead->next_follow_up_at?->toISOString(),
            'lost_reason' => $lead->lost_reason?->value,
            'lost_reason_detail' => $lead->lost_reason_detail,
            'lost_at' => $lead->lost_at?->toISOString(),
            'lost_by' => $lead->lost_by,
            'customer_id' => $lead->customer_id,
            'converted_at' => $lead->converted_at?->toISOString(),
            'converted_by' => $lead->converted_by,
            'conversion_mode' => $lead->conversion_mode?->value,
            'archived_at' => $lead->archived_at?->toISOString(),
            'archived_by' => $lead->archived_by,
            'archive_reason' => $lead->archive_reason?->value,
            'archive_reason_detail' => $lead->archive_reason_detail,
            'restored_by' => $lead->restored_by,
            'restored_at' => $lead->restored_at?->toISOString(),
            'created_by' => $lead->created_by,
            'updated_by' => $lead->updated_by,
            'created_at' => $lead->created_at?->toISOString(),
            'updated_at' => $lead->updated_at?->toISOString(),
            'project' => $lead->project === null
                ? null
                : [
                    'id' => (string) $lead->project->id,
                    'name' => $lead->project->name,
                    'archived_at' => $lead->project->archived_at?->toISOString(),
                ],
            'unit' => $lead->unit === null
                ? null
                : [
                    'id' => (string) $lead->unit->id,
                    'project_id' => (string) $lead->unit->project_id,
                    'unit_number' => $lead->unit->unit_number,
                    'status' => $lead->unit->status->value,
                    'archived_at' => $lead->unit->archived_at?->toISOString(),
                ],
            'customer' => $lead->customer === null
                ? null
                : [
                    'id' => (string) $lead->customer->id,
                    'name' => $lead->customer->name,
                    'status' => $lead->customer->status->value,
                    'archived_at' => $lead->customer->archived_at?->toISOString(),
                ],
            'allowed_actions' => $this->authorization->allowedActions($lead, $actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(LeadActivity $activity): array
    {
        $activity->loadMissing('creator:id,name');

        return [
            'id' => (string) $activity->id,
            'lead_id' => (string) $activity->lead_id,
            'type' => $activity->type->value,
            'body' => $activity->body,
            'payload' => $activity->payload,
            'occurred_at' => $activity->occurred_at?->toISOString(),
            'created_by' => $activity->creator === null
                ? null
                : [
                    'id' => (int) $activity->creator->id,
                    'name' => $activity->creator->name,
                ],
            'created_at' => $activity->created_at?->toISOString(),
        ];
    }
}
