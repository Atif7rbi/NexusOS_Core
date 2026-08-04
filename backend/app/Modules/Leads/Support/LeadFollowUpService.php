<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\NextActionType;
use App\Modules\Leads\Exceptions\LeadAlreadyLostException;
use App\Modules\Leads\Exceptions\LeadAlreadyWonException;
use App\Modules\Leads\Exceptions\LeadFollowUpConflictException;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LeadFollowUpService
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadAuthorization $authorization,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function schedule(string $tenantId, string $leadId, User $actor, CarbonImmutable $followUpAt, NextActionType $actionType, ?string $actionNote): Lead
    {
        return $this->write($tenantId, $leadId, $actor, function (Lead $lead, CarbonImmutable $now) use ($followUpAt, $actionType, $actionNote): void {
            if ($lead->next_follow_up_at !== null) {
                throw new LeadFollowUpConflictException('A follow-up is already scheduled for this Lead.');
            }

            $this->setFollowUp($lead, $followUpAt, $actionType, $actionNote, $now);
            $this->activities->automatic($lead, ActivityType::FollowUpScheduled, [
                'previous_follow_up_at' => null,
                'new_follow_up_at' => $followUpAt->toISOString(),
                'previous_action_type' => null,
                'new_action_type' => $actionType->value,
                'previous_action_note' => null,
                'new_action_note' => $actionNote,
            ], $actor->id, $now);
        });
    }

    public function reschedule(string $tenantId, string $leadId, User $actor, CarbonImmutable $followUpAt, NextActionType $actionType, ?string $actionNote): Lead
    {
        return $this->write($tenantId, $leadId, $actor, function (Lead $lead, CarbonImmutable $now) use ($followUpAt, $actionType, $actionNote): void {
            if ($lead->next_follow_up_at === null) {
                throw new LeadFollowUpConflictException('No follow-up is scheduled for this Lead.');
            }

            $previousAt = CarbonImmutable::instance($lead->next_follow_up_at);
            $previousType = $lead->next_action_type;
            $previousNote = $lead->next_action_note;

            if ($previousAt->equalTo($followUpAt) && $previousType === $actionType && $previousNote === $actionNote) {
                throw new LeadFollowUpConflictException('The requested follow-up values are unchanged.');
            }

            $this->setFollowUp($lead, $followUpAt, $actionType, $actionNote, $now);
            $this->activities->automatic($lead, ActivityType::FollowUpRescheduled, [
                'previous_follow_up_at' => $previousAt->toISOString(),
                'new_follow_up_at' => $followUpAt->toISOString(),
                'previous_action_type' => $previousType?->value,
                'new_action_type' => $actionType->value,
                'previous_action_note' => $previousNote,
                'new_action_note' => $actionNote,
            ], $actor->id, $now);
        });
    }

    public function complete(string $tenantId, string $leadId, User $actor, ?string $note): Lead
    {
        return $this->clear($tenantId, $leadId, $actor, ActivityType::FollowUpCompleted, $note);
    }

    public function cancel(string $tenantId, string $leadId, User $actor, ?string $note): Lead
    {
        return $this->clear($tenantId, $leadId, $actor, ActivityType::FollowUpCancelled, $note);
    }

    private function clear(string $tenantId, string $leadId, User $actor, ActivityType $type, ?string $note): Lead
    {
        return $this->write($tenantId, $leadId, $actor, function (Lead $lead, CarbonImmutable $now) use ($type, $note): void {
            if ($lead->next_follow_up_at === null) {
                throw new LeadFollowUpConflictException('No follow-up is scheduled for this Lead.');
            }

            $previousAt = CarbonImmutable::instance($lead->next_follow_up_at);
            $previousType = $lead->next_action_type;
            $previousNote = $lead->next_action_note;

            $lead->forceFill([
                'next_follow_up_at' => null,
                'next_action_type' => null,
                'next_action_note' => null,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic($lead, $type, [
                'previous_follow_up_at' => $previousAt->toISOString(),
                'new_follow_up_at' => null,
                'previous_action_type' => $previousType?->value,
                'new_action_type' => null,
                'previous_action_note' => $previousNote,
                'new_action_note' => null,
                'note' => $note,
            ], $actor->id, $now);
        });
    }

    private function write(string $tenantId, string $leadId, User $actor, callable $mutation): Lead
    {
        return DB::transaction(function () use ($tenantId, $leadId, $actor, $mutation): Lead {
            $lead = $this->leads->lockVisible($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }
            if ($lead->stage->value === 'won') {
                throw new LeadAlreadyWonException();
            }
            if ($lead->stage->value === 'lost') {
                throw new LeadAlreadyLostException();
            }

            $this->authorization->assertCanModify($lead, $actor);
            $mutation($lead, CarbonImmutable::now());

            return $lead->refresh();
        }, 3);
    }

    private function setFollowUp(Lead $lead, CarbonImmutable $followUpAt, NextActionType $actionType, ?string $actionNote, CarbonImmutable $now): void
    {
        $lead->forceFill([
            'next_follow_up_at' => $followUpAt,
            'next_action_type' => $actionType,
            'next_action_note' => $actionNote,
            'updated_at' => $now,
        ])->save();
    }
}
