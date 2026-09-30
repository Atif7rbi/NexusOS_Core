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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReverseReceivableSettlement
{
    public function __construct(
        private readonly SettlementTransaction $transaction,
        private readonly ReceivablesAuthorization $receivables,
        private readonly AccountingAuthorization $accounting,
        private readonly SettlementJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    /** @return array{settlement_id:string,reversal_journal_entry_id:string,idempotent_replay:bool} */
    public function execute(string $tenantId, string $settlementId, User $actor, array $input): array
    {
        $facts = $this->facts($input);
        if (! Str::isUlid($settlementId)) {
            throw new SettlementValidationFailed('settlement_id must be a ULID.');
        }
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'reverse_journal');

        return $this->transaction->run(function () use ($tenantId, $settlementId, $actor, $facts): array {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'reverse_journal');

            $hint = DB::table('receivable_settlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $settlementId)
                ->first();
            if ($hint === null) {
                throw (new ModelNotFoundException)->setModel('ReceivableSettlement');
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
                ->where('id', $hint->payment_allocation_id)
                ->lockForUpdate()
                ->first();
            $association = DB::table('receipt_payment_associations')
                ->where('tenant_id', $tenantId)
                ->where('id', $hint->receipt_payment_association_id)
                ->lockForUpdate()
                ->first();
            $cashPosting = DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $tenantId)
                ->where('id', $hint->bank_receipt_cash_posting_id)
                ->lockForUpdate()
                ->first();
            $recognition = DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $tenantId)
                ->where('id', $hint->receivable_ar_recognition_id)
                ->lockForUpdate()
                ->first();
            $settlement = DB::table('receivable_settlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $settlementId)
                ->lockForUpdate()
                ->first();

            if (
                $payment === null || $receivable === null || $allocation === null
                || $association === null || $cashPosting === null || $recognition === null
                || $settlement === null
                || $settlement->payment_id !== $payment->id
                || $settlement->receivable_id !== $receivable->id
                || $settlement->payment_allocation_id !== $allocation->id
                || $settlement->receipt_payment_association_id !== $association->id
                || $settlement->bank_receipt_cash_posting_id !== $cashPosting->id
                || $settlement->receivable_ar_recognition_id !== $recognition->id
            ) {
                throw new SettlementConflict('Settlement reversal provenance is incomplete.');
            }

            if ($settlement->status === 'reversed') {
                return $this->replay($settlement, $facts);
            }
            if ($settlement->status !== 'posted') {
                throw new SettlementConflict('Only a Posted Settlement can be reversed.');
            }

            $operationOwner = DB::table('receivable_settlements')
                ->where('tenant_id', $tenantId)
                ->where('reversal_operation_id', $facts['reversal_operation_id'])
                ->lockForUpdate()
                ->first();
            if ($operationOwner !== null && $operationOwner->id !== $settlementId) {
                throw new SettlementConflict('Settlement reversal operation identity was reused.');
            }

            $journal = DB::table('journal_entries')
                ->where('tenant_id', $tenantId)
                ->where('id', $settlement->journal_entry_id)
                ->lockForUpdate()
                ->first();
            if (
                $journal === null || $journal->status !== 'posted'
                || $journal->origin !== 'business'
                || $journal->source_type !== 'receivable_settlement'
                || $journal->source_id !== $settlementId
            ) {
                throw new SettlementConflict('Settlement owns no exact Posted Journal.');
            }

            $reversalJournalId = $this->journals->reverseExact(
                $tenantId,
                $actor,
                $journal,
                $facts['reversal_date'],
                $facts['reversal_reason'],
            );
            $now = now();
            DB::table('receivable_settlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $settlementId)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $facts['reversal_operation_id'],
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reversal_date' => $facts['reversal_date'],
                    'reversal_reason' => $facts['reversal_reason'],
                    'reversed_by' => $actor->id,
                    'reversed_at' => $now,
                ]);
            $this->audit->write(
                $tenantId,
                'receivable_settlement.reversed',
                'receivable_settlement',
                $settlementId,
                (int) $actor->id,
                [
                    'reversal_operation_id' => $facts['reversal_operation_id'],
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reason' => $facts['reversal_reason'],
                ],
                $now,
            );

            return [
                'settlement_id' => $settlementId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'idempotent_replay' => false,
            ];
        });
    }

    private function facts(array $input): array
    {
        if (array_diff(array_keys($input), ['reversal_operation_id', 'reversal_date', 'reversal_reason']) !== []) {
            throw new SettlementValidationFailed('Settlement reversal accepts operation, date, and reason only.');
        }
        $operation = (string) ($input['reversal_operation_id'] ?? '');
        $date = (string) ($input['reversal_date'] ?? '');
        $reason = trim((string) ($input['reversal_reason'] ?? ''));
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if (! Str::isUlid($operation) || $parsed === false || $parsed->format('Y-m-d') !== $date || $reason === '') {
            throw new SettlementValidationFailed('Valid reversal operation, ISO date, and reason are required.');
        }

        return ['reversal_operation_id' => $operation, 'reversal_date' => $date, 'reversal_reason' => $reason];
    }

    private function replay(object $row, array $facts): array
    {
        if (
            $row->reversal_operation_id !== $facts['reversal_operation_id']
            || (string) $row->reversal_date !== $facts['reversal_date']
            || $row->reversal_reason !== $facts['reversal_reason']
            || $row->reversal_journal_entry_id === null
        ) {
            throw new SettlementConflict('Settlement reversal was replayed with different facts.');
        }

        return [
            'settlement_id' => (string) $row->id,
            'reversal_journal_entry_id' => (string) $row->reversal_journal_entry_id,
            'idempotent_replay' => true,
        ];
    }
}
