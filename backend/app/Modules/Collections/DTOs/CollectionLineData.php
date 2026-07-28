<?php

declare(strict_types=1);

namespace App\Modules\Collections\DTOs;

use Carbon\CarbonImmutable;

/**
 * A single line submitted to SaveDraftCollectionScheduleAction or
 * AmendCollectionScheduleAction.
 *
 * `id` is null for a new row. When present, it identifies an existing
 * active Collection to be edited in place (SaveDraft) — it is never used
 * to target a Scheduled row directly, since Scheduled rows are only ever
 * mutated via cancel-and-replace (DDL spec section 26).
 */
final readonly class CollectionLineData
{
    public function __construct(
        public ?string $id,
        public int $sequence,
        public string $title,
        public string $amount,
        public CarbonImmutable $dueDate,
        public ?string $notes = null,
    ) {
    }
}
