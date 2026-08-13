<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Units\Models\Unit;
use Laravel\Sanctum\Sanctum;

final class UnitsApiTest extends ApiTestCase
{
    private int $projectCount = 0;

    protected function createActiveUser(array $attributes = []): User
    {
        return parent::createActiveUser(array_merge([
            'role' => User::ROLE_ADMINISTRATOR,
        ], $attributes));
    }

    public function test_guest_cannot_access_units(): void
    {
        $unitId = '01J00000000000000000000000';

        $this->postJson('/api/units', [])
            ->assertUnauthorized();

        $this->getJson('/api/units')
            ->assertUnauthorized();

        $this->getJson("/api/units/{$unitId}")
            ->assertUnauthorized();

        $this->patchJson("/api/units/{$unitId}", [])
            ->assertUnauthorized();

        $this->patchJson("/api/units/{$unitId}/archive")
            ->assertUnauthorized();

        $this->patchJson("/api/units/{$unitId}/restore")
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_unit(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        Sanctum::actingAs($user);

        $projectId = $this->createProject();

        $response = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'A-101',
            'unit_type' => 'apartment',
            'selling_price' => 750000,
            'area' => 125.5,
            'floor' => 1,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'notes' => 'إطلالة على الحديقة',
            'tenant_id' => '01J00000000000000000000000',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء الوحدة بنجاح.')
            ->assertJsonPath('data.unit.tenant_id', $tenantId)
            ->assertJsonPath('data.unit.project_id', $projectId)
            ->assertJsonPath('data.unit.unit_number', 'A-101')
            ->assertJsonPath('data.unit.unit_type', 'apartment')
            ->assertJsonPath('data.unit.status', 'available')
            ->assertJsonPath('data.unit.selling_price', '750000.00')
            ->assertJsonPath('data.unit.created_by', $user->id)
            ->assertJsonPath('data.unit.updated_by', $user->id);

        $this->assertDatabaseHas('units', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'unit_number' => 'A-101',
            'unit_type' => 'apartment',
            'status' => 'available',
            'selling_price' => 750000,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_unit_creation_validates_required_and_business_values(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/units', [
            'project_id' => '01J00000000000000000000000',
            'unit_number' => '',
            'unit_type' => 'invalid',
            'status' => 'invalid',
            'selling_price' => -1,
            'bedrooms' => -1,
            'bathrooms' => -1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'project_id',
                'unit_number',
                'unit_type',
                'status',
                'selling_price',
                'bedrooms',
                'bathrooms',
            ]);
    }

    public function test_unit_status_cannot_be_set_manually_when_creating_or_updating_a_unit(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProject();

        $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'R-101',
            'unit_type' => 'apartment',
            'status' => 'reserved',
            'selling_price' => 500000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseMissing('units', [
            'project_id' => $projectId,
            'unit_number' => 'R-101',
        ]);

        $unitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'R-102',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

        $this->patchJson("/api/units/{$unitId}", [
            'status' => 'sold',
            'selling_price' => 750000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'available',
            'selling_price' => 500000,
        ]);
    }

    public function test_unit_number_is_unique_within_its_project_only(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $firstProjectId = $this->createProject();
        $secondProjectId = $this->createProject();

        $payload = [
            'unit_number' => '101',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ];

        $this->postJson('/api/units', [
            ...$payload,
            'project_id' => $firstProjectId,
        ])->assertCreated();

        $this->postJson('/api/units', [
            ...$payload,
            'project_id' => $firstProjectId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_number']);

        $this->postJson('/api/units', [
            ...$payload,
            'project_id' => $secondProjectId,
        ])->assertCreated();
    }

    public function test_authenticated_user_can_show_and_update_own_unit(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProject();
        $unitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'B-202',
            'unit_type' => 'office',
            'selling_price' => 900000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

        $this->getJson("/api/units/{$unitId}")
            ->assertOk()
            ->assertJsonPath('data.unit.id', $unitId)
            ->assertJsonPath('data.unit.unit_number', 'B-202');

        $this->patchJson("/api/units/{$unitId}", [
            'selling_price' => 950000,
            'floor' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الوحدة بنجاح.')
            ->assertJsonPath('data.unit.status', 'available')
            ->assertJsonPath('data.unit.selling_price', '950000.00')
            ->assertJsonPath('data.unit.floor', 4)
            ->assertJsonPath('data.unit.project_id', $projectId);

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'available',
            'selling_price' => 950000,
            'floor' => 4,
            'updated_by' => $user->id,
        ]);
    }

    public function test_units_can_be_listed_filtered_and_summarized(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $firstProjectId = $this->createProject();
        $secondProjectId = $this->createProject();

        foreach ([
            [
                'project_id' => $firstProjectId,
                'unit_number' => 'A-101',
                'unit_type' => 'apartment',
                'status' => 'available',
            ],
            [
                'project_id' => $firstProjectId,
                'unit_number' => 'A-102',
                'unit_type' => 'apartment',
                'status' => 'sold',
            ],
            [
                'project_id' => $secondProjectId,
                'unit_number' => 'V-201',
                'unit_type' => 'villa',
                'status' => 'available',
            ],
        ] as $unit) {
            $status = $unit['status'];
            unset($unit['status']);

            $unitId = $this->postJson('/api/units', [
                ...$unit,
                'selling_price' => 500000,
            ])->assertCreated()->json('data.unit.id');

            Unit::query()->whereKey($unitId)->update(['status' => $status]);
        }

        $this->getJson(
            "/api/units?search=A-10&project_id={$firstProjectId}&unit_type=apartment&status=available&per_page=1"
        )
            ->assertOk()
            ->assertJsonPath('data.units.total', 1)
            ->assertJsonPath('data.units.per_page', 1)
            ->assertJsonPath('data.units.data.0.unit_number', 'A-101')
            ->assertJsonPath(
                'data.units.data.0.project.id',
                $firstProjectId
            )
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.available', 2)
            ->assertJsonPath('data.summary.sold', 1);
    }

    public function test_unit_can_be_archived_and_restored_without_changing_business_status(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProject();
        $unitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'S-401',
            'unit_type' => 'shop',
            'selling_price' => 800000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

        Unit::query()->whereKey($unitId)->update(['status' => 'sold']);

        $this->patchJson("/api/units/{$unitId}/archive")
            ->assertOk()
            ->assertJsonPath('message', 'تمت أرشفة الوحدة بنجاح.')
            ->assertJsonPath('data.unit.status', 'sold')
            ->assertJsonPath('data.unit.archived_by', $user->id);

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'sold',
            'archived_by' => $user->id,
        ]);

        $this->getJson('/api/units?archived=true')
            ->assertOk()
            ->assertJsonPath('data.units.total', 1)
            ->assertJsonPath('data.units.data.0.id', $unitId);

        $this->getJson('/api/units?archived=false')
            ->assertOk()
            ->assertJsonPath('data.units.total', 0);

        $this->patchJson("/api/units/{$unitId}", [
            'selling_price' => 850000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);

        $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'S-401',
            'unit_type' => 'shop',
            'selling_price' => 800000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_number']);

        $this->patchJson("/api/units/{$unitId}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);

        $this->patchJson("/api/units/{$unitId}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'تمت استعادة الوحدة بنجاح.')
            ->assertJsonPath('data.unit.status', 'sold')
            ->assertJsonPath('data.unit.archived_at', null)
            ->assertJsonPath('data.unit.restored_by', $user->id);

        $this->patchJson("/api/units/{$unitId}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);
    }

    public function test_unit_summary_respects_the_active_archive_scope(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProject();

        $activeUnitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'A-501',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        $archivedUnitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'A-502',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        $this->patchJson("/api/units/{$archivedUnitId}/archive")
            ->assertOk();

        $this->getJson('/api/units?archived=false')
            ->assertOk()
            ->assertJsonPath('data.units.total', 1)
            ->assertJsonPath('data.units.data.0.id', $activeUnitId)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.available', 1)
            ->assertJsonPath('data.summary.reserved', 0)
            ->assertJsonPath('data.summary.sold', 0);

        $this->getJson('/api/units?archived=true')
            ->assertOk()
            ->assertJsonPath('data.units.total', 1)
            ->assertJsonPath('data.units.data.0.id', $archivedUnitId)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.available', 1);
    }

    public function test_units_are_scoped_to_the_active_tenant(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        Sanctum::actingAs($tenantAUser);

        $unitId = $this->postJson('/api/units', [
            'project_id' => $this->createProject(),
            'unit_number' => 'C-303',
            'unit_type' => 'villa',
            'selling_price' => 1500000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

        Sanctum::actingAs($tenantBUser);

        $this->getJson("/api/units/{$unitId}")
            ->assertNotFound();

        $this->patchJson("/api/units/{$unitId}", [
            'selling_price' => 1600000,
        ])->assertNotFound();

        $this->assertSame(
            'available',
            Unit::query()->findOrFail($unitId)->status->value
        );
    }

    public function test_unit_cannot_reference_a_project_from_another_tenant(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        Sanctum::actingAs($tenantAUser);

        $tenantAProjectId = $this->createProject();

        Sanctum::actingAs($tenantBUser);

        $this->postJson('/api/units', [
            'project_id' => $tenantAProjectId,
            'unit_number' => 'X-101',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertNotFound();

        $this->assertDatabaseMissing('units', [
            'project_id' => $tenantAProjectId,
            'unit_number' => 'X-101',
        ]);
    }

    public function test_project_manager_can_manage_units_only_in_projects_assigned_to_them(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);
        $firstManager = $this->createTenantUser(
            $tenantId,
            User::ROLE_PROJECT_MANAGER,
        );
        $secondManager = $this->createTenantUser(
            $tenantId,
            User::ROLE_PROJECT_MANAGER,
        );

        Sanctum::actingAs($administrator);

        $firstProjectId = $this->createProjectForManager($firstManager);
        $secondProjectId = $this->createProjectForManager($secondManager);
        $otherUnitId = $this->createUnitForProject(
            $secondProjectId,
            'PM-201',
        );

        Sanctum::actingAs($firstManager);

        $unitId = $this->postJson('/api/units', [
            'project_id' => $firstProjectId,
            'unit_number' => 'PM-101',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        $this->postJson('/api/units', [
            'project_id' => $secondProjectId,
            'unit_number' => 'PM-102',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertForbidden();

        $this->patchJson("/api/units/{$unitId}", [
            'selling_price' => 550000,
        ])->assertOk();

        $this->patchJson("/api/units/{$unitId}/archive")
            ->assertOk();
        $this->patchJson("/api/units/{$unitId}/restore")
            ->assertOk();

        $this->patchJson("/api/units/{$unitId}", [
            'project_id' => $secondProjectId,
        ])->assertForbidden();

        $this->patchJson("/api/units/{$otherUnitId}", [
            'selling_price' => 550000,
        ])->assertForbidden();
        $this->patchJson("/api/units/{$otherUnitId}/archive")
            ->assertForbidden();
    }

    public function test_sales_accountant_and_employee_are_read_only_for_units(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);

        Sanctum::actingAs($administrator);

        $projectId = $this->createProject();
        $unitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'RO-101',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        foreach ([
            User::ROLE_SALES,
            User::ROLE_ACCOUNTANT,
            User::ROLE_EMPLOYEE,
        ] as $role) {
            $user = $this->createTenantUser($tenantId, $role);
            Sanctum::actingAs($user);

            $this->getJson("/api/units/{$unitId}")->assertOk();
            $this->postJson('/api/units', [
                'project_id' => $projectId,
                'unit_number' => "RO-{$role}",
                'unit_type' => 'apartment',
                'selling_price' => 500000,
            ])->assertForbidden();
            $this->patchJson("/api/units/{$unitId}", [
                'selling_price' => 550000,
            ])->assertForbidden();
            $this->patchJson("/api/units/{$unitId}/archive")
                ->assertForbidden();
        }
    }

    public function test_system_owner_cannot_set_a_unit_status_manually(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);
        $owner = $this->createTenantUser($tenantId, User::ROLE_SYSTEM_OWNER);

        Sanctum::actingAs($administrator);

        $projectId = $this->createProject();
        $unitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'OWNER-100',
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        Sanctum::actingAs($owner);

        $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'OWNER-101',
            'unit_type' => 'apartment',
            'status' => 'sold',
            'selling_price' => 500000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/units/{$unitId}", [
            'status' => 'sold',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    private function tenantIdFor(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->valueOrFail('tenant_id');
    }

    private function createProject(): string
    {
        $this->projectCount++;

        return (string) $this->postJson('/api/projects', [
            'name' => "مشروع الوحدات {$this->projectCount}",
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->json('data.project.id');
    }

    private function createProjectForManager(User $manager): string
    {
        $this->projectCount++;

        return (string) $this->postJson('/api/projects', [
            'name' => "مشروع مدير الوحدات {$this->projectCount}",
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $manager->id,
        ])
            ->assertCreated()
            ->json('data.project.id');
    }

    private function createUnitForProject(
        string $projectId,
        string $unitNumber,
    ): string {
        return (string) $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => $unitNumber,
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])
            ->assertCreated()
            ->json('data.unit.id');
    }

    private function createTenantUser(string $tenantId, string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);

        return $user;
    }
}
