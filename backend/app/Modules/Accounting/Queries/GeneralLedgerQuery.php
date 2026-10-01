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
        $result = DB::selectOne(<<<'SQL'
            WITH account AS (
              SELECT id,code,name,account_type,classification,status FROM public.accounts
              WHERE tenant_id=? AND id=? AND kind='posting'
            ), opening AS (
              SELECT COALESCE(SUM(CASE WHEN journal.id IS NULL THEN 0 WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END),0) AS balance
              FROM account LEFT JOIN public.journal_lines line ON line.tenant_id=? AND line.account_id=account.id
              LEFT JOIN public.journal_entries journal ON journal.tenant_id=line.tenant_id AND journal.id=line.journal_entry_id AND journal.status='posted' AND journal.entry_date<?
            ), movement_base AS (
              SELECT journal.entry_date,journal.journal_number,journal.journal_sequence_number,journal.id AS journal_id,journal.description,journal.origin,line.id AS line_id,line.line_number,line.memo,line.debit,line.credit,
              CASE WHEN account.account_type IN ('asset','expense') THEN line.debit-line.credit ELSE line.credit-line.debit END AS delta
              FROM account JOIN public.journal_lines line ON line.tenant_id=? AND line.account_id=account.id
              JOIN public.journal_entries journal ON journal.tenant_id=line.tenant_id AND journal.id=line.journal_entry_id
              WHERE journal.status='posted' AND journal.entry_date BETWEEN ? AND ?
            ), movements AS (
              SELECT *, (SELECT balance FROM opening)+SUM(delta) OVER (ORDER BY entry_date,journal_sequence_number,line_number,journal_id,line_id ROWS UNBOUNDED PRECEDING) AS running_balance FROM movement_base
            ), payload AS (
              SELECT COALESCE(jsonb_agg(jsonb_build_object('entry_date',entry_date::text,'journal_number',journal_number,'journal_sequence_number',journal_sequence_number,'journal_id',journal_id,'description',description,'origin',origin,'line_id',line_id,'line_number',line_number,'memo',memo,'debit',round(debit,2)::text,'credit',round(credit,2)::text,'running_balance',round(running_balance,2)::text) ORDER BY entry_date,journal_sequence_number,line_number,journal_id,line_id),'[]'::jsonb) AS movements FROM movements
            )
            SELECT jsonb_build_object('id',account.id,'code',account.code,'name',account.name,'account_type',account.account_type,'classification',account.classification,'status',account.status)::text AS account,
              round(opening.balance,2)::text AS opening_balance,payload.movements::text AS movements,
              round(COALESCE((SELECT running_balance FROM movements ORDER BY entry_date DESC,journal_sequence_number DESC,line_number DESC,journal_id DESC,line_id DESC LIMIT 1),opening.balance),2)::text AS closing_balance
            FROM account CROSS JOIN opening CROSS JOIN payload
            SQL, [$tenantId, $accountId, $tenantId, $fromDate, $tenantId, $fromDate, $toDate]);
        if ($result === null) {
            throw (new ModelNotFoundException)->setModel('Accounting account');
        }

        return [
            'account' => json_decode((string) $result->account, true, 512, JSON_THROW_ON_ERROR),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => (string) $result->opening_balance,
            'movements' => json_decode((string) $result->movements, true, 512, JSON_THROW_ON_ERROR),
            'closing_balance' => (string) $result->closing_balance,
        ];
    }
}
