<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Queries\TrialBalanceQuery;
use Illuminate\Support\Facades\DB;

final class AccountingTrialBalanceSecurityTest extends AccountingApiTestCase
{
    public function test_only_active_administrator_or_accountant_members_can_view_trial_balance(): void
    {
        [$tenant, $administrator] = $this->accountingActor();
        $this->activate($tenant, $administrator);
        $cash = $this->account($tenant, $administrator, 'TB1', 'asset', 'current_asset');
        $equity = $this->account($tenant, $administrator, 'TB3', 'equity', 'equity');
        $this->period($tenant, $administrator);
        $this->posted($tenant, $administrator, $cash, $equity);

        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $accountant->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($accountant);
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31')->assertOk();

        $sales = User::factory()->create(['role' => User::ROLE_SALES, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $sales->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($sales);
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31')->assertForbidden();

        DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $accountant->id)->update(['status' => TenantUser::STATUS_REMOVED]);
        $this->acting($accountant);
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31')->assertForbidden();
    }

    public function test_runtime_role_can_read_trial_balance_without_new_write_privileges(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('RT');
        $this->posted($tenant, $actor, $cash, $equity);
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        DB::statement('SET ROLE "'.$role.'"');
        try {
            $result = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-12-31');
            self::assertSame('100.00', $result['debit_total']);
            self::assertTrue($result['is_balanced']);
        } finally {
            DB::statement('RESET ROLE');
        }
    }
}
