<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\InvalidProjectStatusTransitionException;
use App\Modules\Projects\Exceptions\ProjectHasOutstandingCommitmentsException;
use App\Modules\Projects\Models\Project;

final class OutstandingCommitmentsPolicy
{
    public function assert(
        Project $project,
        bool $hasActiveReservation,
        bool $hasLiveContract,
        bool $hasSoldUnit,
    ): void {
        if (
            $project->status !== ProjectStatus::Active
            || $project->isArchived()
        ) {
            throw new InvalidProjectStatusTransitionException();
        }

        if ($hasActiveReservation || $hasLiveContract || $hasSoldUnit) {
            throw new ProjectHasOutstandingCommitmentsException();
        }
    }
}
