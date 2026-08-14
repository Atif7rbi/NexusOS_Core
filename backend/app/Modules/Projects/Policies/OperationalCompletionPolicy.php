<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\ProjectNotOperationallyCompleteException;
use App\Modules\Projects\Models\Project;

final class OperationalCompletionPolicy
{
    public function assert(
        Project $project,
        bool $hasActiveReservation,
        bool $hasLiveContract,
    ): void
    {
        $startsAt = $project->actual_start_date;
        $endsAt = $project->actual_end_date;

        if (
            $project->status !== ProjectStatus::Active
            || $project->isArchived()
            || $startsAt === null
            || $endsAt === null
            || $endsAt->lt($startsAt)
            || $endsAt->gt(today())
            || $hasActiveReservation
            || $hasLiveContract
        ) {
            throw new ProjectNotOperationallyCompleteException();
        }
    }
}
