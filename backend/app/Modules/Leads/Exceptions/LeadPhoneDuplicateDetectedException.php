<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exceptions;

use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final class LeadPhoneDuplicateDetectedException extends RuntimeException
{
    /**
     * @param Collection<int, Lead> $matches
     */
    public function __construct(
        public readonly Collection $matches,
    ) {
        parent::__construct('Visible Leads with the same canonical phone number already exist.');
    }
}
