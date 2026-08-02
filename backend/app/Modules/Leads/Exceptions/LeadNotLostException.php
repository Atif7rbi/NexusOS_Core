<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadNotLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead is not in the lost stage.');
    }
}
