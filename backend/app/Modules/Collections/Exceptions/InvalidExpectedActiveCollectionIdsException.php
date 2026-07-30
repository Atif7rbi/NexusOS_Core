<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class InvalidExpectedActiveCollectionIdsException extends RuntimeException
{
    public function __construct(string $message = 'Expected active Collection IDs must be a non-empty list of distinct valid ULIDs.')
    {
        parent::__construct($message);
    }
}
