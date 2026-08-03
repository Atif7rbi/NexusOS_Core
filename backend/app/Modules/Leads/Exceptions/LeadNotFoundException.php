<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead does not exist or is not available in the active Tenant.');
    }
}
