<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Queries\IncomeStatementQuery;
use Illuminate\Support\Facades\DB;

final class AccountingIncomeStatementSecurityTest extends AccountingApiTestCase
{
    public function test_income_statement_requires_active_view_ledger_authority_and_runtime_role_can_read(): void
    {
        $url = '/api/accounting/reports/income-statement?from_date=2026-01-01&to_date=2026-12-31';
        $this->getJson($url)->assertUnauthorized();
        [$tenant, $admin, , $cash] = $this->ready('IS');
        $revenue = $this->account($tenant, $admin, 'ISR4', 'revenue', 'operating_revenue');
        $this->posted($tenant, $admin, $cash, $revenue);
        $this->acting($admin);
        $this->getJson($url)->assertOk();
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $accountant->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($accountant);
        $this->getJson($url)->assertOk();
        $sales = User::factory()->create(['role' => User::ROLE_SALES, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $sales->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($sales);
        $this->getJson($url)->assertForbidden();
        DB::table('tenant_users')->where('user_id', $accountant->id)->update(['status' => TenantUser::STATUS_REMOVED]);
        $this->acting($accountant);
        $this->getJson($url)->assertForbidden();
        $paused = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $paused->id, 'status' => TenantUser::STATUS_PAUSED]);
        $this->acting($paused);
        $this->getJson($url)->assertForbidden();
        $inactive = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ARCHIVED]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $inactive->id, 'status' => TenantUser::STATUS_ACTIVE]);
        $this->acting($inactive);
        $this->getJson($url)->assertForbidden();
        $token = $admin->createToken('income-statement-suspended')->plainTextToken;
        DB::table('users')->where('id', $admin->id)->update(['status' => User::STATUS_SUSPENDED]);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson($url)->assertForbidden();
        DB::statement('SET ROLE "'.getenv('ACCOUNTING_RUNTIME_DB_ROLE').'"');
        try {
            self::assertSame('100.00', app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-12-31')['net_income']);
        } finally {
            DB::statement('RESET ROLE');
        }
    }
}
