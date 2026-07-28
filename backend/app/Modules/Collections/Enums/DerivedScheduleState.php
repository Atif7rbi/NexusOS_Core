<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/**
 * The Collection schedule state for a Contract, derived at read time from
 * the set of active (non-cancelled) Collections. Never persisted.
 *
 * See Collection DDL Specification v1, section 28.
 */
enum DerivedScheduleState: string
{
    case Absent = 'absent';
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Invalid = 'invalid';
}
