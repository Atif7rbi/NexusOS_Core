<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadAssigneeRoleNotEligibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected user role is not eligible for Lead assignment.');
    }
}
