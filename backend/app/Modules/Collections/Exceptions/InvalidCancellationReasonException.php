<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class InvalidCancellationReasonException extends RuntimeException
{
    public function __construct(string $message = 'Cancellation reason is required and must not exceed 500 characters when cancelling.')
    {
        parent::__construct($message);
    }
}
