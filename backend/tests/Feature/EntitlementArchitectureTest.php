<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Modules\Entitlements\Services\ResolveEffectiveTenantModules;
use App\Modules\Entitlements\Services\ValidatePlanModuleDependencies;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class EntitlementArchitectureTest extends ApiTestCase
{
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

    public function test_license_lifecycle_states_control_effective_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $license = $tenant->licenses()->firstOrFail();
        $resolver = app(ResolveEffectiveTenantModules::class);

        $this->assertCount(7, $resolver->handle((string) $tenant->id));

        $license->update(['status' => TenantLicense::STATUS_TRIAL]);
        $this->assertCount(7, $resolver->handle((string) $tenant->id));

        $license->update([
            'status' => TenantLicense::STATUS_ACTIVE,
            'starts_at' => CarbonImmutable::now('UTC')->subDays(2),
            'ends_at' => CarbonImmutable::now('UTC')->subDay(),
        ]);
        $this->assertSame([], $resolver->handle((string) $tenant->id));

        $license->update([
            'status' => TenantLicense::STATUS_GRACE,
            'starts_at' => CarbonImmutable::now('UTC')->subDays(2),
            'ends_at' => CarbonImmutable::now('UTC')->subHour(),
            'grace_ends_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);
        $this->assertCount(7, $resolver->handle((string) $tenant->id));

        $license->update([
            'ends_at' => CarbonImmutable::now('UTC')->subDay(),
            'grace_ends_at' => CarbonImmutable::now('UTC')->subHour(),
        ]);
        $this->assertSame([], $resolver->handle((string) $tenant->id));

        $license->update([
            'status' => TenantLicense::STATUS_EXPIRED,
            'grace_ends_at' => null,
        ]);
        $this->assertSame([], $resolver->handle((string) $tenant->id));

        $license->update(['status' => TenantLicense::STATUS_CANCELLED]);
        $this->assertSame([], $resolver->handle((string) $tenant->id));

        $license->delete();
        $this->assertSame([], $resolver->handle((string) $tenant->id));
    }

    public function test_tenant_overrides_have_the_frozen_precedence(): void
    {
        $tenant = Tenant::factory()->create();
        $license = $tenant->licenses()->with('plan')->firstOrFail();
        $crm = Module::query()->where('key', CommercialModuleCatalog::CRM)->firstOrFail();
        $projects = Module::query()->where('key', CommercialModuleCatalog::PROJECTS)->firstOrFail();
        $resolver = app(ResolveEffectiveTenantModules::class);

        $license->plan->modules()->detach($crm->id);
        $this->assertFalse($resolver->isEntitled((string) $tenant->id, CommercialModuleCatalog::CRM));

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $crm->id,
            'state' => TenantModule::STATE_ENABLED,
        ]);
        $this->assertTrue($resolver->isEntitled((string) $tenant->id, CommercialModuleCatalog::CRM));

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $projects->id,
            'state' => TenantModule::STATE_DISABLED,
        ]);
        $this->assertFalse($resolver->isEntitled((string) $tenant->id, CommercialModuleCatalog::PROJECTS));

        $crm->update(['status' => Module::STATUS_INACTIVE]);
        $this->assertFalse($resolver->isEntitled((string) $tenant->id, CommercialModuleCatalog::CRM));
    }

    public function test_plan_dependency_validation_rejects_invalid_composition(): void
    {
        $validator = app(ValidatePlanModuleDependencies::class);

        foreach ([
            [CommercialModuleCatalog::UNITS],
            [CommercialModuleCatalog::CRM],
            [CommercialModuleCatalog::RESERVATIONS, CommercialModuleCatalog::CUSTOMERS],
            [CommercialModuleCatalog::CONTRACTS],
            [CommercialModuleCatalog::COLLECTIONS],
        ] as $invalidModules) {
            try {
                $validator->handle($invalidModules);
                $this->fail('Invalid Plan composition was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $validator->handle(app(CommercialModuleCatalog::class)->keys());
        $this->addToAssertionCount(1);
    }

    public function test_database_allows_non_overlapping_entitled_license_history(): void
    {
        $tenant = Tenant::factory()->create();
        $existing = $tenant->licenses()->firstOrFail();

        $historical = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $existing->plan_id,
            'status' => TenantLicense::STATUS_ACTIVE,
            'starts_at' => $existing->starts_at->subYear(),
            'ends_at' => $existing->starts_at->subSecond(),
            'grace_ends_at' => null,
        ]);

        $this->assertTrue($historical->exists);
        $this->assertSame(2, $tenant->licenses()->count());
    }

    public function test_database_rejects_overlapping_entitled_periods_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $existing = $tenant->licenses()->firstOrFail();

        $this->expectException(QueryException::class);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $existing->plan_id,
            'status' => TenantLicense::STATUS_TRIAL,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(),
            'ends_at' => CarbonImmutable::now('UTC')->addDay(),
            'grace_ends_at' => null,
        ]);
    }

    public function test_database_rejects_an_update_that_creates_an_entitled_period_overlap(): void
    {
        $tenant = Tenant::factory()->create();
        $existing = $tenant->licenses()->firstOrFail();
        $historical = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $existing->plan_id,
            'status' => TenantLicense::STATUS_ACTIVE,
            'starts_at' => $existing->starts_at->subYear(),
            'ends_at' => $existing->starts_at->subSecond(),
            'grace_ends_at' => null,
        ]);

        $this->expectException(QueryException::class);

        $historical->update([
            'ends_at' => $existing->starts_at,
        ]);
    }

    public function test_overlap_trigger_uses_a_transaction_scoped_tenant_advisory_lock(): void
    {
        /** @var object{definition: string} $function */
        $function = DB::selectOne(
            "SELECT pg_get_functiondef(
                'enforce_tenant_license_effective_period_overlap()'::regprocedure
            ) AS definition",
        );

        $this->assertStringContainsString(
            'pg_advisory_xact_lock',
            $function->definition,
        );
        $this->assertStringContainsString(
            'hashtext',
            $function->definition,
        );
        $this->assertStringContainsString(
            'tenant_id',
            $function->definition,
        );
        $this->assertStringContainsString(
            'existing.id IS DISTINCT FROM NEW.id',
            $function->definition,
        );
    }

    public function test_commercial_module_key_is_immutable(): void
    {
        Tenant::factory()->create();
        $module = Module::query()->where('key', CommercialModuleCatalog::PROJECTS)->firstOrFail();

        $this->expectException(LogicException::class);

        $module->update(['key' => 'renamed-projects']);
    }
}
