<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\User;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use App\Modules\Leads\Models\Lead;

final class LeadLocator
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
    ) {
    }

    public function lockVisible(
        string $tenantId,
        string $leadId,
        User $actor,
    ): Lead {
        $this->authorization->assertActor($actor, $tenantId, true);

        $lead = Lead::query()
            ->forTenant($tenantId)
            ->visibleTo($actor)
            ->whereKey($leadId)
            ->lockForUpdate()
            ->first();

        if ($lead === null) {
            throw new LeadNotFoundException();
        }

        return $lead;
    }

    public function lockForAdministrator(
        string $tenantId,
        string $leadId,
        User $actor,
    ): Lead {
        $this->authorization->assertAdministrator($actor, $tenantId);

        $lead = Lead::query()
            ->forTenant($tenantId)
            ->whereKey($leadId)
            ->lockForUpdate()
            ->first();

        if ($lead === null) {
            throw new LeadNotFoundException();
        }

        return $lead;
    }
}
