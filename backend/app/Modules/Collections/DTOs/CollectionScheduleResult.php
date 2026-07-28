<?php

declare(strict_types=1);

namespace App\Modules\Collections\DTOs;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Models\Collection;

/**
 * Result DTO returned by SaveDraftCollectionScheduleAction,
 * FinalizeCollectionScheduleAction, and AmendCollectionScheduleAction.
 *
 * Matches the architecture's "Domain Service returns a Result DTO"
 * convention (MASTER_CONTEXT, 07_Backend_Architecture).
 */
final readonly class CollectionScheduleResult
{
    /**
     * @param  array<int, Collection>  $collections  The active (non-cancelled)
     *                                                Collections after the
     *                                                action completed, ordered
     *                                                by sequence.
     */
    public function __construct(
        public array $collections,
        public DerivedScheduleState $derivedState,
    ) {
    }
}
