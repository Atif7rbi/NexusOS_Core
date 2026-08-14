<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

final class TenantAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $status,
    ) {
        parent::__construct('The Tenant is not active.');
    }
}
