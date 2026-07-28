<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class InvalidCollectionAmountException extends RuntimeException
{
    public function __construct(string $message = 'Collection amount must be greater than zero.')
    {
        parent::__construct($message);
    }
}
