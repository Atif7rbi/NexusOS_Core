<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadStageTransitionNotAllowedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested Lead stage transition is not allowed.');
    }
}
