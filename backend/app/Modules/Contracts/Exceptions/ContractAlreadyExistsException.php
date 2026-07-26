<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A contract already exists for this reservation.'
        );
    }
}
