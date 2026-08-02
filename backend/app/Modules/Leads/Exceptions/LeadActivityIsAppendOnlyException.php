<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use LogicException;

final class LeadActivityIsAppendOnlyException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Lead activities are append-only and cannot be changed or deleted.');
    }
}
