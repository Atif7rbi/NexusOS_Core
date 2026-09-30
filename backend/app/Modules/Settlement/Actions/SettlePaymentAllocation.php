<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Settlement\Exceptions\SettlementConflict;
use App\Modules\Settlement\Exceptions\SettlementValidationFailed;
use App\Modules\Settlement\Support\SettlementJournalWriter;
use App\Modules\Settlement\Support\SettlementTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SettlePaymentAllocation
{
    public function __construct(
        private readonly SettlementTransaction $transaction,
        private readonly ReceivablesAuthorization $receivables,
        private readonly AccountingAuthorization $accounting,
        private readonly SettlementJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    /** @return array{settlement_id:string,journal_entry_id:string,idempotent_replay:bool} */
    public function execute(string $tenantId, User $actor, array $input): array
    {
        $facts = $this->facts($input);
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'post_journal');

        $committed = $this->resolveCommitted($tenantId, $facts, true);
        if ($committed !== null) {
            return $committed;
        }

        try {
            return $this->transaction->run(
                fn (): array => $this->settle($tenantId, $actor, $facts),
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolveCommitted($tenantId, $facts, false)
                ?? throw new SettlementConflict(
                    'Settlement uniqueness conflict has no exact committed replay winner.',
                    previous: $exception,
                );
        }
    }

    private function settle(string $tenantId, User $actor, array $facts): array
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'post_journal');

        $hint = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $facts['payment_allocation_id'])
            ->first();
        if ($hint === null) {
            throw (new ModelNotFoundException)->setModel('PaymentAllocation');
        }

        $payment = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('id', $hint->payment_id)
            ->lockForUpdate()
            ->first();
        $receivable = DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $hint->receivable_id)
            ->lockForUpdate()
            ->first();
        $allocation = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $facts['payment_allocation_id'])
            ->lockForUpdate()
            ->first();

        if ($payment === null || $receivable === null || $allocation === null) {
            throw new SettlementConflict('Settlement allocation provenance is incomplete.');
        }
        if (
            $allocation->payment_id !== $payment->id
            || $allocation->receivable_id !== $receivable->id
            || $allocation->status !== 'effective'
            || $payment->status !== 'received'
            || $receivable->status !== 'recognized'
            || $payment->customer_id !== $receivable->customer_id
            || $payment->currency !== 'SAR'
            || $receivable->currency !== 'SAR'
        ) {
            throw new SettlementConflict('Payment Allocation is not eligible for Settlement.');
        }

        $association = DB::table('receipt_payment_associations')
            ->where('tenant_id', $tenantId)
            ->where('payment_id', $payment->id)
            ->where('status', 'effective')
            ->lockForUpdate()
            ->first();
        if ($association === null) {
            throw new SettlementConflict('Settlement requires the exact effective Receipt association.');
        }

        $receipt = DB::table('bank_receipt_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $association->receipt_id)
            ->first();
        if (
            $receipt === null
            || $receipt->status !== 'effective'
            || $receipt->currency !== 'SAR'
            || $association->currency !== 'SAR'
            || $association->payment_id !== $payment->id
        ) {
            throw new SettlementConflict('Settlement Receipt provenance is not effective and exact.');
        }

        $cashPosting = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $tenantId)
            ->where('receipt_id', $receipt->id)
            ->lockForUpdate()
            ->first();
        if ($cashPosting === null || $cashPosting->status !== 'posted' || $cashPosting->currency !== 'SAR') {
            throw new SettlementConflict('Settlement requires the exact Posted Cash Posting.');
        }

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('receivable_id', $receivable->id)
            ->lockForUpdate()
            ->first();
        if ($recognition === null || $recognition->status !== 'posted' || $recognition->currency !== 'SAR') {
            throw new SettlementConflict('Settlement requires the exact Posted Receivable AR Recognition.');
        }

        $existing = DB::table('receivable_settlements')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($facts): void {
                $query->where('settlement_operation_id', $facts['settlement_operation_id'])
                    ->orWhere('payment_allocation_id', $facts['payment_allocation_id']);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($existing->isNotEmpty()) {
            if ($existing->count() !== 1) {
                throw new SettlementConflict('Settlement identity resolved multiple historical rows.');
            }

            return $this->replay($existing->first(), $facts);
        }

        $settlementId = (string) Str::ulid();
        $accountingDate = max(
            (string) $cashPosting->accounting_date,
            (string) $recognition->accounting_date,
        );
        $journalId = $this->journals->post(
            $tenantId,
            $actor,
            $settlementId,
            $accountingDate,
            (string) $cashPosting->clearing_account_id,
            (string) $recognition->ar_control_account_id,
            (string) $allocation->amount,
        );
        $now = now();
        DB::table('receivable_settlements')->insert([
            'id' => $settlementId,
            'tenant_id' => $tenantId,
            'settlement_operation_id' => $facts['settlement_operation_id'],
            'payment_allocation_id' => $allocation->id,
            'payment_id' => $payment->id,
            'receivable_id' => $receivable->id,
            'receipt_payment_association_id' => $association->id,
            'receipt_id' => $receipt->id,
            'bank_receipt_cash_posting_id' => $cashPosting->id,
            'receivable_ar_recognition_id' => $recognition->id,
            'amount' => $allocation->amount,
            'currency' => 'SAR',
            'accounting_date' => $accountingDate,
            'clearing_account_id' => $cashPosting->clearing_account_id,
            'ar_control_account_id' => $recognition->ar_control_account_id,
            'journal_entry_id' => $journalId,
            'status' => 'posted',
            'created_by' => $actor->id,
            'created_at' => $now,
        ]);
        $this->audit->write(
            $tenantId,
            'receivable_settlement.posted',
            'receivable_settlement',
            $settlementId,
            (int) $actor->id,
            [
                'settlement_operation_id' => $facts['settlement_operation_id'],
                'payment_allocation_id' => $allocation->id,
                'payment_id' => $payment->id,
                'receivable_id' => $receivable->id,
                'receipt_payment_association_id' => $association->id,
                'receipt_id' => $receipt->id,
                'bank_receipt_cash_posting_id' => $cashPosting->id,
                'receivable_ar_recognition_id' => $recognition->id,
                'journal_entry_id' => $journalId,
            ],
            $now,
        );

        return ['settlement_id' => $settlementId, 'journal_entry_id' => $journalId, 'idempotent_replay' => false];
    }

    private function facts(array $input): array
    {
        if (array_diff(array_keys($input), ['payment_allocation_id', 'settlement_operation_id']) !== []) {
            throw new SettlementValidationFailed('Settlement accepts Allocation and operation identities only.');
        }
        $allocationId = (string) ($input['payment_allocation_id'] ?? '');
        $operationId = (string) ($input['settlement_operation_id'] ?? '');
        if (! Str::isUlid($allocationId) || ! Str::isUlid($operationId)) {
            throw new SettlementValidationFailed('Settlement identities must be ULIDs.');
        }

        return ['payment_allocation_id' => $allocationId, 'settlement_operation_id' => $operationId];
    }

    private function resolveCommitted(string $tenantId, array $facts, bool $nullable): ?array
    {
        $row = DB::table('receivable_settlements')
            ->where('tenant_id', $tenantId)
            ->where('settlement_operation_id', $facts['settlement_operation_id'])
            ->first();
        if ($row === null) {
            if (! $nullable) {
                $row = DB::table('receivable_settlements')
                    ->where('tenant_id', $tenantId)
                    ->where('payment_allocation_id', $facts['payment_allocation_id'])
                    ->first();
            }
            if ($row === null) {
                return null;
            }
        }

        return $this->replay($row, $facts);
    }

    private function replay(object $row, array $facts): array
    {
        if (
            $row->settlement_operation_id !== $facts['settlement_operation_id']
            || $row->payment_allocation_id !== $facts['payment_allocation_id']
            || $row->journal_entry_id === null
        ) {
            throw new SettlementConflict('Settlement operation identity was reused with different facts.');
        }

        return [
            'settlement_id' => (string) $row->id,
            'journal_entry_id' => (string) $row->journal_entry_id,
            'idempotent_replay' => true,
        ];
    }
}
