<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

final class AccountingSettingsAndAccountsApiTest extends AccountingApiTestCase
{
    public function test_accounting_routes_require_authentication(): void
    {
        $this->getJson('/api/accounting/settings')->assertUnauthorized();
        $this->postJson('/api/accounting/activation')->assertUnauthorized();
        $this->getJson('/api/accounting/accounts')->assertUnauthorized();
    }

    public function test_activation_is_admin_only_non_idempotent_by_default_and_explicitly_replayable(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->acting($admin);

        $this->postJson('/api/accounting/activation')->assertCreated()
            ->assertJsonPath('data.settings.tenant_id', (string) $tenant->id)
            ->assertJsonPath('data.settings.ledger_currency', 'SAR');
        $this->postJson('/api/accounting/activation')->assertConflict()
            ->assertJsonPath('error.code', 'accounting_conflict');
        $this->postJson('/api/accounting/activation', ['idempotent' => true])->assertCreated()
            ->assertJsonPath('data.settings.ledger_currency', 'SAR');
        self::assertSame(1, DB::table('accounting_settings')->where('tenant_id', $tenant->id)->count());
    }

    public function test_activation_rejects_non_sar_inactive_membership_and_non_admin_roles(): void
    {
        [$usd, $admin] = $this->accountingActor(tenantAttributes: ['currency' => 'USD']);
        $this->acting($admin);
        $this->postJson('/api/accounting/activation')->assertUnprocessable();
        self::assertFalse(DB::table('accounting_settings')->where('tenant_id', $usd->id)->exists());

        [, $paused] = $this->accountingActor(membershipStatus: TenantUser::STATUS_PAUSED);
        $this->acting($paused);
        $this->postJson('/api/accounting/activation')->assertForbidden();

        [, $inactiveTenantAdmin] = $this->accountingActor(tenantAttributes: ['status' => \App\Models\Tenant::STATUS_PAUSED]);
        $this->acting($inactiveTenantAdmin);
        $this->postJson('/api/accounting/activation')->assertForbidden();

        [, $accountant] = $this->accountingActor(User::ROLE_ACCOUNTANT);
        $this->acting($accountant);
        $this->postJson('/api/accounting/activation')->assertForbidden();

        [, $ordinary] = $this->accountingActor(User::ROLE_SALES);
        $this->acting($ordinary);
        $this->postJson('/api/accounting/activation')->assertForbidden();
    }

    public function test_system_owner_without_same_tenant_membership_is_rejected(): void
    {
        $systemOwner = User::factory()->create(['role' => User::ROLE_SYSTEM_OWNER, 'status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($systemOwner);

        $this->getJson('/api/accounting/accounts')->assertForbidden();
    }

    public function test_settings_read_returns_404_before_activation_and_frozen_activation_metadata_afterward(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->acting($admin);
        $this->getJson('/api/accounting/settings')->assertNotFound();
        $this->activate($tenant, $admin);
        $this->getJson('/api/accounting/settings')->assertOk()
            ->assertJsonPath('data.settings.tenant_id', (string) $tenant->id)
            ->assertJsonPath('data.settings.ledger_currency', 'SAR');
    }

    public function test_accounts_api_supports_create_list_show_update_archive_and_restore(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->activate($tenant, $admin);
        $this->acting($admin);

        $id = $this->postJson('/api/accounting/accounts', [
            'code' => '1100', 'name' => 'Cash', 'description' => 'Cash account', 'kind' => 'posting',
            'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null,
        ])->assertCreated()->assertJsonPath('data.account.status', 'active')->json('data.account.id');

        $this->getJson('/api/accounting/accounts?status=active&account_type=asset&search=Cash')->assertOk()
            ->assertJsonPath('data.accounts.data.0.id', $id);
        $this->getJson("/api/accounting/accounts/{$id}")->assertOk()->assertJsonPath('data.account.code', '1100');
        $this->patchJson("/api/accounting/accounts/{$id}", ['name' => 'Main Cash'])->assertOk()->assertJsonPath('data.account.name', 'Main Cash');
        $this->postJson("/api/accounting/accounts/{$id}/archive")->assertOk()->assertJsonPath('data.account.status', 'archived');
        $this->postJson("/api/accounting/accounts/{$id}/restore")->assertOk()->assertJsonPath('data.account.status', 'active');
    }

    public function test_account_validation_parent_integrity_and_tenant_isolation_are_enforced_without_database_leaks(): void
    {
        [$tenantA, $adminA] = $this->accountingActor();
        [$tenantB, $adminB] = $this->accountingActor();
        $this->activate($tenantA, $adminA);
        $this->activate($tenantB, $adminB);
        $foreign = $this->account($tenantB, $adminB, 'B100', 'asset', 'current_asset');
        $this->acting($adminA);

        $this->getJson("/api/accounting/accounts/{$foreign}")->assertNotFound();
        $this->patchJson("/api/accounting/accounts/{$foreign}", ['name' => 'Leak'])->assertNotFound();
        $response = $this->postJson('/api/accounting/accounts', [
            'code' => 'A100', 'name' => 'Invalid', 'kind' => 'posting', 'account_type' => 'asset',
            'classification' => 'operating_revenue', 'parent_id' => null,
        ])->assertUnprocessable();
        self::assertStringNotContainsString('SQLSTATE', $response->getContent());
        self::assertStringNotContainsString('accounts_type_classification_check', $response->getContent());

        $this->postJson('/api/accounting/accounts', [
            'code' => 'A200', 'name' => 'Foreign parent', 'kind' => 'posting', 'account_type' => 'asset',
            'classification' => 'current_asset', 'parent_id' => $foreign,
        ])->assertUnprocessable();
    }

    public function test_posted_descendant_history_rejects_structural_account_change_and_ordinary_role_is_forbidden(): void
    {
        [$tenant, $admin, , $debit, $credit] = $this->ready('H');
        $posted = $this->posted($tenant, $admin, $debit, $credit);
        $this->acting($admin);
        $this->patchJson("/api/accounting/accounts/{$debit}", ['code' => 'H9'])->assertUnprocessable();
        self::assertSame('posted', DB::table('journal_entries')->where('id', $posted->journalEntryId)->value('status'));

        [, $ordinary] = $this->accountingActor(User::ROLE_EMPLOYEE);
        $this->acting($ordinary);
        $this->getJson('/api/accounting/accounts')->assertForbidden();
    }
}
