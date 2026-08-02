<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadAssigneeNotActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected user does not have an active eligible membership in this Tenant.');
    }
}
