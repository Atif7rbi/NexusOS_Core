<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Support\LeadActivityWriter;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AddLeadNoteAction
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
        string $body,
    ): LeadActivity {
        return DB::transaction(function () use ($tenantId, $leadId, $actor, $body): LeadActivity {
            $lead = $this->leads->lockVisible($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }

            if (! $lead->isOpen()) {
                throw new LeadNotInOpenStageException();
            }

            $this->authorization->assertCanModify($lead, $actor);

            $normalizedBody = trim($body);

            if ($normalizedBody === '') {
                throw new LeadValidationException(
                    'Lead note body is required.',
                    ['body' => ['Lead note body is required.']],
                );
            }

            $now = CarbonImmutable::now();
            $lead->forceFill([
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            return $this->activities->note(
                lead: $lead,
                body: $normalizedBody,
                actorId: $actor->id,
                occurredAt: $now,
            );
        }, 3);
    }
}
