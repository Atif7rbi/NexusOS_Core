<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Exceptions;

use RuntimeException;

final class CollectionScheduleIntegrityViolationException extends RuntimeException
{
    public function __construct(string $message = 'The Collection schedule contains an invalid active lifecycle mixture.')
    {
        parent::__construct($message);
    }
}
