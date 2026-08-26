<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Support\Facades\DB;

final class IncomeStatementQuery
{
    /** @return array<string,string> */
    public function execute(string $tenantId, string $fromDate, string $toDate): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              round(COALESCE(SUM(line.credit-line.debit) FILTER (WHERE account.classification='operating_revenue'),0),2)::text AS operating_revenue,
              round(COALESCE(SUM(line.credit-line.debit) FILTER (WHERE account.classification='other_revenue'),0),2)::text AS other_revenue,
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='cost_of_revenue'),0),2)::text AS cost_of_revenue,
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='operating_expense'),0),2)::text AS operating_expense,
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='finance_cost'),0),2)::text AS finance_cost,
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='other_expense'),0),2)::text AS other_expense,
              round(COALESCE(SUM(CASE WHEN account.account_type='revenue' THEN line.credit-line.debit ELSE 0 END),0),2)::text AS revenue,
              round(COALESCE(SUM(CASE WHEN account.account_type='expense' THEN line.debit-line.credit ELSE 0 END),0),2)::text AS expense,
              round(COALESCE(SUM(CASE WHEN account.account_type='revenue' THEN line.credit-line.debit WHEN account.account_type='expense' THEN line.credit-line.debit ELSE 0 END),0),2)::text AS net_income
            FROM public.journal_entries journal
            JOIN public.journal_lines line ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
            JOIN public.accounts account ON account.tenant_id=line.tenant_id AND account.id=line.account_id
            WHERE journal.tenant_id=? AND journal.status='posted'
              AND journal.entry_date BETWEEN ? AND ?
              AND account.account_type IN ('revenue','expense')
            SQL, [$tenantId, $fromDate, $toDate]);

        return ['from_date' => $fromDate, 'to_date' => $toDate] + (array) $row;
    }
}
