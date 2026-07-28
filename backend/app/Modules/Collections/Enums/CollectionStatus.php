<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/**
 * The only Collection lifecycle values persisted to the database.
 *
 * Derived states (unpaid, partially_paid, paid, overdue, absent, invalid...)
 * are never stored. See DerivedScheduleState for the schedule-level derived
 * state and the Collection DDL Specification v1, section 12.
 */
enum CollectionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
}
