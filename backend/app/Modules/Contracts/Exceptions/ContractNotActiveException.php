<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractNotActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cannot complete a contract that is not active.'
        );
    }
}
