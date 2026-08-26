<?php

declare(strict_types=1);

namespace App\Modules\ReceivableAccounting\Services;

use App\Models\User;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use Illuminate\Support\Facades\DB;

final class ReceivableAccountingIntegration
{
    public const SOURCE_TYPE = 'receivable_recognition';

    public function __construct(private readonly RecognizeReceivableAction $recognize, private readonly BusinessPostingServiceInterface $posting) {}

    public function recognizeAndPost(string $tenantId, User $actor, array $recognition, string $entryDate, string $description, array $orderedLines): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receivable Accounting integration requires a business-owned outer transaction.');
        }
        if (($recognition['currency'] ?? null) !== 'SAR') {
            throw new ReceivablesValidationFailed('Accounting-integrated Receivable recognition requires SAR.');
        }

        $receivableId = $this->recognize->executeInTransaction($tenantId, $actor, $recognition);
        $journal = $this->posting->post(new BusinessPostingRequest(
            $tenantId,
            (int) $actor->id,
            self::SOURCE_TYPE,
            $receivableId,
            'SAR',
            $entryDate,
            $description,
            $orderedLines,
        ));

        return ['receivable_id' => $receivableId, 'journal_entry_id' => $journal->journalEntryId, 'replayed' => $journal->idempotentReplay];
    }
}
