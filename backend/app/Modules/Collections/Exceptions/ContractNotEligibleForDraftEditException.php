<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ContractNotEligibleForDraftEditException extends RuntimeException
{
    public function __construct(string $message = 'Draft Collection edits are only allowed while the Contract is draft and its schedule state is absent or draft.')
    {
        parent::__construct($message);
    }
}
