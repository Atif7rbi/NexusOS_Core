<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Support\Facades\DB;

final class TrialBalanceQuery
{
    /** @return array{as_of_date:string,rows:list<array<string,mixed>>,debit_total:string,credit_total:string,is_balanced:bool} */
    public function execute(string $tenantId, string $asOfDate, ?string $accountType = null, ?string $classification = null, bool $includeZero = false): array
    {
        $bindings = [$tenantId, $asOfDate, $tenantId, $asOfDate, $tenantId];
        $filters = '';
        if ($accountType !== null) {
            $filters .= ' AND account.account_type=?';
            $bindings[] = $accountType;
        }
        if ($classification !== null) {
            $filters .= ' AND account.classification=?';
            $bindings[] = $classification;
        }
        $bindings[] = $includeZero;

        $result = DB::selectOne(<<<SQL
            WITH activity AS (
                SELECT line.tenant_id, line.account_id,
                       COALESCE(SUM(line.debit), 0) AS debit_total,
                       COALESCE(SUM(line.credit), 0) AS credit_total
                FROM public.journal_entries journal
                JOIN public.journal_lines line
                  ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
                WHERE journal.tenant_id=? AND journal.status='posted' AND journal.entry_date<=?
                GROUP BY line.tenant_id, line.account_id
            ), totals AS (
                SELECT COALESCE(SUM(line.debit), 0) AS debit_total,
                       COALESCE(SUM(line.credit), 0) AS credit_total
                FROM public.journal_entries journal
                JOIN public.journal_lines line
                  ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
                WHERE journal.tenant_id=? AND journal.status='posted' AND journal.entry_date<=?
            ), report_rows AS (
                SELECT account.id AS account_id, account.code, account.name,
                       account.account_type, account.classification, account.status,
                       COALESCE(activity.debit_total, 0) AS debit_total,
                       COALESCE(activity.credit_total, 0) AS credit_total
                FROM public.accounts account
                LEFT JOIN activity
                  ON activity.tenant_id=account.tenant_id AND activity.account_id=account.id
                WHERE account.tenant_id=? AND account.kind='posting'{$filters}
            ), displayed_rows AS (
                SELECT * FROM report_rows
                WHERE ?::boolean OR debit_total<>0 OR credit_total<>0
            ), payload AS (
                SELECT COALESCE(jsonb_agg(jsonb_build_object(
                    'account_id', account_id,
                    'code', code,
                    'name', name,
                    'account_type', account_type,
                    'classification', classification,
                    'status', status,
                    'debit_total', round(debit_total, 2)::text,
                    'credit_total', round(credit_total, 2)::text,
                    'signed_balance', round(debit_total-credit_total, 2)::text,
                    'normal_balance', round(CASE WHEN account_type IN ('asset','expense') THEN debit_total-credit_total ELSE credit_total-debit_total END, 2)::text
                ) ORDER BY code, account_id), '[]'::jsonb) AS rows
                FROM displayed_rows
            )
            SELECT payload.rows::text AS rows,
                   round(totals.debit_total, 2)::text AS debit_total,
                   round(totals.credit_total, 2)::text AS credit_total,
                   totals.debit_total=totals.credit_total AS is_balanced
            FROM payload CROSS JOIN totals
            SQL, $bindings);

        if ($result === null) {
            throw new \RuntimeException('Trial Balance query did not return a result.');
        }

        return [
            'as_of_date' => $asOfDate,
            'rows' => json_decode((string) $result->rows, true, 512, JSON_THROW_ON_ERROR),
            'debit_total' => (string) $result->debit_total,
            'credit_total' => (string) $result->credit_total,
            'is_balanced' => $result->is_balanced === true || $result->is_balanced === 't' || $result->is_balanced === '1',
        ];
    }
}
