<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum ConversionMode: string
{
    case Created = 'created';
    case Linked = 'linked';
    case LinkedAndPromoted = 'linked_and_promoted';
}
