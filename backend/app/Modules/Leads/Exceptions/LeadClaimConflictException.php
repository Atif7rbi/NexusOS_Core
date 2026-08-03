<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadClaimConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead could not be claimed because its assignment or lifecycle state changed.');
    }
}
