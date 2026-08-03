<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Exceptions\LeadProjectIsArchivedException;
use App\Modules\Leads\Exceptions\LeadUnitIsArchivedException;
use App\Modules\Leads\Exceptions\LeadUnitProjectMismatchException;
use App\Modules\Projects\Models\Project;
use App\Modules\Units\Models\Unit;

final class LeadInterestValidator
{
    public function validate(
        string $tenantId,
        ?string $projectId,
        ?string $unitId,
    ): void {
        if ($projectId === null && $unitId === null) {
            return;
        }

        if ($projectId === null) {
            throw new LeadUnitProjectMismatchException();
        }

        $project = Project::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($projectId)
            ->first();

        if ($project === null) {
            throw new LeadUnitProjectMismatchException();
        }

        if ($project->isArchived()) {
            throw new LeadProjectIsArchivedException();
        }

        if ($unitId === null) {
            return;
        }

        $unit = Unit::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($unitId)
            ->first();

        if ($unit === null || (string) $unit->project_id !== $projectId) {
            throw new LeadUnitProjectMismatchException();
        }

        if ($unit->isArchived()) {
            throw new LeadUnitIsArchivedException();
        }
    }
}
