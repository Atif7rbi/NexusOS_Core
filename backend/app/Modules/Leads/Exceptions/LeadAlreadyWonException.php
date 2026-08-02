<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadAlreadyWonException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead is already won and is terminal in CRM Leads v1.');
    }
}
