<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class CollectionHasEffectiveReceivableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A Collection with an effective Receivable must be corrected by cancelling the Receivable first.');
    }
}
