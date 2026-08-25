<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivateAccountingAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit) {}

    public function execute(string $tenantId, User $actor): string
    {
        $this->auth->authorize($tenantId, $actor, 'activate');

        return $this->tx->run(function () use ($tenantId, $actor): string {
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if ($tenant === null || $tenant->status !== Tenant::STATUS_ACTIVE || $tenant->currency !== 'SAR') {
                throw new AccountingValidationFailed('Accounting activation requires an active SAR Tenant.');
            }
            $existing = DB::table('accounting_settings')->where('tenant_id', $tenantId)->first();
            if ($existing !== null) {
                return $existing->id;
            }
            $id = (string) Str::ulid();
            $at = now();
            DB::table('accounting_settings')->insert(['id' => $id, 'tenant_id' => $tenantId, 'ledger_currency' => 'SAR', 'activated_by' => $actor->id, 'activated_at' => $at]);
            $this->audit->write($tenantId, 'accounting.activated', 'accounting_settings', $id, (int) $actor->id, ['currency' => 'SAR'], $at);

            return $id;
        });
    }
}
