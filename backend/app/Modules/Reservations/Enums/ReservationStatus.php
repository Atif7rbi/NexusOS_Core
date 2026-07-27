<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
