<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadProjectIsArchivedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An archived Project cannot be selected for a Lead.');
    }
}
