<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ScheduleTotalMismatchException extends RuntimeException
{
    public function __construct(string $message = 'The sum of active Collection amounts must equal the Contract total amount.')
    {
        parent::__construct($message);
    }
}
