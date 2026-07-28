<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class NoActiveScheduleToAmendException extends RuntimeException
{
    public function __construct(string $message = 'Amendment requires an existing active (scheduled) Collection schedule.')
    {
        parent::__construct($message);
    }
}
