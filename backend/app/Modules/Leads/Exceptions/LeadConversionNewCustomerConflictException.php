<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use RuntimeException;

/**
 * Thrown when the user requested to create a new Customer but a Customer
 * with the same phone appeared between the preview check and the commit.
 * The frontend must re-present the conflict for explicit acknowledgement.
 */
final class LeadConversionNewCustomerConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $conflictingCustomerId,
        public readonly string $conflictingCustomerStatus,
    ) {
        parent::__construct('ظهر سجل عميل بنفس رقم الهاتف أثناء إتمام التحويل. يرجى مراجعة التطابق والمحاولة مجددًا.');
    }
}
