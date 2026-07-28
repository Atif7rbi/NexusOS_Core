<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class BlankCollectionTitleException extends RuntimeException
{
    public function __construct(string $message = 'Collection title must not be blank.')
    {
        parent::__construct($message);
    }
}
