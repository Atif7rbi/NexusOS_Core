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

    public function test_reserved_status_cannot_be_set_manually_when_creating_or_updating_a_unit(): void
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
            'status' => 'available',
            'selling_price' => 500000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

        $this->patchJson("/api/units/{$unitId}", [
            'status' => 'reserved',
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
            'status' => 'sold',
            'selling_price' => 950000,
            'floor' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث الوحدة بنجاح.')
            ->assertJsonPath('data.unit.status', 'sold')
            ->assertJsonPath('data.unit.selling_price', '950000.00')
            ->assertJsonPath('data.unit.floor', 4)
            ->assertJsonPath('data.unit.project_id', $projectId);

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'sold',
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
            $this->postJson('/api/units', [
                ...$unit,
                'selling_price' => 500000,
            ])->assertCreated();
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
            'status' => 'sold',
            'selling_price' => 800000,
        ])
            ->assertCreated()
            ->json('data.unit.id');

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
            'status' => 'available',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');

        $archivedUnitId = $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => 'A-502',
            'unit_type' => 'apartment',
            'status' => 'available',
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
            'status' => 'sold',
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
}
