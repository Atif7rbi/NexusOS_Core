<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractCannotBeCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A completed or already cancelled contract cannot be cancelled.'
        );
    }
}
