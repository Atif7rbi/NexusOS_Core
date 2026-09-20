<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Support;

use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Services\PostingEngine;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReceivableArJournalWriter
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly AccountingAuditWriter $audit,
    ) {}

    public function postRecognition(
        string $tenantId,
        User $actor,
        string $recognitionId,
        string $accountingDate,
        array $arDebit,
        array $contractAssetCredits,
        ?array $contractLiabilityCredit,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Receivable AR Journal creation requires a caller-owned transaction.',
            );
        }

        ksort($contractAssetCredits, SORT_STRING);

        $journalId = (string) Str::ulid();
        $at = now();

        DB::table('journal_entries')->insert([
            'id' => $journalId,
            'tenant_id' => $tenantId,
            'entry_date' => $accountingDate,
            'description' => 'Receivable AR Recognition',
            'status' => 'draft',
            'origin' => 'business',
            'source_type' => 'receivable_ar_recognition',
            'source_id' => $recognitionId,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $lineNumber = 1;
        $arLineId = (string) Str::ulid();

        DB::table('journal_lines')->insert([
            'id' => $arLineId,
            'tenant_id' => $tenantId,
            'journal_entry_id' => $journalId,
            'line_number' => $lineNumber++,
            'account_id' => $arDebit['account_id'],
            'debit' => $arDebit['amount'],
            'credit' => '0.00',
            'memo' => 'Accounts Receivable recognition',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $assetLineIds = [];

        foreach ($contractAssetCredits as $accountId => $amount) {
            $lineId = (string) Str::ulid();
            $assetLineIds[$accountId] = $lineId;

            DB::table('journal_lines')->insert([
                'id' => $lineId,
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journalId,
                'line_number' => $lineNumber++,
                'account_id' => $accountId,
                'debit' => '0.00',
                'credit' => $amount,
                'memo' => 'Contract Asset release',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $liabilityLineId = null;

        if ($contractLiabilityCredit !== null) {
            $liabilityLineId = (string) Str::ulid();

            DB::table('journal_lines')->insert([
                'id' => $liabilityLineId,
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journalId,
                'line_number' => $lineNumber,
                'account_id' => $contractLiabilityCredit['account_id'],
                'debit' => '0.00',
                'credit' => $contractLiabilityCredit['amount'],
                'memo' => 'Contract Liability creation',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $posted = $this->posting->post($tenantId, $journalId, $actor);

        return [
            'journal_entry_id' => $posted->journalEntryId,
            'journal_number' => $posted->journalNumber,
            'ar_line_id' => $arLineId,
            'contract_asset_line_ids' => $assetLineIds,
            'contract_liability_line_id' => $liabilityLineId,
        ];
    }

    public function reverseExact(
        string $tenantId,
        User $actor,
        object $target,
        string $reason,
    ): string {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Receivable AR Journal reversal requires a caller-owned transaction.',
            );
        }

        if ($target->status !== 'posted') {
            throw new AccountingValidationFailed(
                'Receivable AR can reverse only a Posted Journal.',
            );
        }

        $existing = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('reverses_journal_entry_id', $target->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return (string) $existing->id;
        }

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $tenantId)
            ->where('journal_entry_id', $target->id)
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get();

        if ($lines->count() < 2) {
            throw new AccountingValidationFailed(
                'Receivable AR reversal target lacks a complete Journal.',
            );
        }

        $id = (string) Str::ulid();
        $at = now();

        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'entry_date' => $target->entry_date,
            'description' => 'Reversal: '.$target->description,
            'status' => 'draft',
            'origin' => 'reversal',
            'source_type' => 'journal_entry',
            'source_id' => $target->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $at,
            'updated_at' => $at,
            'reverses_journal_entry_id' => $target->id,
            'reversal_reason' => $reason,
        ]);

        foreach ($lines as $line) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $id,
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => $line->memo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $result = $this->posting->post($tenantId, $id, $actor);

        $this->audit->write(
            $tenantId,
            'journal.reversed',
            'journal_entry',
            (string) $target->id,
            (int) $actor->id,
            [
                'reversal_journal_entry_id' => $id,
                'reason' => $reason,
            ],
            $at,
        );

        if ($result->journalEntryId !== $id) {
            throw new AccountingConflict(
                'Receivable AR reversal resolved an unexpected Journal.',
            );
        }

        return $id;
    }
}
