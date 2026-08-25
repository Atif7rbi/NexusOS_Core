<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\ValueObjects\Money;
use App\Modules\Shared\Contracts\BusinessNumberGeneratorInterface;
use Illuminate\Support\Facades\DB;

final class PostingEngine
{
    public function __construct(private readonly BusinessNumberGeneratorInterface $numbers, private readonly AccountingAuditWriter $audit) {}

    public function post(string $tenantId, string $journalId, User $actor): PostedJournalResult
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('PostingEngine requires a caller-owned transaction.');
        }
        $journal = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first();
        if ($journal === null) {
            throw new AccountingValidationFailed('Journal was not found.');
        }
        if ($journal->status === 'posted') {
            return new PostedJournalResult($journal->id, $journal->journal_number, true);
        }
        $period = DB::table('accounting_periods')->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $journal->entry_date)->whereDate('end_date', '>=', $journal->entry_date)
            ->orderBy('id')->lockForUpdate()->first();
        if ($period === null || $period->status !== 'open') {
            throw new AccountingValidationFailed('Journal requires a containing open period.');
        }
        $lines = DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $journalId)->orderBy('line_number')->lockForUpdate()->get();
        if ($lines->count() < 2) {
            throw new AccountingValidationFailed('Journal requires at least two lines.');
        }
        $accountIds = $lines->pluck('account_id')->unique()->sort()->values()->all();
        $accounts = DB::table('accounts')->where('tenant_id', $tenantId)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($accounts->count() !== count($accountIds)) {
            throw new AccountingValidationFailed('A journal account was not found.');
        }
        foreach ($accountIds as $accountId) {
            $account = $accounts[$accountId];
            if ($account->kind !== 'posting' || ($journal->origin !== 'reversal' && $account->status !== 'active')) {
                throw new AccountingValidationFailed('A journal account is not eligible.');
            }
        }
        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($lines as $index => $line) {
            if ((int) $line->line_number !== $index + 1) {
                throw new AccountingValidationFailed('Journal lines must be contiguous.');
            }
            $debit = $debit->plus(Money::of((string) $line->debit));
            $credit = $credit->plus(Money::of((string) $line->credit));
        }
        if ($debit->isZero() || ! $debit->equals($credit)) {
            throw new AccountingValidationFailed('Journal must be non-zero and balanced.');
        }
        $year = (int) substr((string) $journal->entry_date, 0, 4);
        $number = $this->numbers->generateWithinCurrentTransaction($tenantId, 'JRN', $year);
        $at = now();
        $updated = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->where('status', 'draft')->update([
            'status' => 'posted', 'accounting_period_id' => $period->id,
            'journal_number' => $number['number'], 'journal_number_year' => $number['year'],
            'journal_sequence_number' => $number['sequence'], 'posted_by' => $actor->id,
            'posted_at' => $at, 'updated_by' => $actor->id, 'updated_at' => $at,
        ]);
        if ($updated !== 1) {
            throw new AccountingConflict('Journal changed while posting.');
        }
        $this->audit->write($tenantId, 'journal.posted', 'journal_entry', $journalId, (int) $actor->id, ['journal_number' => $number['number']], $at);

        return new PostedJournalResult($journalId, $number['number']);
    }
}
