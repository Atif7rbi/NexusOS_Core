<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use App\Modules\Projects\Models\Project;
use Laravel\Sanctum\Sanctum;

final class ModuleEntitlementApiTest extends ApiTestCase
{
    public function test_entitled_and_authorized_request_is_allowed(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/projects')->assertOk();
        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonCount(7, 'data.effective_modules')
            ->assertJsonPath('data.effective_modules.0', CommercialModuleCatalog::COLLECTIONS);
    }

    public function test_not_entitled_denies_direct_api_access_with_canonical_error(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenant = $this->tenantFor($administrator);
        $this->disable($tenant, CommercialModuleCatalog::PROJECTS);
        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/projects')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'module_not_entitled');

        $this->assertSame(
            $response->json('message'),
            $response->json('error.message'),
        );
    }

    public function test_every_commercial_module_entry_point_enforces_entitlement(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenant = $this->tenantFor($administrator);

        foreach ([
            CommercialModuleCatalog::PROJECTS => '/api/projects',
            CommercialModuleCatalog::UNITS => '/api/units',
            CommercialModuleCatalog::CUSTOMERS => '/api/customers',
            CommercialModuleCatalog::CRM => '/api/leads',
            CommercialModuleCatalog::RESERVATIONS => '/api/reservations',
            CommercialModuleCatalog::CONTRACTS => '/api/contracts',
            CommercialModuleCatalog::COLLECTIONS => '/api/collections',
        ] as $moduleKey => $endpoint) {
            $this->disable($tenant, $moduleKey);
            Sanctum::actingAs($administrator);

            $this->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'module_not_entitled');
        }

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/system-settings')->assertOk();
    }

    public function test_no_current_license_denies_an_otherwise_authorized_actor(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenant = $this->tenantFor($administrator);
        TenantLicense::query()->where('tenant_id', $tenant->id)->delete();
        Sanctum::actingAs($administrator);

        $this->postJson('/api/projects', [
            'name' => 'Not licensed',
            'project_type' => 'residential',
            'city' => 'Riyadh',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'module_not_entitled');
    }

    public function test_entitled_but_unauthorized_role_keeps_existing_rbac_denial(): void
    {
        $accountant = $this->createActiveUser([
            'role' => User::ROLE_ACCOUNTANT,
        ]);
        Sanctum::actingAs($accountant);

        $this->postJson('/api/projects', [
            'name' => 'Forbidden by RBAC',
            'project_type' => 'residential',
            'city' => 'Riyadh',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_cross_tenant_resource_stays_hidden_before_entitlement_denial(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenant = $this->tenantFor($administrator);
        $this->disable($tenant, CommercialModuleCatalog::PROJECTS);

        $otherAdministrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $otherTenant = $this->tenantFor($otherAdministrator);
        $project = Project::query()->create([
            'tenant_id' => $otherTenant->id,
            'project_number' => 'PRJ-2099-001',
            'project_number_year' => 2099,
            'project_sequence_number' => 1,
            'name' => 'Other Tenant Project',
            'project_type' => 'residential',
            'status' => 'draft',
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'currency' => 'SAR',
            'data_origin' => 'user',
            'created_by' => $otherAdministrator->id,
            'updated_by' => $otherAdministrator->id,
        ]);

        Sanctum::actingAs($administrator);

        $this->getJson("/api/projects/{$project->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    private function tenantFor(User $user): Tenant
    {
        return TenantUser::query()
            ->with('tenant')
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->tenant;
    }

    private function disable(Tenant $tenant, string $moduleKey): void
    {
        $module = Module::query()->where('key', $moduleKey)->firstOrFail();

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'state' => TenantModule::STATE_DISABLED,
        ]);
    }
}
