<?php

namespace App\Modules\Accounting\Queries;

use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use Illuminate\Support\Facades\DB;

final class CashFlowStatementQuery
{
    /**
     * Derives the direct-method cash-flow statement from posted journal truth.
     *
     * @return array{from_date:string,to_date:string,operating_activities:string,investing_activities:string,financing_activities:string,net_change_in_cash:string,beginning_cash:string,ending_cash:string}
     */
    public function execute(string $tenantId, string $fromDate, string $toDate): array
    {
        $row = DB::selectOne(<<<'SQL'
WITH cash_journals AS (
    SELECT
        line.tenant_id,
        line.journal_entry_id,
        COALESCE(SUM(line.debit - line.credit), 0)::numeric AS cash_delta
    FROM journal_lines AS line
    INNER JOIN account_cash_roles AS cash_role
        ON cash_role.tenant_id = line.tenant_id
        AND cash_role.account_id = line.account_id
    WHERE line.tenant_id = ?
    GROUP BY line.tenant_id, line.journal_entry_id
), posted_cash_journals AS (
    SELECT
        journal.entry_date,
        cash_journals.cash_delta,
        semantic.activity
    FROM journal_entries AS journal
    INNER JOIN cash_journals
        ON cash_journals.tenant_id = journal.tenant_id
        AND cash_journals.journal_entry_id = journal.id
    LEFT JOIN journal_cash_flow_semantics AS semantic
        ON semantic.tenant_id = journal.tenant_id
        AND semantic.journal_entry_id = journal.id
    WHERE journal.tenant_id = ?
        AND journal.status = 'posted'
), totals AS (
    SELECT
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date < ?), 0)::numeric AS beginning_cash,
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date >= ? AND entry_date <= ? AND activity = 'operating'), 0)::numeric AS operating,
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date >= ? AND entry_date <= ? AND activity = 'investing'), 0)::numeric AS investing,
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date >= ? AND entry_date <= ? AND activity = 'financing'), 0)::numeric AS financing,
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date >= ? AND entry_date <= ?), 0)::numeric AS net_cash_flow,
        COALESCE(SUM(cash_delta) FILTER (WHERE entry_date <= ?), 0)::numeric AS ending_cash,
        COALESCE(BOOL_OR(entry_date <= ? AND ((cash_delta <> 0 AND activity IS NULL) OR (cash_delta = 0 AND activity IS NOT NULL))), false) AS classification_incomplete
    FROM posted_cash_journals
)
SELECT
    beginning_cash::text,
    operating::text,
    investing::text,
    financing::text,
    net_cash_flow::text,
    ending_cash::text,
    classification_incomplete
FROM totals
SQL, [
            $tenantId,
            $tenantId,
            $fromDate,
            $fromDate,
            $toDate,
            $fromDate,
            $toDate,
            $fromDate,
            $toDate,
            $fromDate,
            $toDate,
            $toDate,
            $toDate,
        ]);

        if ((bool) $row->classification_incomplete) {
            throw new AccountingValidationFailed('CASH_FLOW_CLASSIFICATION_INCOMPLETE');
        }

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'beginning_cash' => $this->money($row->beginning_cash),
            'operating_activities' => $this->money($row->operating),
            'investing_activities' => $this->money($row->investing),
            'financing_activities' => $this->money($row->financing),
            'net_change_in_cash' => $this->money($row->net_cash_flow),
            'ending_cash' => $this->money($row->ending_cash),
        ];
    }

    private function money(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        return ($negative ? '-' : '').$whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
