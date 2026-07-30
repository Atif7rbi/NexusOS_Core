<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Exceptions;

use RuntimeException;

final class RoleNotAuthorizedException extends RuntimeException
{
    public function __construct(string $message = 'The current user role is not authorized for this Collection Schedule command.')
    {
        parent::__construct($message);
    }
}
