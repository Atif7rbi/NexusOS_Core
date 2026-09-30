<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Models\User;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Services\PostingEngine;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SettlementJournalWriter
{
    public function __construct(
        private readonly BusinessPostingServiceInterface $businessPosting,
        private readonly PostingEngine $posting,
        private readonly AccountingAuditWriter $audit,
    ) {}

    public function post(
        string $tenantId,
        User $actor,
        string $settlementId,
        string $accountingDate,
        string $clearingAccountId,
        string $arAccountId,
        string $amount,
    ): string {
        $journal = $this->businessPosting->post(new BusinessPostingRequest(
            $tenantId,
            (int) $actor->id,
            'receivable_settlement',
            $settlementId,
            'SAR',
            $accountingDate,
            'Receivable settlement',
            [
                new JournalLineData($clearingAccountId, $amount, '0'),
                new JournalLineData($arAccountId, '0', $amount),
            ],
        ));

        return $journal->journalEntryId;
    }

    public function reverseExact(
        string $tenantId,
        User $actor,
        object $target,
        string $entryDate,
        string $reason,
    ): string {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Settlement Journal reversal requires a caller-owned transaction.');
        }

        if ($target->status !== 'posted' || $entryDate < $target->entry_date) {
            throw new AccountingValidationFailed(
                'Settlement can reverse only a Posted Journal on or after its accounting date.',
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

        if ($lines->count() !== 2) {
            throw new AccountingValidationFailed('Settlement reversal target must have exactly two lines.');
        }

        $id = (string) Str::ulid();
        $at = now();
        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'entry_date' => $entryDate,
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
            ['reversal_journal_entry_id' => $id, 'reason' => $reason],
            $at,
        );

        if ($result->journalEntryId !== $id) {
            throw new AccountingConflict('Settlement reversal resolved an unexpected Journal.');
        }

        return $id;
    }
}
