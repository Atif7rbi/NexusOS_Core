<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageCashFlowSemanticsAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit) {}

    public function assignCashRole(string $tenantId, string $accountId, string $role, string $operationId, User $actor): string
    {
        $this->auth->authorize($tenantId, $actor, 'manage_chart');
        $this->validateRole($role);

        return $this->tx->run(function () use ($tenantId, $accountId, $role, $operationId, $actor): string {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_chart');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenantId.'|'.$operationId]);
            $existing = DB::table('account_cash_roles')->where('tenant_id', $tenantId)->where('assignment_operation_id', $operationId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->account_id !== $accountId || $existing->role !== $role) {
                    throw new AccountingConflict('Cash role operation conflicts.');
                }

                return $existing->id;
            }
            $id = (string) Str::ulid();
            $at = now();
            DB::table('account_cash_roles')->insert(['id' => $id, 'tenant_id' => $tenantId, 'account_id' => $accountId, 'role' => $role, 'assignment_operation_id' => $operationId, 'assigned_by' => $actor->id, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
            $this->audit->write($tenantId, 'cash_flow.cash_role_assigned', 'account', $accountId, (int) $actor->id, ['cash_role_id' => $id, 'role' => $role, 'assignment_operation_id' => $operationId], $at);

            return $id;
        });
    }

    public function setDraftSemantic(string $tenantId, string $journalId, ?string $activity, string $operationId, User $actor): void
    {
        $this->auth->authorize($tenantId, $actor, 'edit_manual_draft');
        $this->validateActivity($activity);
        $this->tx->run(function () use ($tenantId, $journalId, $activity, $operationId, $actor): void {
            $this->auth->authorizeTransactional($tenantId, $actor, 'edit_manual_draft');
            $journal = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first();
            if ($journal === null || $journal->status !== 'draft') {
                throw new AccountingValidationFailed('Cash flow semantic requires a draft Journal.');
            }
            if ($activity === null) {
                DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenantId)->where('journal_entry_id', $journalId)->delete();

                return;
            }
            $at = now();
            DB::table('journal_cash_flow_semantics')->updateOrInsert(['tenant_id' => $tenantId, 'journal_entry_id' => $journalId], ['id' => (string) Str::ulid(), 'activity' => $activity, 'semantic_operation_id' => $operationId, 'assigned_by' => $actor->id, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        });
    }

    public function adoptHistoricalSemantic(string $tenantId, string $journalId, string $activity, string $operationId, User $actor): string
    {
        $this->auth->authorize($tenantId, $actor, 'manage_chart');
        $this->validateActivity($activity);

        return $this->tx->run(function () use ($tenantId, $journalId, $activity, $operationId, $actor): string {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_chart');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenantId.'|'.$operationId]);
            $operation = DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenantId)->where('semantic_operation_id', $operationId)->lockForUpdate()->first();
            if ($operation !== null) {
                if ($operation->journal_entry_id !== $journalId || $operation->activity !== $activity) {
                    throw new AccountingConflict('Cash flow semantic operation conflicts.');
                }

                return $operation->id;
            }
            $journal = DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $journalId)->lockForUpdate()->first();
            if ($journal === null || $journal->status !== 'posted') {
                throw new AccountingValidationFailed('Cash flow adoption requires a Posted Journal.');
            }
            $existing = DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenantId)->where('journal_entry_id', $journalId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->activity !== $activity || $existing->semantic_operation_id !== $operationId) {
                    throw new AccountingConflict('Cash flow semantic already exists.');
                }

                return $existing->id;
            }
            $cashDelta = DB::scalar('SELECT COALESCE(SUM(line.debit-line.credit),0) FROM journal_lines line JOIN account_cash_roles role ON role.tenant_id=line.tenant_id AND role.account_id=line.account_id WHERE line.tenant_id=? AND line.journal_entry_id=?', [$tenantId, $journalId]);
            if ((string) $cashDelta === '0' || (string) $cashDelta === '0.00') {
                throw new AccountingValidationFailed('Cash flow adoption requires a non-zero cash delta.');
            }
            $id = (string) Str::ulid();
            $at = now();
            DB::table('journal_cash_flow_semantics')->insert(['id' => $id, 'tenant_id' => $tenantId, 'journal_entry_id' => $journalId, 'activity' => $activity, 'semantic_operation_id' => $operationId, 'assigned_by' => $actor->id, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
            $this->audit->write($tenantId, 'cash_flow.historical_semantic_adopted', 'journal_entry', $journalId, (int) $actor->id, ['cash_flow_semantic_id' => $id, 'activity' => $activity, 'semantic_operation_id' => $operationId], $at);

            return $id;
        });
    }

    private function validateRole(string $role): void
    {
        if (! in_array($role, ['cash', 'cash_equivalent'], true)) {
            throw new AccountingValidationFailed('Cash flow role is invalid.');
        }
    }

    private function validateActivity(?string $activity): void
    {
        if ($activity !== null && ! in_array($activity, ['operating', 'investing', 'financing'], true)) {
            throw new AccountingValidationFailed('Cash flow activity is invalid.');
        }
    }
}
