<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Services\PostingEngine;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReverseJournalAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit, private readonly PostingEngine $posting) {}

    public function execute(string $tenantId, string $targetId, User $actor, string $entryDate, string $reason): PostedJournalResult
    {
        $this->auth->authorize($tenantId, $actor, 'reverse_journal');
        if (trim($reason) === '') {
            throw new AccountingValidationFailed('Reversal reason is required.');
        }

        return $this->tx->run(function () use ($tenantId, $targetId, $actor, $entryDate, $reason): PostedJournalResult {
            $opening = $this->findOpening($tenantId, $targetId);
            if ($opening !== null) {
                DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            }
            $target = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $targetId)->lockForUpdate()->first();
            if ($target === null || $target->status !== 'posted' || $entryDate < $target->entry_date) {
                throw new AccountingValidationFailed('Only a terminal Posted Journal can be reversed chronologically.');
            }
            if (DB::table('journal_entries')->where('tenant_id', $tenantId)->where('reverses_journal_entry_id', $targetId)->exists()) {
                throw new AccountingConflict('Journal already has a direct reversal.');
            }
            $lines = DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $targetId)->orderBy('line_number')->lockForUpdate()->get();
            $id = (string) Str::ulid();
            $at = now();
            DB::table('journal_entries')->insert(['id' => $id, 'tenant_id' => $tenantId, 'entry_date' => $entryDate, 'description' => 'Reversal: '.$target->description, 'status' => 'draft', 'origin' => 'reversal', 'source_type' => 'journal_entry', 'source_id' => $targetId, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at, 'reverses_journal_entry_id' => $targetId, 'reversal_reason' => $reason]);
            foreach ($lines as $line) {
                DB::table('journal_lines')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $id, 'line_number' => $line->line_number, 'account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'memo' => $line->memo, 'created_at' => $at, 'updated_at' => $at]);
            }
            $result = $this->posting->post($tenantId, $id, $actor);
            $this->audit->write($tenantId, 'journal.reversed', 'journal_entry', $targetId, (int) $actor->id, ['reversal_journal_entry_id' => $id, 'reason' => $reason], $at);
            if ($opening !== null) {
                $state = $opening->effect_state === 'effective' ? 'neutralized' : 'effective';
                DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $opening->id)->lockForUpdate()->update(['effect_state' => $state, 'latest_effect_journal_entry_id' => $id, 'effect_updated_by' => $actor->id, 'effect_updated_at' => $at, 'updated_by' => $actor->id, 'updated_at' => $at]);
                $this->audit->write($tenantId, $state === 'effective' ? 'opening_balance.reactivated' : 'opening_balance.reversed', 'opening_balance_operation', $opening->id, (int) $actor->id, ['journal_entry_id' => $id], $at);
            }

            return $result;
        });
    }

    private function findOpening(string $tenantId, string $journalId): ?object
    {
        return DB::selectOne('WITH RECURSIVE a AS (SELECT id,reverses_journal_entry_id FROM journal_entries WHERE tenant_id=? AND id=? UNION ALL SELECT j.id,j.reverses_journal_entry_id FROM journal_entries j JOIN a ON a.reverses_journal_entry_id=j.id WHERE j.tenant_id=?) SELECT o.* FROM a JOIN opening_balance_operations o ON o.tenant_id=? AND o.journal_entry_id=a.id LIMIT 1',[$tenantId, $journalId, $tenantId, $tenantId]);
    }
}
