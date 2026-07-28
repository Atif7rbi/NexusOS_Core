<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class NonDecreasingDueDateViolationException extends RuntimeException
{
    public function __construct(string $message = 'Due dates must be non-decreasing when ordered by sequence.')
    {
        parent::__construct($message);
    }
}
