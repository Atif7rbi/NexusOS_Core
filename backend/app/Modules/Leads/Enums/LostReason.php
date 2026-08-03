<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum LostReason: string
{
    case PriceTooHigh = 'price_too_high';
    case NoSuitableUnit = 'no_suitable_unit';
    case FinancingUnavailable = 'financing_unavailable';
    case Competitor = 'competitor';
    case NotReady = 'not_ready';
    case Unresponsive = 'unresponsive';
    case Duplicate = 'duplicate';
    case NotQualified = 'not_qualified';
    case Other = 'other';
}
