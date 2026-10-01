<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountingFinancialStatementClassificationSecurityTest extends AccountingApiTestCase
{
    public function test_catalog_requires_active_ledger_authority_without_tenant_override(): void
    {
        $url = '/api/accounting/reports/classifications';
        $this->getJson($url)->assertUnauthorized();

        [$tenant, $administrator] = $this->ready('FS');
        $this->acting($administrator);
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

        $token = $administrator->createToken('classification-suspended-user')->plainTextToken;
        DB::table('users')->where('id', $administrator->id)->update(['status' => User::STATUS_SUSPENDED]);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson($url)->assertForbidden();
    }
}
