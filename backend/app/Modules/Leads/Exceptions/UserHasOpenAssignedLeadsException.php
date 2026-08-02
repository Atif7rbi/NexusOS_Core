<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class UserHasOpenAssignedLeadsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Tenant membership has open assigned Leads and cannot be paused.');
    }
}
