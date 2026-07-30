<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class CollectionScheduleChangedSinceLoadedException extends RuntimeException
{
    public function __construct(string $message = 'The active Collection schedule has changed since it was loaded.')
    {
        parent::__construct($message);
    }
}
