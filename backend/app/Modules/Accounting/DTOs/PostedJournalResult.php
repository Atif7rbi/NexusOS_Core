<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

final readonly class PostedJournalResult
{
    public function __construct(public string $journalEntryId, public string $journalNumber, public bool $idempotentReplay = false) {}
}
