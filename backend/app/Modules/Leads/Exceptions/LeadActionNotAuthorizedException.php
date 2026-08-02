<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadActionNotAuthorizedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The authenticated user is not authorized to perform this Lead action.');
    }
}
