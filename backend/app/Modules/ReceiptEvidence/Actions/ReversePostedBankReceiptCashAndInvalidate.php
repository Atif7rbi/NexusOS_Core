<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\EffectiveReceiptPaymentAssociationGuard;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReversePostedBankReceiptCashAndInvalidate
{
    public function __construct(private readonly VerifiedBankReceiptCashTransaction $tx, private readonly ReceivablesAuthorization $receivables, private readonly AccountingAuthorization $accounting, private readonly ReverseJournalAction $reverse, private readonly EffectiveReceiptPaymentAssociationGuard $associations) {}

    /** @return array{posting_id:string,reversal_journal_entry_id:string,idempotent_replay:bool} */
    public function execute(string $tenantId, string $receiptId, string $postingId, User $actor, array $input): array
    {
        $facts = ['reversal_operation_id' => ReceiptEvidenceFacts::operation($input, 'reversal_operation_id'), 'reversal_date' => ReceiptEvidenceFacts::date($input, 'reversal_date'), 'reversal_reason' => ReceiptEvidenceFacts::reason($input, 'reversal_reason'), 'invalidation_operation_id' => ReceiptEvidenceFacts::operation($input, 'invalidation_operation_id'), 'invalidation_reason' => ReceiptEvidenceFacts::reason($input, 'invalidation_reason')];
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'reverse_journal');
        try {
            return $this->tx->run(fn (): array => $this->reverseAndInvalidate($tenantId, $receiptId, $postingId, $actor, $facts));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve($tenantId, $receiptId, $postingId, $actor, $facts);
        }
    }

    private function reverseAndInvalidate(string $tenantId, string $receiptId, string $postingId, User $actor, array $facts): array
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'reverse_journal');
        $receipt = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $receiptId)->lockForUpdate()->first();
        if ($receipt === null) {
            throw (new ModelNotFoundException)->setModel('BankReceiptEvidence');
        }
        $posting = DB::table('bank_receipt_cash_postings')->where('tenant_id', $tenantId)->where('id', $postingId)->lockForUpdate()->first();
        if ($posting === null || $posting->receipt_id !== $receiptId) {
            throw (new ModelNotFoundException)->setModel('BankReceiptCashPosting');
        }
        if ($posting->status === 'reversed') {
            return $this->replay($receipt, $posting, $facts);
        }
        $sameReversal = DB::table('bank_receipt_cash_postings')->where('tenant_id', $tenantId)->where('reversal_operation_id', $facts['reversal_operation_id'])->lockForUpdate()->first();
        if ($sameReversal !== null) {
            throw new ReceiptEvidenceConflict('Reversal operation identity was reused with different facts.');
        }
        if ($receipt->status !== 'effective') {
            throw new ReceiptEvidenceConflict('Only an effective receipt can be reversed and invalidated.');
        }
        $this->associations->assertNoneForReceipt($tenantId, $receiptId);
        $reversal = $this->reverse->execute($tenantId, (string) $posting->journal_entry_id, $actor, $facts['reversal_date'], $facts['reversal_reason'], participating: true);
        $now = now();
        DB::table('bank_receipt_cash_postings')->where('tenant_id', $tenantId)->where('id', $postingId)->update(['status' => 'reversed', 'reversal_operation_id' => $facts['reversal_operation_id'], 'reversal_journal_entry_id' => $reversal->journalEntryId, 'reversal_date' => $facts['reversal_date'], 'reversal_reason' => $facts['reversal_reason'], 'reversed_by' => $actor->id, 'reversed_at' => $now, 'updated_at' => $now]);
        DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $receiptId)->update(['status' => 'invalidated', 'invalidation_operation_id' => $facts['invalidation_operation_id'], 'invalidation_reason' => $facts['invalidation_reason'], 'invalidated_by' => $actor->id, 'invalidated_at' => $now, 'updated_at' => $now]);

        return ['posting_id' => $postingId, 'reversal_journal_entry_id' => $reversal->journalEntryId, 'idempotent_replay' => false];
    }

    private function resolve(string $tenantId, string $receiptId, string $postingId, User $actor, array $facts): array
    {
        return $this->tx->run(function () use ($tenantId, $receiptId, $postingId, $actor, $facts): array {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'reverse_journal');
            $posting = DB::table('bank_receipt_cash_postings')->where('tenant_id', $tenantId)->where('reversal_operation_id', $facts['reversal_operation_id'])->lockForUpdate()->first();
            $receipt = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $receiptId)->lockForUpdate()->first();
            if ($posting === null || $receipt === null || $posting->id !== $postingId) {
                throw new ReceiptEvidenceConflict('Cash reversal outcome is unavailable after uniqueness race.');
            }

            return $this->replay($receipt, $posting, $facts);
        });
    }

    private function replay(object $receipt, object $posting, array $facts): array
    {
        if ($posting->status !== 'reversed' || $posting->reversal_operation_id !== $facts['reversal_operation_id'] || $posting->reversal_date !== $facts['reversal_date'] || $posting->reversal_reason !== $facts['reversal_reason'] || $receipt->status !== 'invalidated' || $receipt->invalidation_operation_id !== $facts['invalidation_operation_id'] || $receipt->invalidation_reason !== $facts['invalidation_reason']) {
            throw new ReceiptEvidenceConflict('Cash reversal or receipt invalidation operation identity was reused with different facts.');
        }

        return ['posting_id' => (string) $posting->id, 'reversal_journal_entry_id' => (string) $posting->reversal_journal_entry_id, 'idempotent_replay' => true];
    }
}
