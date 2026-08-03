<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadWonCannotBeArchivedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A won Lead cannot be archived in CRM Leads v1.');
    }
}
