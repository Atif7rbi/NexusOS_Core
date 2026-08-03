<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\TenantUser;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\UserHasOpenAssignedLeadsException;
use App\Modules\Leads\Models\Lead;

final class EnsureTenantUserCanBePaused
{
    public function handle(TenantUser $membership): void
    {
        $hasOpenAssignedLeads = Lead::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('assigned_to', $membership->user_id)
            ->whereIn('stage', LeadStage::openValues())
            ->whereNull('archived_at')
            ->exists();

        if ($hasOpenAssignedLeads) {
            throw new UserHasOpenAssignedLeadsException();
        }
    }
}
