<?php

declare(strict_types=1);

namespace App\Modules\ReceivableAccounting\Services;

use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\Services\BusinessPostingCanonicalResolver;
use App\Modules\ReceivableAccounting\Exceptions\ReceivableAccountingIntegrityFault;
use App\Modules\Receivables\Services\ReceivableRecognitionResolver;
use Illuminate\Support\Facades\DB;

final class ReceivableAccountingRecoveryResolver
{
    public function __construct(private readonly BusinessPostingCanonicalResolver $canonical, private readonly ReceivableRecognitionResolver $recognition) {}

    public function resolve(string $tenantId, string $operationId, int $actorId, string $entryDate, string $description, array $orderedLines, ?array $recognitionFacts = null): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Unknown-commit recovery requires a fresh transaction.');
        }

        return DB::transaction(function () use ($tenantId, $operationId, $actorId, $entryDate, $description, $orderedLines, $recognitionFacts): array {
            $receivable = DB::table('receivables')->where('tenant_id', $tenantId)->where('recognition_operation_id', $operationId)->first();
            if ($receivable === null) {
                $orphan = DB::table('journal_entries as journal')
                    ->leftJoin('receivables as receivable', function ($join): void {
                        $join->on('receivable.tenant_id', '=', 'journal.tenant_id')->on('receivable.id', '=', 'journal.source_id');
                    })
                    ->where('journal.tenant_id', $tenantId)
                    ->where('journal.origin', 'business')
                    ->where('journal.source_type', ReceivableAccountingIntegration::SOURCE_TYPE)
                    ->whereNull('receivable.id')
                    ->exists();
                if ($orphan) {
                    throw new ReceivableAccountingIntegrityFault('An orphan Receivable Accounting Journal exists.');
                }

                return ['status' => 'retryable', 'receivable_id' => null, 'journal_entry_id' => null];
            }
            if ($recognitionFacts !== null) {
                $this->recognition->resolve($tenantId, $operationId, $recognitionFacts);
            }

            $journal = DB::table('journal_entries')->where('tenant_id', $tenantId)
                ->where('origin', 'business')->where('source_type', ReceivableAccountingIntegration::SOURCE_TYPE)
                ->where('source_id', $receivable->id)->first();
            if ($journal === null) {
                throw new ReceivableAccountingIntegrityFault('Committed Receivable has no atomic Accounting Journal.');
            }
            $result = $this->canonical->resolve(new BusinessPostingRequest(
                $tenantId,
                $actorId,
                ReceivableAccountingIntegration::SOURCE_TYPE,
                (string) $receivable->id,
                'SAR',
                $entryDate,
                $description,
                $orderedLines,
            ));

            return ['status' => 'committed', 'receivable_id' => (string) $receivable->id, 'journal_entry_id' => $result->journalEntryId];
        });
    }
}
