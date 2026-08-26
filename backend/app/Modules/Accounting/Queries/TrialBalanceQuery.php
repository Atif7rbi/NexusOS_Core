<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Support\Facades\DB;

final class TrialBalanceQuery
{
    /** @return array{as_of_date:string,rows:list<array<string,mixed>>,debit_total:string,credit_total:string,is_balanced:bool} */
    public function execute(string $tenantId, string $asOfDate, ?string $accountType = null, ?string $classification = null, bool $includeZero = false): array
    {
        $bindings = [$asOfDate, $tenantId];
        $filters = '';
        if ($accountType !== null) {
            $filters .= ' AND account.account_type=?';
            $bindings[] = $accountType;
        }
        if ($classification !== null) {
            $filters .= ' AND account.classification=?';
            $bindings[] = $classification;
        }

        $rows = DB::select(<<<SQL
            SELECT account.id AS account_id, account.code, account.name,
                   account.account_type, account.classification, account.status,
                   round(COALESCE(SUM(line.debit) FILTER (WHERE journal.id IS NOT NULL),0),2)::text AS debit_total,
                   round(COALESCE(SUM(line.credit) FILTER (WHERE journal.id IS NOT NULL),0),2)::text AS credit_total,
                   round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE journal.id IS NOT NULL),0),2)::text AS signed_balance,
                   round(COALESCE(SUM(CASE WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END) FILTER (WHERE journal.id IS NOT NULL),0),2)::text AS normal_balance
            FROM public.accounts account
            LEFT JOIN public.journal_lines line
              ON line.tenant_id=account.tenant_id AND line.account_id=account.id
            LEFT JOIN public.journal_entries journal
              ON journal.tenant_id=line.tenant_id AND journal.id=line.journal_entry_id
             AND journal.status='posted' AND journal.entry_date<=?
            WHERE account.tenant_id=? AND account.kind='posting'{$filters}
            GROUP BY account.id,account.code,account.name,account.account_type,account.classification,account.status
            ORDER BY account.code,account.id
            SQL, $bindings);

        $result = array_values(array_filter(array_map(static fn (object $row): array => (array) $row, $rows), static fn (array $row): bool => $includeZero || $row['debit_total'] !== '0.00' || $row['credit_total'] !== '0.00'));
        $totals = DB::selectOne(<<<'SQL'
            SELECT round(COALESCE(SUM(line.debit),0),2)::text AS debit_total,
                   round(COALESCE(SUM(line.credit),0),2)::text AS credit_total
            FROM public.journal_entries journal
            JOIN public.journal_lines line ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
            WHERE journal.tenant_id=? AND journal.status='posted' AND journal.entry_date<=?
            SQL, [$tenantId, $asOfDate]);

        return [
            'as_of_date' => $asOfDate,
            'rows' => $result,
            'debit_total' => $totals->debit_total,
            'credit_total' => $totals->credit_total,
            'is_balanced' => $totals->debit_total === $totals->credit_total,
        ];
    }
}
