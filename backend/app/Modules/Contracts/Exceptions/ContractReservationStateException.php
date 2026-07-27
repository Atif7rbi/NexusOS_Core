<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractReservationStateException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The linked reservation is not in the required status for this action.'
        );
    }
}
