<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use Illuminate\Support\Facades\DB;

final class AccountingGeneralLedgerSecurityTest extends AccountingApiTestCase
{
    public function test_general_ledger_enforces_tenant_account_and_actor_boundaries(): void
    {
        [$tenant, $administrator, , $cash, $equity] = $this->ready('GS');
        $this->posted($tenant, $administrator, $cash, $equity);
        $url = "/api/accounting/reports/general-ledger/{$cash}?from_date=2026-01-01&to_date=2026-12-31";

        $this->getJson($url)->assertUnauthorized();

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

        $token = $administrator->createToken('general-ledger-suspended-user')->plainTextToken;
        DB::table('users')->where('id', $administrator->id)->update(['status' => User::STATUS_SUSPENDED]);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson($url)->assertForbidden();

        [, $otherAdministrator, , $foreign] = $this->ready('GX');
        $this->acting($otherAdministrator);
        $this->getJson("/api/accounting/reports/general-ledger/{$cash}?from_date=2026-01-01&to_date=2026-12-31")->assertNotFound();
        $this->getJson("/api/accounting/reports/general-ledger/{$foreign}?from_date=2026-01-01&to_date=2026-12-31")->assertOk();
    }

    public function test_general_ledger_rejects_group_accounts_and_runs_under_runtime_role(): void
    {
        [$tenant, $administrator, , $cash, $equity] = $this->ready('GR');
        $this->posted($tenant, $administrator, $cash, $equity);
        $group = app(ManageAccountAction::class)->create((string) $tenant->id, $administrator, [
            'code' => 'GR0',
            'name' => 'General Ledger Group',
            'description' => null,
            'kind' => 'group',
            'account_type' => 'asset',
            'classification' => null,
            'parent_id' => null,
        ]);

        $this->acting($administrator);
        $this->getJson("/api/accounting/reports/general-ledger/{$group}?from_date=2026-01-01&to_date=2026-12-31")->assertNotFound();

        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        DB::statement('SET ROLE "'.$role.'"');
        try {
            self::assertSame('100.00', app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-01-01', '2026-12-31')['closing_balance']);
        } finally {
            DB::statement('RESET ROLE');
        }
    }
}
