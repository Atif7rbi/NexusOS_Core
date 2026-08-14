<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use DomainException;

final class ReservationUnitStateException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'حالة الوحدة لا تتطابق مع حالة الحجز النشط.'
        );
    }
}
