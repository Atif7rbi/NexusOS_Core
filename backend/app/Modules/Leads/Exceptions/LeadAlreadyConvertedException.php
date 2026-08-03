<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

/** Thrown when conversion is attempted on a Lead that is already won. */
final class LeadAlreadyConvertedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('هذا العميل المحتمل تم تحويله مسبقًا.');
    }
}
