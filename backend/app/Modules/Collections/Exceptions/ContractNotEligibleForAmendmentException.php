<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ContractNotEligibleForAmendmentException extends RuntimeException
{
    public function __construct(string $message = 'Collection schedule amendment is not allowed for the current Contract lifecycle state.')
    {
        parent::__construct($message);
    }
}
