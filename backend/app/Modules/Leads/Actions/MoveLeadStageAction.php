<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadAlreadyLostException;
use App\Modules\Leads\Exceptions\LeadAlreadyWonException;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadStageTransitionNotAllowedException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MoveLeadStageAction
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadAuthorization $authorization,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
        LeadStage $targetStage,
    ): Lead {
        return DB::transaction(function () use ($tenantId, $leadId, $actor, $targetStage): Lead {
            $lead = $this->leads->lockVisible($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }

            if ($lead->stage === LeadStage::Won) {
                throw new LeadAlreadyWonException();
            }

            if ($lead->stage === LeadStage::Lost) {
                throw new LeadAlreadyLostException();
            }

            if (! $targetStage->isOpen() || $targetStage === $lead->stage) {
                throw new LeadStageTransitionNotAllowedException();
            }

            $this->authorization->assertCanModify($lead, $actor);

            if (
                $targetStage->rank() < $lead->stage->rank()
                && ! $this->authorization->isAdministrator($actor)
            ) {
                throw new LeadStageTransitionNotAllowedException();
            }

            $fromStage = $lead->stage;
            $now = CarbonImmutable::now();
            $lead->forceFill([
                'stage' => $targetStage,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::StageChange,
                payload: [
                    'from_stage' => $fromStage->value,
                    'to_stage' => $targetStage->value,
                ],
                actorId: $actor->id,
                occurredAt: $now,
            );

            return $lead->refresh();
        }, 3);
    }
}
