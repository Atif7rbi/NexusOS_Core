<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadAlreadyLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead is already lost.');
    }
}
