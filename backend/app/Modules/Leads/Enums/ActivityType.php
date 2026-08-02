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
}
