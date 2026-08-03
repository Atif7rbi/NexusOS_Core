<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum ArchiveReason: string
{
    case Duplicate = 'duplicate';
    case InvalidRecord = 'invalid_record';
    case CreatedByMistake = 'created_by_mistake';
    case Other = 'other';
}
