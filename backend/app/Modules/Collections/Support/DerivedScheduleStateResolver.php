<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Models\Collection;

/**
 * Derives the schedule state for a Contract from its Collections.
 *
 * This state is never persisted (DDL spec section 28). It is a pure
 * function of the given rows and takes no database dependency itself —
 * callers are responsible for loading the relevant Collections (typically
 * under a row lock).
 */
final class DerivedScheduleStateResolver
{
    /**
     * @param  array<int, Collection>  $collections  All Collections
     *                                                (active and cancelled)
     *                                                currently known for the
     *                                                Contract.
     */
    public function resolve(array $collections): DerivedScheduleState
    {
        if ($collections === []) {
            return DerivedScheduleState::Absent;
        }

        $active = array_values(array_filter(
            $collections,
            static fn (Collection $collection): bool => $collection->isActive(),
        ));

        if ($active === []) {
            return DerivedScheduleState::Cancelled;
        }

        $statuses = array_unique(array_map(
            static fn (Collection $collection): string => $collection->status->value,
            $active,
        ));

        if ($statuses === [CollectionStatus::Draft->value]) {
            return DerivedScheduleState::Draft;
        }

        if ($statuses === [CollectionStatus::Scheduled->value]) {
            return DerivedScheduleState::Scheduled;
        }

        return DerivedScheduleState::Invalid;
    }

    /**
     * @param  array<int, Collection>  $collections
     * @return array<int, Collection>
     */
    public function activeOnly(array $collections): array
    {
        return array_values(array_filter(
            $collections,
            static fn (Collection $collection): bool => $collection->isActive(),
        ));
    }
}
