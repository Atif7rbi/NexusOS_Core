<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadFollowUpService;

final class CompleteLeadFollowUpAction
{
    public function __construct(private readonly LeadFollowUpService $service)
    {
    }

    public function execute(string $tenantId, string $leadId, User $actor, ?string $note): Lead
    {
        return $this->service->complete($tenantId, $leadId, $actor, $note);
    }
}
