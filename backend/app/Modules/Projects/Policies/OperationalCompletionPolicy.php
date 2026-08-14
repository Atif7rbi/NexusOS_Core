<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\ProjectNotOperationallyCompleteException;
use App\Modules\Projects\Models\Project;
use Carbon\CarbonInterface;

final class OperationalCompletionPolicy
{
    public function assert(
        Project $project,
        bool $hasActiveReservation,
        bool $hasLiveContract,
        CarbonInterface $businessDate,
    ): void
    {
        $startsOn = $project->actual_start_date?->toDateString();
        $endsOn = $project->actual_end_date?->toDateString();
        $businessDateValue = $businessDate->toDateString();

        if (
            $project->status !== ProjectStatus::Active
            || $project->isArchived()
            || $startsOn === null
            || $endsOn === null
            || $endsOn < $startsOn
            || $endsOn > $businessDateValue
            || $hasActiveReservation
            || $hasLiveContract
        ) {
            throw new ProjectNotOperationallyCompleteException();
        }
    }
}
