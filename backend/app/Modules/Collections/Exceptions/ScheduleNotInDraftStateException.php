<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use RuntimeException;

final class ScheduleNotInDraftStateException extends RuntimeException
{
    public function __construct(string $message = 'Finalization requires the Collection schedule to be in the draft derived state.')
    {
        parent::__construct($message);
    }
}
