<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashRecoveryResolver;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostVerifiedBankReceiptCash
{
    public function __construct(
        private readonly VerifiedBankReceiptCashTransaction $tx,
        private readonly ReceivablesAuthorization $receivables,
        private readonly AccountingAuthorization $accounting,
        private readonly BusinessPostingServiceInterface $posting,
        private readonly VerifiedBankReceiptCashRecoveryResolver $recovery,
    ) {}

    /** @return array{posting_id:string,journal_entry_id:string,idempotent_replay:bool} */
    public function execute(
        string $tenantId,
        string $receiptId,
        User $actor,
        array $input,
    ): array {
        $operation = ReceiptEvidenceFacts::operation(
            $input,
            'posting_operation_id',
        );

        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'post_journal');

        try {
            return $this->tx->run(
                fn (): array => $this->post(
                    $tenantId,
                    $receiptId,
                    $actor,
                    $operation,
                ),
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve(
                $tenantId,
                $receiptId,
                $actor,
                $operation,
            );
        }
    }

    private function post(
        string $tenantId,
        string $receiptId,
        User $actor,
        string $operation,
    ): array {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional(
            $tenantId,
            $actor,
            'post_journal',
        );

        $receiptHint = DB::table('bank_receipt_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $receiptId)
            ->first();

        if ($receiptHint === null) {
            throw (new ModelNotFoundException)->setModel(
                'BankReceiptEvidence',
            );
        }

        DB::table('approved_receiving_accounts')
            ->where('tenant_id', $tenantId)
            ->where('id', $receiptHint->receiving_account_id)
            ->lockForUpdate()
            ->first()
            ?? throw new ReceiptEvidenceValidationFailed(
                'Receiving account was not found.',
            );

        $mapping = DB::table(
            'approved_receiving_account_cash_mappings',
        )
            ->where('tenant_id', $tenantId)
            ->where(
                'receiving_account_id',
                $receiptHint->receiving_account_id,
            )
            ->where('status', 'effective')
            ->lockForUpdate()
            ->first()
            ?? throw new ReceiptEvidenceValidationFailed(
                'Effective receiving account cash mapping is required.',
            );

        $policy = DB::table('bank_receipt_cash_clearing_policies')
            ->where('tenant_id', $tenantId)
            ->where('status', 'effective')
            ->lockForUpdate()
            ->first()
            ?? throw new ReceiptEvidenceValidationFailed(
                'Effective cash clearing policy is required.',
            );

        $receipt = DB::table('bank_receipt_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $receiptId)
            ->lockForUpdate()
            ->first();

        if ($receipt === null) {
            throw (new ModelNotFoundException)->setModel(
                'BankReceiptEvidence',
            );
        }

        if (
            $receipt->status !== 'effective'
            || $receipt->channel !== 'bank_transfer'
            || $receipt->currency !== 'SAR'
        ) {
            throw new ReceiptEvidenceConflict(
                'Only an effective SAR bank transfer receipt is eligible for cash posting.',
            );
        }

        $existingOperation = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $tenantId)
            ->where('posting_operation_id', $operation)
            ->lockForUpdate()
            ->first();

        if ($existingOperation !== null) {
            return $this->replay(
                $existingOperation,
                $receiptId,
                $operation,
            );
        }

        if (
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $tenantId)
                ->where('receipt_id', $receiptId)
                ->lockForUpdate()
                ->exists()
        ) {
            throw new ReceiptEvidenceConflict(
                'Receipt already has a cash posting.',
            );
        }

        $id = (string) Str::ulid();
        $now = now();

        $journal = $this->posting->post(
            new BusinessPostingRequest(
                $tenantId,
                (int) $actor->id,
                'bank_receipt_cash_posting',
                $id,
                'SAR',
                (string) $receipt->control_date,
                'Verified bank receipt cash posting',
                [
                    new JournalLineData(
                        (string) $mapping->cash_account_id,
                        (string) $receipt->amount,
                        '0',
                    ),
                    new JournalLineData(
                        (string) $policy->clearing_account_id,
                        '0',
                        (string) $receipt->amount,
                    ),
                ],
            ),
        );

        DB::table('bank_receipt_cash_postings')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'posting_operation_id' => $operation,
            'receipt_id' => $receiptId,
            'cash_mapping_id' => $mapping->id,
            'cash_policy_id' => $policy->id,
            'receiving_account_id' => $receipt->receiving_account_id,
            'amount' => $receipt->amount,
            'currency' => 'SAR',
            'accounting_date' => $receipt->control_date,
            'cash_account_id' => $mapping->cash_account_id,
            'clearing_account_id' => $policy->clearing_account_id,
            'status' => 'posted',
            'journal_entry_id' => $journal->journalEntryId,
            'posted_by' => $actor->id,
            'posted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'posting_id' => $id,
            'journal_entry_id' => $journal->journalEntryId,
            'idempotent_replay' => false,
        ];
    }

    private function resolve(
        string $tenantId,
        string $receiptId,
        User $actor,
        string $operation,
    ): array {
        $outcome = $this->recovery->resolve(
            $tenantId,
            $operation,
            $receiptId,
            (int) $actor->id,
        );

        if ($outcome['status'] === 'retryable') {
            return $this->tx->run(
                fn (): array => $this->post(
                    $tenantId,
                    $receiptId,
                    $actor,
                    $operation,
                ),
            );
        }

        $row = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $tenantId)
            ->where('id', $outcome['posting_id'])
            ->first();

        if ($row === null) {
            throw new ReceiptEvidenceConflict(
                'Cash posting recovery lost committed posting truth.',
            );
        }

        return $this->replay($row, $receiptId, $operation);
    }

    private function replay(
        object $row,
        string $receiptId,
        string $operation,
    ): array {
        if (
            $row->receipt_id !== $receiptId
            || $row->posting_operation_id !== $operation
            || $row->status !== 'posted'
            || $row->journal_entry_id === null
        ) {
            throw new ReceiptEvidenceConflict(
                'Cash posting operation identity was reused with different or terminal facts.',
            );
        }

        $journal = DB::table('journal_entries')
            ->where('tenant_id', $row->tenant_id)
            ->where('id', $row->journal_entry_id)
            ->first();

        if (
            $journal === null
            || $journal->status !== 'posted'
            || $journal->origin !== 'business'
            || $journal->source_type !== 'bank_receipt_cash_posting'
            || $journal->source_id !== $row->id
        ) {
            throw new ReceiptEvidenceConflict(
                'Cash posting recovery found inconsistent Journal provenance.',
            );
        }

        return [
            'posting_id' => (string) $row->id,
            'journal_entry_id' => (string) $row->journal_entry_id,
            'idempotent_replay' => true,
        ];
    }
}
