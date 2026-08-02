<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadClaimConflictException;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ClaimLeadAction
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
    ): Lead {
        $this->authorization->assertActor($actor, $tenantId);

        return DB::transaction(function () use ($tenantId, $leadId, $actor): Lead {
            $this->authorization->assertActor($actor, $tenantId, true);
            $visibleLead = Lead::query()
                ->forTenant($tenantId)
                ->visibleTo($actor)
                ->activeOnly()
                ->whereKey($leadId)
                ->first();

            if ($visibleLead === null) {
                throw new LeadNotFoundException();
            }

            if (! $visibleLead->isOpen()) {
                throw new LeadNotInOpenStageException();
            }

            if ($visibleLead->assigned_to !== null) {
                throw new LeadClaimConflictException();
            }

            $now = CarbonImmutable::now();
            $updated = Lead::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($leadId)
                ->whereNull('assigned_to')
                ->whereNull('archived_at')
                ->whereIn('stage', LeadStage::openValues())
                ->update([
                    'assigned_to' => $actor->id,
                    'updated_by' => $actor->id,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new LeadClaimConflictException();
            }

            $lead = Lead::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($leadId)
                ->firstOrFail();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::Assignment,
                payload: [
                    'from_user_id' => null,
                    'to_user_id' => $actor->id,
                    'reason' => 'claim',
                ],
                actorId: $actor->id,
                occurredAt: $now,
            );

            return $lead;
        }, 3);
    }
}
