<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final class AccountingApiBoundaryTest extends AccountingApiTestCase
{
    public function test_accountant_can_use_accounting_commands_while_ordinary_role_cannot_read_or_mutate(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->activate($tenant, $admin);
        $this->period($tenant, $admin);
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $accountant->id]);
        $this->acting($accountant);

        $asset = $this->postJson('/api/accounting/accounts', ['code' => 'Q100', 'name' => 'Asset', 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null])
            ->assertCreated()->json('data.account.id');
        $equity = $this->postJson('/api/accounting/accounts', ['code' => 'Q300', 'name' => 'Equity', 'kind' => 'posting', 'account_type' => 'equity', 'classification' => 'equity', 'parent_id' => null])
            ->assertCreated()->json('data.account.id');
        $journal = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-03-01', 'description' => 'Accountant', 'lines' => $this->linePayload($asset, $equity)])
            ->assertCreated()->json('data.journal.id');
        $this->postJson("/api/accounting/journals/{$journal}/post")->assertOk();

        [, $ordinary] = $this->accountingActor(User::ROLE_EMPLOYEE);
        $this->acting($ordinary);
        $this->getJson('/api/accounting/accounts')->assertForbidden();
        $this->postJson('/api/accounting/journals', ['entry_date' => '2026-03-01', 'description' => 'Denied'])->assertForbidden();
    }

    public function test_tenant_id_is_never_accepted_as_request_authority(): void
    {
        [$tenantA, $adminA] = $this->accountingActor();
        [$tenantB, $adminB] = $this->accountingActor();
        $this->activate($tenantA, $adminA);
        $this->activate($tenantB, $adminB);
        $this->acting($adminA);

        $this->postJson('/api/accounting/accounts', [
            'tenant_id' => (string) $tenantB->id, 'code' => 'Z100', 'name' => 'Spoof', 'kind' => 'posting',
            'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
    }

    public function test_phase_three_exposes_no_public_business_posting_or_reporting_routes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->filter(fn (string $uri): bool => str_starts_with($uri, 'api/accounting'))->values()->all();

        self::assertNotEmpty($routes);
        self::assertFalse(collect($routes)->contains(fn (string $uri): bool => str_contains($uri, 'business-post')));
        self::assertFalse(collect($routes)->contains(fn (string $uri): bool => str_contains($uri, 'trial-balance') || str_contains($uri, 'balance-sheet') || str_contains($uri, 'income-statement')));
    }

    public function test_controllers_contain_no_database_mutation_or_transaction_ownership(): void
    {
        foreach (glob(app_path('Modules/Accounting/Controllers/*.php')) ?: [] as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('DB::', $source, $path);
            self::assertStringNotContainsString('transaction(', $source, $path);
        }
    }
}
