<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\ArchiveReason;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadAlreadyArchivedException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Exceptions\LeadWonCannotBeArchivedException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ArchiveLeadAction
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadActivityWriter $activities,
    ) {
    }

    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
        ArchiveReason $reason,
        ?string $reasonDetail,
    ): Lead {
        return DB::transaction(function () use (
            $tenantId,
            $leadId,
            $actor,
            $reason,
            $reasonDetail,
        ): Lead {
            $lead = $this->leads->lockForAdministrator($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadAlreadyArchivedException();
            }

            if ($lead->stage === LeadStage::Won) {
                throw new LeadWonCannotBeArchivedException();
            }

            $normalizedDetail = $reason === ArchiveReason::Other
                ? trim((string) $reasonDetail)
                : null;

            if ($reason === ArchiveReason::Other && $normalizedDetail === '') {
                throw new LeadValidationException(
                    'Archive reason detail is required when the reason is other.',
                    ['archive_reason_detail' => ['Archive reason detail is required.']],
                );
            }

            $now = CarbonImmutable::now();

            $lead->forceFill([
                'archived_at' => $now,
                'archived_by' => $actor->id,
                'archive_reason' => $reason,
                'archive_reason_detail' => $normalizedDetail,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->activities->automatic(
                lead: $lead,
                type: ActivityType::Archive,
                payload: [
                    'stage' => $lead->stage->value,
                    'archive_reason' => $reason->value,
                    'archive_reason_detail' => $normalizedDetail,
                ],
                actorId: $actor->id,
                occurredAt: $now,
            );

            return $lead->refresh();
        }, 3);
    }
}
