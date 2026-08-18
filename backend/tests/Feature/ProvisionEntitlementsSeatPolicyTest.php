<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Modules\Entitlements\Services\ResolveTenantUserLimit;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;

final class ProvisionEntitlementsSeatPolicyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-18T06:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pilot_full_provisioning_persists_five_user_limit(): void
    {
        $tenant = $this->createTenant('seat-policy-tenant');

        $this->provision($tenant, CommercialModuleCatalog::PILOT_FULL_PLAN)
            ->assertSuccessful();

        $this->assertSame(
            5,
            Plan::query()
                ->where('key', CommercialModuleCatalog::PILOT_FULL_PLAN)
                ->value('users_limit'),
        );

        $this->assertSame(
            ['available' => true, 'limit' => 5],
            app(ResolveTenantUserLimit::class)->handle((string) $tenant->id),
        );
    }

    public function test_demo_full_provisioning_is_unlimited(): void
    {
        $tenant = $this->createTenant('demo-seat-policy-tenant');

        $this->provision($tenant, CommercialModuleCatalog::DEMO_FULL_PLAN)
            ->assertSuccessful();

        $this->assertNull(
            Plan::query()
                ->where('key', CommercialModuleCatalog::DEMO_FULL_PLAN)
                ->value('users_limit'),
        );

        $this->assertSame(
            ['available' => true, 'limit' => null],
            app(ResolveTenantUserLimit::class)->handle((string) $tenant->id),
        );
    }

    public function test_license_override_takes_precedence_over_plan_limit(): void
    {
        $tenant = $this->createTenant('override-seat-policy-tenant');

        $this->provision(
            $tenant,
            CommercialModuleCatalog::PILOT_FULL_PLAN,
            8,
        )->assertSuccessful();

        $license = TenantLicense::query()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $this->assertSame(8, $license->users_limit_override);
        $this->assertSame(
            ['available' => true, 'limit' => 8],
            app(ResolveTenantUserLimit::class)->handle((string) $tenant->id),
        );
    }

    public function test_users_limit_override_must_be_positive(): void
    {
        $tenant = $this->createTenant('invalid-override-seat-policy-tenant');

        $this->provision(
            $tenant,
            CommercialModuleCatalog::PILOT_FULL_PLAN,
            0,
        )->assertFailed();

        $this->assertDatabaseMissing('tenant_licenses', [
            'tenant_id' => $tenant->id,
        ]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Seat Policy Tenant',
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
        ]);
    }

    private function provision(
        Tenant $tenant,
        string $plan,
        ?int $usersLimitOverride = null,
    ) {
        $arguments = [
            '--tenant' => (string) $tenant->id,
            '--plan' => $plan,
            '--status' => TenantLicense::STATUS_ACTIVE,
            '--starts-at' => '2026-08-01T00:00:00Z',
            '--ends-at' => '2026-11-30T23:59:59Z',
        ];

        if ($usersLimitOverride !== null) {
            $arguments['--users-limit-override'] = (string) $usersLimitOverride;
        }

        return $this->artisan('nexusos:provision-entitlements', $arguments);
    }
}
