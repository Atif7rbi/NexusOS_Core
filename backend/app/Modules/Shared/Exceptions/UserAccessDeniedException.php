<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

final class UserAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $status,
    ) {
        parent::__construct('The User is not active.');
    }
}
