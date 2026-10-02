<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Support\Facades\DB;

final class BalanceSheetQuery
{
    /** @return array<string,string> */
    public function execute(string $tenantId, string $asOfDate): array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH balances AS (
              SELECT account.account_type,account.classification,COALESCE(SUM(line.debit),0) AS debit_total,COALESCE(SUM(line.credit),0) AS credit_total
              FROM public.journal_entries journal
              JOIN public.journal_lines line ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
              JOIN public.accounts account ON account.tenant_id=line.tenant_id AND account.id=line.account_id
              WHERE journal.tenant_id=? AND journal.status='posted' AND journal.entry_date<=? AND account.kind='posting'
              GROUP BY account.account_type,account.classification
            ), totals AS (
              SELECT COALESCE(SUM(debit_total-credit_total) FILTER (WHERE classification='current_asset'),0) AS current_assets,
                COALESCE(SUM(debit_total-credit_total) FILTER (WHERE classification='non_current_asset'),0) AS non_current_assets,
                COALESCE(SUM(credit_total-debit_total) FILTER (WHERE classification='current_liability'),0) AS current_liabilities,
                COALESCE(SUM(credit_total-debit_total) FILTER (WHERE classification='non_current_liability'),0) AS non_current_liabilities,
                COALESCE(SUM(credit_total-debit_total) FILTER (WHERE classification='equity'),0) AS equity_accounts,
                COALESCE(SUM(credit_total-debit_total) FILTER (WHERE account_type='revenue'),0)+COALESCE(SUM(credit_total-debit_total) FILTER (WHERE account_type='expense'),0) AS current_earnings
              FROM balances
            )
            SELECT round(current_assets,2)::text AS current_assets,round(non_current_assets,2)::text AS non_current_assets,round(current_assets+non_current_assets,2)::text AS assets,
              round(current_liabilities,2)::text AS current_liabilities,round(non_current_liabilities,2)::text AS non_current_liabilities,round(current_liabilities+non_current_liabilities,2)::text AS liabilities,
              round(equity_accounts,2)::text AS equity_accounts,round(current_earnings,2)::text AS current_earnings,round(equity_accounts+current_earnings,2)::text AS equity,
              round(current_liabilities+non_current_liabilities+equity_accounts+current_earnings,2)::text AS liabilities_and_equity
            FROM totals
            SQL, [$tenantId, $asOfDate]);

        return ['as_of_date' => $asOfDate] + (array) $row;
    }
}
