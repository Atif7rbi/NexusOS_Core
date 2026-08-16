<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Modules\Entitlements\Services\ResolveEffectiveTenantModules;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ProvisionEntitlementsCommandTest extends ApiTestCase
{
    private const STARTS_AT = '2026-08-01T00:00:00Z';

    private const ENDS_AT = '2027-08-01T00:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15T09:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_command_creates_catalog_plan_license_and_verifies_modules(): void
    {
        $tenant = $this->tenantWithoutEntitlements();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant))
            ->assertSuccessful();

        $this->assertSame(7, Module::query()->count());
        $plan = Plan::query()->where('key', CommercialModuleCatalog::PILOT_FULL_PLAN)->firstOrFail();
        $this->assertSame(7, $plan->modules()->count());
        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => TenantLicense::STATUS_ACTIVE,
        ]);
        $this->assertSame(
            app(CommercialModuleCatalog::class)->keys(),
            $this->orderedByCatalog(
                app(ResolveEffectiveTenantModules::class)->handle((string) $tenant->id),
            ),
        );
    }

    public function test_missing_and_invalid_period_arguments_are_rejected(): void
    {
        $tenant = $this->tenantWithoutEntitlements();

        $this->artisan('nexusos:provision-entitlements', [
            '--tenant' => (string) $tenant->id,
        ])->assertFailed();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--starts-at' => self::ENDS_AT,
            '--ends-at' => self::STARTS_AT,
        ]))->assertFailed();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--starts-at' => '2026-08-01 00:00:00',
        ]))->assertFailed();
    }

    public function test_grace_period_contract_is_enforced(): void
    {
        $tenant = $this->tenantWithoutEntitlements();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--status' => TenantLicense::STATUS_GRACE,
        ]))->assertFailed();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--status' => TenantLicense::STATUS_GRACE,
            '--grace-ends-at' => '2026-07-01T00:00:00Z',
        ]))->assertFailed();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--grace-ends-at' => '2027-09-01T00:00:00Z',
        ]))->assertFailed();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--status' => TenantLicense::STATUS_GRACE,
            '--ends-at' => '2026-08-10T00:00:00Z',
            '--grace-ends-at' => '2026-09-01T00:00:00Z',
        ]))->assertSuccessful();

        $this->assertCount(
            7,
            app(ResolveEffectiveTenantModules::class)->handle((string) $tenant->id),
        );
    }

    public function test_exact_rerun_is_idempotent_and_conflicting_license_fails(): void
    {
        $tenant = $this->tenantWithoutEntitlements();
        $options = $this->provisioningOptions($tenant);

        $this->artisan('nexusos:provision-entitlements', $options)
            ->assertSuccessful();
        $this->artisan('nexusos:provision-entitlements', $options)
            ->assertSuccessful()
            ->expectsOutputToContain('already exist');

        $this->assertSame(1, TenantLicense::query()->where('tenant_id', $tenant->id)->count());

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant, [
            '--ends-at' => '2028-08-01T00:00:00Z',
        ]))
            ->assertFailed()
            ->expectsOutputToContain('overlapping entitled license');
    }

    public function test_historical_time_expired_license_does_not_block_a_later_period(): void
    {
        $tenant = $this->tenantWithoutEntitlements();

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant))
            ->assertSuccessful();

        $historical = TenantLicense::query()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $historical->update([
            'starts_at' => CarbonImmutable::parse('2025-01-01T00:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-07-31T23:59:59Z'),
        ]);

        $this->artisan('nexusos:provision-entitlements', $this->provisioningOptions($tenant))
            ->assertSuccessful();

        $this->assertDatabaseHas('tenant_licenses', [
            'id' => $historical->id,
            'tenant_id' => $tenant->id,
            'status' => TenantLicense::STATUS_ACTIVE,
        ]);
        $historical->refresh();
        $this->assertTrue(
            $historical->starts_at->equalTo(CarbonImmutable::parse('2025-01-01T00:00:00Z')),
        );
        $this->assertTrue(
            $historical->ends_at->equalTo(CarbonImmutable::parse('2026-07-31T23:59:59Z')),
        );
        $this->assertSame(2, TenantLicense::query()->where('tenant_id', $tenant->id)->count());
        $this->assertCount(
            7,
            app(ResolveEffectiveTenantModules::class)->handle((string) $tenant->id),
        );
    }

    public function test_unknown_tenant_fails_even_when_demo_mode_is_enabled(): void
    {
        config()->set('nexusos.demo_mode', true);

        $this->artisan('nexusos:provision-entitlements', [
            '--tenant' => 'demo@example.test',
            '--plan' => CommercialModuleCatalog::PILOT_FULL_PLAN,
            '--status' => TenantLicense::STATUS_ACTIVE,
            '--starts-at' => self::STARTS_AT,
            '--ends-at' => self::ENDS_AT,
        ])
            ->assertFailed()
            ->expectsOutputToContain('was not found');

        $this->assertDatabaseCount('tenant_licenses', 0);
    }

    private function tenantWithoutEntitlements(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::lower(Str::random(8)),
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
        ]);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function provisioningOptions(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            '--tenant' => (string) $tenant->id,
            '--plan' => CommercialModuleCatalog::PILOT_FULL_PLAN,
            '--status' => TenantLicense::STATUS_ACTIVE,
            '--starts-at' => self::STARTS_AT,
            '--ends-at' => self::ENDS_AT,
        ], $overrides);
    }

    /** @param list<string> $modules */
    private function orderedByCatalog(array $modules): array
    {
        $positions = array_flip(app(CommercialModuleCatalog::class)->keys());
        usort($modules, static fn (string $left, string $right): int => $positions[$left] <=> $positions[$right]);

        return $modules;
    }
}
