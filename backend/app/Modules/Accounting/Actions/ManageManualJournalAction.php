<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Services\PostingEngine;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageManualJournalAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit, private readonly PostingEngine $posting) {}

    public function create(string $tenantId, User $actor, string $entryDate, string $description, array $lines = []): string
    {
        $this->auth->authorize($tenantId, $actor, 'create_manual_draft');

        return $this->tx->run(function () use ($tenantId, $actor, $entryDate, $description, $lines): string {
            $id = (string) Str::ulid();
            $at = now();
            DB::table('journal_entries')->insert(['id' => $id, 'tenant_id' => $tenantId, 'entry_date' => $entryDate, 'description' => $description, 'status' => 'draft', 'origin' => 'manual', 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]);
            $this->replaceLines($tenantId, $id, $lines, $at);
            $this->audit->write($tenantId, 'journal.draft_created', 'journal_entry', $id, (int) $actor->id, [], $at);

            return $id;
        });
    }

    public function update(string $tenantId, string $journalId, User $actor, string $entryDate, string $description, array $lines): void
    {
        $this->auth->authorize($tenantId, $actor, 'edit_manual_draft');
        $this->tx->run(function () use ($tenantId, $journalId, $actor, $entryDate, $description, $lines): void {
            $j = $this->lockDraft($tenantId, $journalId);
            $at = now();
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->update(['entry_date' => $entryDate, 'description' => $description, 'updated_by' => $actor->id, 'updated_at' => $at]);
            $this->replaceLines($tenantId, $journalId, $lines, $at);
        });
    }

    public function delete(string $tenantId, string $journalId, User $actor): void
    {
        $this->auth->authorize($tenantId, $actor, 'edit_manual_draft');
        $this->tx->run(function () use ($tenantId, $journalId, $actor): void {
            $this->lockDraft($tenantId, $journalId);
            $at = now();
            $this->audit->write($tenantId, 'journal.draft_deleted', 'journal_entry', $journalId, (int) $actor->id, [], $at);
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->delete();
        });
    }

    public function post(string $tenantId, string $journalId, User $actor): PostedJournalResult
    {
        $this->auth->authorize($tenantId, $actor, 'post_journal');

        return $this->tx->run(fn () => $this->posting->post($tenantId, $journalId, $actor));
    }

    private function lockDraft(string $tenantId, string $journalId): object
    {
        $j = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first();
        if ($j === null || $j->origin !== 'manual' || $j->status !== 'draft') {
            throw new AccountingValidationFailed('Manual draft was not found.');
        }

        return $j;
    }

    private function replaceLines(string $tenantId, string $journalId, array $lines, \DateTimeInterface $at): void
    {
        DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $journalId)->delete();
        foreach (array_values($lines) as $index => $line) {
            if (! $line instanceof JournalLineData) {
                throw new AccountingValidationFailed('Journal lines must be JournalLineData.');
            }DB::table('journal_lines')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $journalId, 'line_number' => $index + 1, 'account_id' => $line->accountId, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit, 'memo' => $line->memo, 'created_at' => $at, 'updated_at' => $at]);
        }
    }
}
