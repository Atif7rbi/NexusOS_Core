<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\InvalidProjectStatusTransitionException;
use App\Modules\Projects\Exceptions\ProjectHasOperationalFootprintException;
use App\Modules\Projects\Models\Project;

final class OperationalFootprintPolicy
{
    public function assert(
        Project $project,
        bool $hasActiveReservation,
        bool $hasLiveContract,
    ): void
    {
        if (
            $project->status !== ProjectStatus::Active
            || $project->isArchived()
        ) {
            throw new InvalidProjectStatusTransitionException();
        }

        if ($hasActiveReservation || $hasLiveContract) {
            throw new ProjectHasOperationalFootprintException();
        }
    }
}
