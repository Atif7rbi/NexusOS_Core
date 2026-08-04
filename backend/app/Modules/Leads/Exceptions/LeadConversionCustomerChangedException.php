<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

/**
 * Thrown when the Customer's state has changed between the user's preview
 * and the conversion attempt (race condition guard).
 */
final class LeadConversionCustomerChangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('تغيرت حالة سجل العميل منذ معاينتك الأخيرة. يرجى مراجعة البيانات والمحاولة مجددًا.');
    }
}
