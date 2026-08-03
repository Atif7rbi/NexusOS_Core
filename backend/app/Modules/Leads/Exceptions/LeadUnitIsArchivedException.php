<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadUnitIsArchivedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An archived Unit cannot be selected for a Lead.');
    }
}
