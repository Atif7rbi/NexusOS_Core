<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageAccountAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit) {}

    public function create(string $tenantId, User $actor, array $data): string
    {
        $this->auth->authorize($tenantId, $actor, 'manage_chart');

        return $this->tx->run(function () use ($tenantId, $actor, $data): string {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_chart');
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            if (($data['parent_id'] ?? null) !== null) {
                DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $data['parent_id'])->lockForUpdate()->first()
                    ?? throw new AccountingValidationFailed('Parent Account was not found.');
            }
            $id = (string) Str::ulid();
            $at = now();
            DB::table('accounts')->insert(array_merge($data, ['id' => $id, 'tenant_id' => $tenantId, 'status' => 'active', 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]));
            $this->audit->write($tenantId, 'account.created', 'account', $id, (int) $actor->id, [], $at);

            return $id;
        });
    }

    public function update(string $tenantId, string $accountId, User $actor, array $changes): void
    {
        $this->auth->authorize($tenantId, $actor, 'manage_chart');
        $allowed = ['code', 'name', 'description', 'kind', 'account_type', 'classification', 'parent_id'];
        $changes = array_intersect_key($changes, array_flip($allowed));
        $this->tx->run(function () use ($tenantId, $accountId, $actor, $changes): void {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_chart');
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            $account = $this->lockAffectedAccounts($tenantId, $accountId, isset($changes['parent_id']) ? (string) $changes['parent_id'] : null);
            if ($account === null) {
                throw new AccountingValidationFailed('Account was not found.');
            }
            DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->update(array_merge($changes, ['updated_by' => $actor->id, 'updated_at' => now()]));
            $this->audit->write($tenantId, 'account.updated', 'account', $accountId, (int) $actor->id, ['fields' => array_keys($changes)], now());
        });
    }

    public function archive(string $tenantId, string $accountId, User $actor): void
    {
        $this->lifecycle($tenantId, $accountId, $actor, true);
    }

    public function restore(string $tenantId, string $accountId, User $actor): void
    {
        $this->lifecycle($tenantId, $accountId, $actor, false);
    }

    private function lifecycle(string $tenantId, string $accountId, User $actor, bool $archive): void
    {
        $this->auth->authorize($tenantId, $actor, 'manage_chart');
        $this->tx->run(function () use ($tenantId, $accountId, $actor, $archive): void {
            $this->auth->authorizeTransactional($tenantId, $actor, 'manage_chart');
            DB::table('accounting_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first()
                ?? throw new AccountingValidationFailed('Accounting is not active.');
            $at = now();
            $this->lockAffectedAccounts($tenantId, $accountId);
            $values = $archive ? ['status' => 'archived', 'archived_at' => $at, 'archived_by' => $actor->id, 'restored_at' => null, 'restored_by' => null] : ['status' => 'active', 'archived_at' => null, 'archived_by' => null, 'restored_at' => $at, 'restored_by' => $actor->id];
            DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->update(array_merge($values, ['updated_by' => $actor->id, 'updated_at' => $at]));
            $this->audit->write($tenantId, $archive ? 'account.archived' : 'account.restored', 'account', $accountId, (int) $actor->id, [], $at);
        });
    }

    private function lockAffectedAccounts(string $tenantId, string $accountId, ?string $newParentId = null): object
    {
        $rows = DB::select(
            <<<'SQL'
                WITH RECURSIVE subtree(id) AS (
                  SELECT id FROM accounts WHERE tenant_id=? AND id=?
                  UNION ALL
                  SELECT child.id FROM accounts child JOIN subtree parent ON child.parent_id=parent.id
                  WHERE child.tenant_id=?
                )
                SELECT a.* FROM accounts a
                WHERE a.tenant_id=?
                  AND (a.id IN (SELECT id FROM subtree) OR a.id=(SELECT parent_id FROM accounts WHERE tenant_id=? AND id=?) OR a.id=?)
                ORDER BY a.id
                FOR UPDATE OF a
                SQL,
            [$tenantId, $accountId, $tenantId, $tenantId, $tenantId, $accountId, $newParentId],
        );
        foreach ($rows as $row) {
            if ($row->id === $accountId) {
                return $row;
            }
        }

        throw new AccountingValidationFailed('Account was not found.');
    }
}
