<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class InvalidCollectionSequenceException extends RuntimeException
{
    public function __construct(string $message = 'Collection sequence must be a positive integer.')
    {
        parent::__construct($message);
    }
}
