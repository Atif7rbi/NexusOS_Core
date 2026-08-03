<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadNotInOpenStageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lead must be in an open stage for this action.');
    }
}
