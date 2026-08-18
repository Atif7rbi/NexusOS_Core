<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Modules\Entitlements\Services\ResolveTenantUserLimit;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;

final class TenantUserLimitOverrideCommandTest extends ApiTestCase
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

    public function test_current_license_override_can_be_set_and_cleared(): void
    {
        $tenant = $this->tenant('seat-override-command');

        $this->artisan('nexusos:provision-entitlements', [
            '--tenant' => (string) $tenant->id,
            '--plan' => CommercialModuleCatalog::PILOT_FULL_PLAN,
            '--status' => TenantLicense::STATUS_ACTIVE,
            '--starts-at' => '2026-08-01T00:00:00Z',
            '--ends-at' => '2026-11-30T23:59:59Z',
        ])->assertSuccessful();

        $this->artisan('nexusos:set-user-limit', [
            '--tenant' => (string) $tenant->id,
            '--limit' => '8',
        ])->assertSuccessful();

        $resolver = app(ResolveTenantUserLimit::class);
        $this->assertSame(8, $resolver->handle((string) $tenant->id)['limit']);
        $this->assertSame(
            8,
            TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->value('users_limit_override'),
        );

        $this->artisan('nexusos:set-user-limit', [
            '--tenant' => (string) $tenant->id,
            '--inherit' => true,
        ])->assertSuccessful();

        $this->assertSame(5, $resolver->handle((string) $tenant->id)['limit']);
        $this->assertNull(
            TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->value('users_limit_override'),
        );
    }

    public function test_override_command_rejects_invalid_input_and_missing_current_license(): void
    {
        $tenant = $this->tenant('seat-override-invalid');

        $this->artisan('nexusos:set-user-limit', [
            '--tenant' => (string) $tenant->id,
            '--limit' => '0',
        ])->assertFailed();

        $this->artisan('nexusos:set-user-limit', [
            '--tenant' => (string) $tenant->id,
            '--limit' => '8',
            '--inherit' => true,
        ])->assertFailed();

        $this->artisan('nexusos:set-user-limit', [
            '--tenant' => (string) $tenant->id,
            '--limit' => '8',
        ])->assertFailed();
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Seat Override Tenant',
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
        ]);
    }
}
