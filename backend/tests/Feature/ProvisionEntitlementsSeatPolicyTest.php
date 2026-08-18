<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
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
        $tenant = Tenant::query()->create([
            'name' => 'Seat Policy Tenant',
            'slug' => 'seat-policy-tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
        ]);

        $this->artisan('nexusos:provision-entitlements', [
            '--tenant' => (string) $tenant->id,
            '--plan' => CommercialModuleCatalog::PILOT_FULL_PLAN,
            '--status' => TenantLicense::STATUS_ACTIVE,
            '--starts-at' => '2026-08-01T00:00:00Z',
            '--ends-at' => '2026-11-30T23:59:59Z',
        ])->assertSuccessful();

        $this->assertSame(
            5,
            Plan::query()
                ->where('key', CommercialModuleCatalog::PILOT_FULL_PLAN)
                ->value('users_limit'),
        );
    }
}
