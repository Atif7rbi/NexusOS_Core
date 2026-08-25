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

final class ManageOpeningBalanceAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit, private readonly PostingEngine $posting) {}

    public function create(string $tenantId, User $actor, string $date, array $lines): string
    {
        $this->auth->authorize($tenantId, $actor, 'manage_opening_balance');

        return $this->tx->run(function () use ($tenantId, $actor, $date, $lines): string {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_opening_balance');
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first() ?? throw new AccountingValidationFailed('Accounting is not active.');
            $operationId = (string) Str::ulid();
            $journalId = (string) Str::ulid();
            $at = now();
            DB::table('journal_entries')->insert(['id' => $journalId, 'tenant_id' => $tenantId, 'entry_date' => $date, 'description' => 'Opening balance', 'status' => 'draft', 'origin' => 'opening_balance', 'source_type' => 'opening_balance_operation', 'source_id' => $operationId, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]);
            DB::table('opening_balance_operations')->insert(['id' => $operationId, 'tenant_id' => $tenantId, 'status' => 'draft', 'accounting_date' => $date, 'journal_entry_id' => $journalId, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]);
            $this->replaceLines($tenantId, $journalId, $lines, $at);
            $this->audit->write($tenantId, 'opening_balance.created', 'opening_balance_operation', $operationId, (int) $actor->id, [], $at);

            return $operationId;
        });
    }

    public function update(string $tenantId, string $operationId, User $actor, string $date, array $lines): void
    {
        $this->auth->authorize($tenantId, $actor, 'manage_opening_balance');
        $this->tx->run(function () use ($tenantId, $operationId, $actor, $date, $lines): void {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_opening_balance');
            $journalId = $this->discoverRootJournalId($tenantId, $operationId);
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Opening Balance root Journal was not found.');
            $op = $this->lockDraft($tenantId, $operationId);
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->update(['entry_date' => $date, 'updated_by' => $actor->id, 'updated_at' => now()]);
            DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $operationId)->update(['accounting_date' => $date, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->replaceLines($tenantId, $op->journal_entry_id, $lines, now());
        });
    }

    public function delete(string $tenantId, string $operationId, User $actor): void
    {
        $this->auth->authorize($tenantId, $actor, 'manage_opening_balance');
        $this->tx->run(function () use ($tenantId, $operationId, $actor): void {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_opening_balance');
            $journalId = $this->discoverRootJournalId($tenantId, $operationId);
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Opening Balance root Journal was not found.');
            $op = $this->lockDraft($tenantId, $operationId);
            $at = now();
            $this->audit->write($tenantId, 'opening_balance.draft_deleted', 'opening_balance_operation', $operationId, (int) $actor->id, [], $at);
            DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $operationId)->delete();
            DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $op->journal_entry_id)->delete();
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $op->journal_entry_id)->delete();
        });
    }

    public function post(string $tenantId, string $operationId, User $actor): PostedJournalResult
    {
        $this->auth->authorize($tenantId, $actor, 'manage_opening_balance');

        return $this->tx->run(function () use ($tenantId, $operationId, $actor): PostedJournalResult {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_opening_balance');
            $journalId = $this->discoverRootJournalId($tenantId, $operationId);
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Opening Balance root Journal was not found.');
            $op = DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $operationId)->lockForUpdate()->first() ?? throw new AccountingValidationFailed('Opening Balance was not found.');
            if ($op->status === 'posted') {
                $j = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $op->journal_entry_id)->first();

                return new PostedJournalResult($j->id, $j->journal_number, true);
            }$result = $this->posting->post($tenantId, $op->journal_entry_id, $actor);
            $at = now();
            DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $operationId)->update(['status' => 'posted', 'effect_state' => 'effective', 'latest_effect_journal_entry_id' => $op->journal_entry_id, 'posted_by' => $actor->id, 'posted_at' => $at, 'effect_updated_by' => $actor->id, 'effect_updated_at' => $at, 'updated_by' => $actor->id, 'updated_at' => $at]);
            $this->audit->write($tenantId, 'opening_balance.posted', 'opening_balance_operation', $operationId, (int) $actor->id, ['journal_entry_id' => $op->journal_entry_id], $at);

            return $result;
        });
    }

    private function lockDraft(string $tenantId, string $operationId): object
    {
        $op = DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $operationId)->lockForUpdate()->first();
        if ($op === null || $op->status !== 'draft') {
            throw new AccountingValidationFailed('Opening Balance draft was not found.');
        }

        return $op;
    }

    private function discoverRootJournalId(string $tenantId, string $operationId): string
    {
        $journalId = DB::table('opening_balance_operations')
            ->where('tenant_id', $tenantId)
            ->where('id', $operationId)
            ->value('journal_entry_id');

        if (! is_string($journalId) || $journalId === '') {
            throw new AccountingValidationFailed('Opening Balance was not found.');
        }

        return $journalId;
    }

    private function replaceLines(string $tenantId, string $journalId, array $lines, \DateTimeInterface $at): void
    {
        DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $journalId)->delete();
        foreach (array_values($lines) as $i => $line) {
            if (! $line instanceof JournalLineData) {
                throw new AccountingValidationFailed('Invalid Opening Balance line.');
            }DB::table('journal_lines')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $journalId, 'line_number' => $i + 1, 'account_id' => $line->accountId, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit, 'memo' => $line->memo, 'created_at' => $at, 'updated_at' => $at]);
        }
    }
}
