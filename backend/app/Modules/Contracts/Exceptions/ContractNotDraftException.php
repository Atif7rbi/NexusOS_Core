<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Exceptions;

use RuntimeException;

final class ContractNotDraftException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Cannot modify or activate a contract that is not in draft status.'
        );
    }
}
