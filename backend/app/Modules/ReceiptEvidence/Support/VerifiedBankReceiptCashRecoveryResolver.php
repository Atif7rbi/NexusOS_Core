<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Support;

use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Services\BusinessPostingCanonicalResolver;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use Illuminate\Support\Facades\DB;

final class VerifiedBankReceiptCashRecoveryResolver
{
    public function __construct(
        private readonly BusinessPostingCanonicalResolver $canonical,
    ) {}

    /**
     * @return array{
     *     status:'retryable'|'committed',
     *     posting_id:?string,
     *     journal_entry_id:?string
     * }
     */
    public function resolve(
        string $tenantId,
        string $postingOperationId,
        string $receiptId,
        int $actorId,
    ): array {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Verified bank receipt cash unknown-commit recovery requires a fresh transaction.',
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $postingOperationId,
            $receiptId,
            $actorId,
        ): array {
            $posting = DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $tenantId)
                ->where('posting_operation_id', $postingOperationId)
                ->first();

            if ($posting === null) {
                /*
                 * We cannot derive an Accounting source_id from the operation
                 * when the posting aggregate is absent. Therefore any orphan
                 * Journal of this integration type is an integrity fault,
                 * exactly as with Receivable Accounting unknown-outcome
                 * recovery.
                 */
                $orphan = DB::table('journal_entries as journal')
                    ->leftJoin(
                        'bank_receipt_cash_postings as posting',
                        function ($join): void {
                            $join->on(
                                'posting.tenant_id',
                                '=',
                                'journal.tenant_id',
                            )->on(
                                'posting.id',
                                '=',
                                'journal.source_id',
                            );
                        },
                    )
                    ->where('journal.tenant_id', $tenantId)
                    ->where('journal.origin', 'business')
                    ->where(
                        'journal.source_type',
                        'bank_receipt_cash_posting',
                    )
                    ->whereNull('posting.id')
                    ->exists();

                if ($orphan) {
                    throw new ReceiptEvidenceConflict(
                        'An orphan verified bank receipt cash Accounting Journal exists.',
                    );
                }

                return [
                    'status' => 'retryable',
                    'posting_id' => null,
                    'journal_entry_id' => null,
                ];
            }

            if (
                $posting->receipt_id !== $receiptId
                || $posting->posting_operation_id !== $postingOperationId
                || $posting->status !== 'posted'
                || $posting->journal_entry_id === null
            ) {
                throw new ReceiptEvidenceConflict(
                    'Cash posting recovery found inconsistent posting facts.',
                );
            }

            $request = new BusinessPostingRequest(
                $tenantId,
                $actorId,
                'bank_receipt_cash_posting',
                (string) $posting->id,
                'SAR',
                (string) $posting->accounting_date,
                'Verified bank receipt cash posting',
                [
                    new JournalLineData(
                        (string) $posting->cash_account_id,
                        (string) $posting->amount,
                        '0',
                    ),
                    new JournalLineData(
                        (string) $posting->clearing_account_id,
                        '0',
                        (string) $posting->amount,
                    ),
                ],
            );

            try {
                $journal = $this->canonical->resolve($request);
            } catch (\Throwable $exception) {
                throw new ReceiptEvidenceConflict(
                    'Cash posting recovery found inconsistent Journal provenance.',
                    previous: $exception,
                );
            }

            if ($journal->journalEntryId !== $posting->journal_entry_id) {
                throw new ReceiptEvidenceConflict(
                    'Cash posting recovery found mismatched Journal identity.',
                );
            }

            return [
                'status' => 'committed',
                'posting_id' => (string) $posting->id,
                'journal_entry_id' => (string) $journal->journalEntryId,
            ];
        });
    }
}
