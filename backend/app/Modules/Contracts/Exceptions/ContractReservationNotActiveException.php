<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractReservationNotActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cannot create contract from inactive reservation.'
        );
    }
}
