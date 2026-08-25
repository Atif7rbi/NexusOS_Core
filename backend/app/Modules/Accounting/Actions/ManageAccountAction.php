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
            $account = DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first();
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
            $at = now();
            DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first() ?? throw new AccountingValidationFailed('Account was not found.');
            $values = $archive ? ['status' => 'archived', 'archived_at' => $at, 'archived_by' => $actor->id, 'restored_at' => null, 'restored_by' => null] : ['status' => 'active', 'archived_at' => null, 'archived_by' => null, 'restored_at' => $at, 'restored_by' => $actor->id];
            DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->update(array_merge($values, ['updated_by' => $actor->id, 'updated_at' => $at]));
            $this->audit->write($tenantId, $archive ? 'account.archived' : 'account.restored', 'account', $accountId, (int) $actor->id, [], $at);
        });
    }
}
