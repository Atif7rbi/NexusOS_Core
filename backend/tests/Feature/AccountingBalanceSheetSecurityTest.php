<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Queries\BalanceSheetQuery;
use Illuminate\Support\Facades\DB;

final class AccountingBalanceSheetSecurityTest extends AccountingApiTestCase
{
    public function test_balance_sheet_requires_active_ledger_authority_and_runtime_role_can_read(): void
    {
        [$tenant, $admin] = $this->ready('BX');
        $url = '/api/accounting/reports/balance-sheet?as_of_date=2026-01-01';
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $accountant->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($admin);
        $this->getJson($url)->assertOk();
        $this->acting($accountant);
        $this->getJson($url)->assertOk();
        $sales = User::factory()->create(['role' => User::ROLE_SALES, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $sales->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($sales);
        $this->getJson($url)->assertForbidden();
        DB::table('tenant_users')->where('user_id', $accountant->id)->update(['status' => TenantUser::STATUS_REMOVED]);
        $this->acting($accountant);
        $this->getJson($url)->assertForbidden();
        $suspended = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_SUSPENDED]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $suspended->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($suspended);
        $this->getJson($url)->assertForbidden();
        $archived = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ARCHIVED]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $archived->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($archived);
        $this->getJson($url)->assertForbidden();
        DB::table('tenant_users')->where('user_id', $accountant->id)->update(['status' => TenantUser::STATUS_PAUSED]);
        $this->acting($accountant);
        $this->getJson($url)->assertForbidden();
        DB::statement('SET ROLE "'.getenv('ACCOUNTING_RUNTIME_DB_ROLE').'"');
        try {
            self::assertSame('0.00', app(BalanceSheetQuery::class)->execute((string) $tenant->id, '2026-01-01')['assets']);
        } finally {
            DB::statement('RESET ROLE');
        }
    }
}
