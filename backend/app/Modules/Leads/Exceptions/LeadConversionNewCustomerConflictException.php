<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

final class LeadConversionNewCustomerConflictException extends RuntimeException
{
    public const SOURCE_PRECHECK = 'precheck';

    public const SOURCE_UNIQUE_VIOLATION = 'unique_violation';

    public function __construct(
        public readonly string $conflictingCustomerId,
        public readonly string $conflictingCustomerStatus,
        public readonly ?string $conflictingCustomerName = null,
        public readonly string $conflictSource = self::SOURCE_PRECHECK,
    ) {
        parent::__construct('ظهر سجل عميل بنفس رقم الهاتف أثناء إتمام التحويل. يرجى مراجعة التطابق والمحاولة مجددًا.');
    }
}
