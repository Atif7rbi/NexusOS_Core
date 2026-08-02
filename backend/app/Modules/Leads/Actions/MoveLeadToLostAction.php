<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Enums\LostReason;
use App\Modules\Leads\Exceptions\LeadAlreadyLostException;
use App\Modules\Leads\Exceptions\LeadAlreadyWonException;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MoveLeadToLostAction
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
        LostReason $reason,
        ?string $reasonDetail,
    ): Lead {
        return DB::transaction(function () use (
            $tenantId,
            $leadId,
            $actor,
            $reason,
            $reasonDetail,
        ): Lead {
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

            $this->authorization->assertCanModify($lead, $actor);

            $normalizedDetail = $reason === LostReason::Other
                ? trim((string) $reasonDetail)
                : null;

            if ($reason === LostReason::Other && $normalizedDetail === '') {
                throw new LeadValidationException(
                    'Lost reason detail is required when the reason is other.',
                    ['lost_reason_detail' => ['Lost reason detail is required.']],
                );
            }

            $fromStage = $lead->stage;
            $previousFollowUp = $lead->next_follow_up_at?->toISOString();
            $now = CarbonImmutable::now();
            $lead->forceFill([
                'stage' => LeadStage::Lost,
                'lost_reason' => $reason,
                'lost_reason_detail' => $normalizedDetail,
                'lost_at' => $now,
                'lost_by' => $actor->id,
                'next_follow_up_at' => null,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $payload = [
                'from_stage' => $fromStage->value,
                'to_stage' => LeadStage::Lost->value,
                'lost_reason' => $reason->value,
                'lost_reason_detail' => $normalizedDetail,
            ];

            if ($previousFollowUp !== null) {
                $payload['previous_next_follow_up_at'] = $previousFollowUp;
            }

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::StageChange,
                payload: $payload,
                actorId: $actor->id,
                occurredAt: $now,
            );

            return $lead->refresh();
        }, 3);
    }
}
