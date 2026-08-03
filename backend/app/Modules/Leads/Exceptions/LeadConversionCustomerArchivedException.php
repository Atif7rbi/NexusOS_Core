<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

/** Thrown when the matched Customer is archived and cannot be linked. */
final class LeadConversionCustomerArchivedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('لا يمكن الربط بعميل مؤرشف. يرجى استعادة سجل العميل أولًا.');
    }
}
