<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ExcessDecimalPrecisionException extends RuntimeException
{
    public function __construct(string $message = 'Collection amount must not have more than two decimal places.')
    {
        parent::__construct($message);
    }
}
