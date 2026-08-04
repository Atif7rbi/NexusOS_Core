<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\NextActionType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadFollowUpService;
use Carbon\CarbonImmutable;

final class RescheduleLeadFollowUpAction
{
    public function __construct(private readonly LeadFollowUpService $service)
    {
    }

    public function execute(string $tenantId, string $leadId, User $actor, CarbonImmutable $followUpAt, NextActionType $actionType, ?string $actionNote): Lead
    {
        return $this->service->reschedule($tenantId, $leadId, $actor, $followUpAt, $actionType, $actionNote);
    }
}
