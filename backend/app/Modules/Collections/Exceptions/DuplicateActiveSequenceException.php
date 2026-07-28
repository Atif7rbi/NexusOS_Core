<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class DuplicateActiveSequenceException extends RuntimeException
{
    public function __construct(string $message = 'Sequence must be unique among active Collections for the Contract.')
    {
        parent::__construct($message);
    }
}
