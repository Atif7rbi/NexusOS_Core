<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class CollectionNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Referenced Collection does not exist or is not active for this Contract.')
    {
        parent::__construct($message);
    }
}
