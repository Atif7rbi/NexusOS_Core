<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ContractNotEligibleForFinalizationException extends RuntimeException
{
    public function __construct(string $message = 'Collection schedule finalization is only allowed while the Contract is draft.')
    {
        parent::__construct($message);
    }
}
