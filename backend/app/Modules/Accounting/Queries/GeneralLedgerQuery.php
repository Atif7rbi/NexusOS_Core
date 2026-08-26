<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Queries;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class GeneralLedgerQuery
{
    /** @return array{account:array<string,mixed>,from_date:string,to_date:string,opening_balance:string,movements:list<array<string,mixed>>,closing_balance:string} */
    public function execute(string $tenantId, string $accountId, string $fromDate, string $toDate): array
    {
        $account = DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->where('kind', 'posting')->first();
        if ($account === null) {
            throw (new ModelNotFoundException)->setModel('Accounting account');
        }

        $rows = DB::select(<<<'SQL'
            WITH opening AS (
              SELECT COALESCE(SUM(CASE WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END) FILTER (WHERE journal.id IS NOT NULL),0) AS balance
              FROM public.accounts account
              LEFT JOIN public.journal_lines line ON line.tenant_id=account.tenant_id AND line.account_id=account.id
              LEFT JOIN public.journal_entries journal ON journal.tenant_id=line.tenant_id AND journal.id=line.journal_entry_id
                AND journal.status='posted' AND journal.entry_date<?
              WHERE account.tenant_id=? AND account.id=?
            ), movements AS (
              SELECT journal.entry_date,journal.journal_number,journal.journal_sequence_number,
                     journal.id AS journal_id,journal.description,journal.origin,
                     line.id AS line_id,line.line_number,line.memo,line.debit,line.credit,
                     CASE WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END AS delta
              FROM public.journal_entries journal
              JOIN public.journal_lines line ON line.tenant_id=journal.tenant_id AND line.journal_entry_id=journal.id
              JOIN public.accounts account ON account.tenant_id=line.tenant_id AND account.id=line.account_id
              WHERE journal.tenant_id=? AND line.account_id=? AND journal.status='posted'
                AND journal.entry_date BETWEEN ? AND ?
            )
            SELECT movement.entry_date,movement.journal_number,movement.journal_sequence_number,
                   movement.journal_id,movement.description,movement.origin,movement.line_id,
                   movement.line_number,movement.memo,round(movement.debit,2)::text AS debit,
                   round(movement.credit,2)::text AS credit,
                   round(opening.balance+SUM(movement.delta) OVER (
                     ORDER BY movement.entry_date,movement.journal_sequence_number NULLS LAST,movement.line_number,movement.journal_id
                     ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                   ),2)::text AS running_balance
            FROM movements movement CROSS JOIN opening
            ORDER BY movement.entry_date,movement.journal_sequence_number NULLS LAST,movement.line_number,movement.journal_id
            SQL, [$fromDate, $tenantId, $accountId, $tenantId, $accountId, $fromDate, $toDate]);

        $opening = DB::selectOne(<<<'SQL'
            SELECT round(COALESCE(SUM(CASE WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END) FILTER (WHERE journal.id IS NOT NULL),0),2)::text AS balance
            FROM public.accounts account
            LEFT JOIN public.journal_lines line ON line.tenant_id=account.tenant_id AND line.account_id=account.id
            LEFT JOIN public.journal_entries journal ON journal.tenant_id=line.tenant_id AND journal.id=line.journal_entry_id
              AND journal.status='posted' AND journal.entry_date<?
            WHERE account.tenant_id=? AND account.id=?
            SQL, [$fromDate, $tenantId, $accountId])->balance;
        $movements = array_map(static fn (object $row): array => (array) $row, $rows);

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type, 'classification' => $account->classification, 'status' => $account->status],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => $opening,
            'movements' => $movements,
            'closing_balance' => $movements === [] ? $opening : $movements[array_key_last($movements)]['running_balance'],
        ];
    }
}
