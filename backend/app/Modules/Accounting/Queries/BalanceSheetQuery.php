<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Support\Facades\DB;

final class BalanceSheetQuery
{
    /** @return array<string,string|bool> */
    public function execute(string $tenantId, string $asOfDate): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='current_asset'),0),2)::text AS current_asset,
              round(COALESCE(SUM(line.debit-line.credit) FILTER (WHERE account.classification='non_current_asset'),0),2)::text AS non_current_asset,
              round(COALESCE(SUM(line.credit-line.debit) FILTER (WHERE account.classification='current_liability'),0),2)::text AS current_liability,
              round(COALESCE(SUM(line.credit-line.debit) FILTER (WHERE account.classification='non_current_liability'),0),2)::text AS non_current_liability,
              round(COALESCE(SUM(line.credit-line.debit) FILTER (WHERE account.classification='equity'),0),2)::text AS equity,
              round(COALESCE(SUM(CASE WHEN account.account_type='asset' THEN line.debit-line.credit ELSE 0 END),0),2)::text AS assets,
              round(COALESCE(SUM(CASE WHEN account.account_type='liability' THEN line.credit-line.debit ELSE 0 END),0),2)::text AS liabilities,
              round(COALESCE(SUM(CASE WHEN account.account_type='revenue' THEN line.credit-line.debit WHEN account.account_type='expense' THEN line.credit-line.debit ELSE 0 END),0),2)::text AS derived_unclosed_earnings,
              round(COALESCE(SUM(CASE WHEN account.account_type='asset' THEN line.debit-line.credit WHEN account.account_type IN ('liability','equity','revenue','expense') THEN line.debit-line.credit ELSE 0 END),0),2)::text AS equation_difference
            FROM public.journal_entries journal
            JOIN public.journal_lines line ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
            JOIN public.accounts account ON account.tenant_id=line.tenant_id AND account.id=line.account_id
            WHERE journal.tenant_id=? AND journal.status='posted' AND journal.entry_date<=?
            SQL, [$tenantId, $asOfDate]);
        $result = ['as_of_date' => $asOfDate] + (array) $row;
        $result['is_balanced'] = $result['equation_difference'] === '0.00';

        return $result;
    }
}
