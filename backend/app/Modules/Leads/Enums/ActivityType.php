<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum ActivityType: string
{
    case Note = 'note';
    case StageChange = 'stage_change';
    case Assignment = 'assignment';
    case Archive = 'archive';
    case Restore = 'restore';
    case FollowUpScheduled = 'follow_up_scheduled';
    case FollowUpRescheduled = 'follow_up_rescheduled';
    case FollowUpCompleted = 'follow_up_completed';
    case FollowUpCancelled = 'follow_up_cancelled';
}
