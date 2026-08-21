<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class ProjectsApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function createActiveUser(array $attributes = []): User
    {
        return parent::createActiveUser(array_merge([
            'role' => User::ROLE_ADMINISTRATOR,
        ], $attributes));
    }

    public function test_guest_cannot_access_projects(): void
    {
        $this->getJson('/api/projects')
            ->assertUnauthorized();

        $this->postJson('/api/projects', [])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_project_with_generated_number(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', [
            'name' => 'مشروع المثال السكني',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'district' => 'الياسمين',
            'estimated_budget' => 2500000,
            'planned_start_date' => '2026-08-01',
            'planned_end_date' => '2027-08-01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.project.project_number', 'PRJ-2026-001')
            ->assertJsonPath('data.project.project_number_year', 2026)
            ->assertJsonPath('data.project.project_sequence_number', 1)
            ->assertJsonPath('data.project.status', 'draft')
            ->assertJsonPath('data.project.data_origin', 'user')
            ->assertJsonPath('data.project.tenant_id', $tenantId);

        $this->assertDatabaseHas('projects', [
            'project_number' => 'PRJ-2026-001',
            'name' => 'مشروع المثال السكني',
            'project_type' => 'residential',
            'status' => 'draft',
            'city' => 'الرياض',
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_project_status_can_only_change_through_lifecycle_commands(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'name' => 'مشروع بحالة غير مسموحة',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'status' => 'active',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع دورة الحياة',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');

        $this->patchJson("/api/projects/{$projectId}", [
            'status' => 'active',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'draft',
        ]);
    }

    public function test_project_creation_uses_the_active_membership_tenant(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع التوافق المرحلي',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->json('data.project.id');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'archived_at' => null,
            'archived_by' => null,
            'restored_by' => null,
        ]);
    }

    public function test_project_creation_uses_tenant_timezone_without_creating_company_settings(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $this->assertDatabaseMissing('system_settings', [
            'tenant_id' => $tenantId,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'name' => 'مشروع دون أثر جانبي للإعدادات',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated();

        $this->assertDatabaseMissing('system_settings', [
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_project_tenant_id_is_required_after_phase_three_compatibility(): void
    {
        $column = DB::selectOne("
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'projects'
              AND column_name = 'tenant_id'
        ");

        $this->assertSame('NO', $column->is_nullable);
    }

    public function test_projects_are_scoped_to_the_active_tenant(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        Sanctum::actingAs($tenantAUser);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع Tenant الأول',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');

        Sanctum::actingAs($tenantBUser);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->getJson("/api/projects/{$projectId}")
            ->assertNotFound();

        $this->patchJson("/api/projects/{$projectId}", [
            'name' => 'محاولة تعديل من Tenant آخر',
        ])->assertNotFound();

        $this->patchJson("/api/projects/{$projectId}/archive")
            ->assertNotFound();

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'tenant_id' => $this->tenantIdFor($tenantAUser),
            'name' => 'مشروع Tenant الأول',
        ]);
    }

    public function test_phase_two_point_six_assigns_only_explicitly_approved_historical_projects(): void
    {
        $tenant = Tenant::factory()->create([
            'id' => '01KXPBAATGZQBH4JRVETAH5SX1',
        ]);
        $user = $this->createActiveUser();
        $activeTenantId = $this->tenantIdFor($user);

        $tenantRequirementMigration = require database_path(
            'migrations/2026_07_24_000000_require_tenant_for_projects.php'
        );

        $tenantRequirementMigration->down();

        Sanctum::actingAs($user);

        $projects = [
            '01kxkhx6y03ge39wahs7cg32j8' => 'PRJ-2026-001',
            '01kxkj755fk2a8ww6k23yk578j' => 'PRJ-2026-002',
            '01kxkvpxwz0drmc3sy9e0a0wsv' => 'PRJ-2026-003',
            '01kxkx9k9b6snmard931m307zm' => 'PRJ-2026-004',
            '01ky1exp9w0m5hnmwsztpb7zsp' => 'PRJ-2026-005',
        ];

        foreach ($projects as $projectId => $projectNumber) {
            $createdProjectId = $this->postJson('/api/projects', [
                'name' => "مشروع ترحيل {$projectNumber}",
                'project_type' => 'residential',
                'city' => 'الرياض',
            ])->assertCreated()->json('data.project.id');

            DB::table('projects')->where('id', $createdProjectId)->update([
                'id' => $projectId,
                'tenant_id' => null,
            ]);
        }

        $unassignedProjectId = $this->postJson('/api/projects', [
            'name' => 'مشروع خارج قرار الترحيل',
            'project_type' => 'commercial',
            'city' => 'جدة',
        ])->assertCreated()->json('data.project.id');

        $migration = require database_path(
            'migrations/2026_07_23_030000_assign_historical_projects_to_ufq_tenant.php'
        );

        $migration->up();

        $tenantRequirementMigration->up();

        foreach ($projects as $projectId => $projectNumber) {
            $this->assertDatabaseHas('projects', [
                'id' => $projectId,
                'project_number' => $projectNumber,
                'tenant_id' => $tenant->id,
            ]);
        }

        $this->assertDatabaseHas('projects', [
            'id' => $unassignedProjectId,
            'tenant_id' => $activeTenantId,
        ]);
    }

    public function test_project_numbers_increment_within_same_year(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'name' => 'المشروع الأول',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated();

        $this->postJson('/api/projects', [
            'name' => 'المشروع الثاني',
            'project_type' => 'commercial',
            'city' => 'جدة',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-002'
            );
    }

    public function test_project_sequence_number_is_server_controlled(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'sequence_number' => 999999,
            'name' => 'محاولة التحكم في رقم المشروع',
            'project_type' => 'mixed_use',
            'city' => 'الدمام',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sequence_number');

        $this->postJson('/api/projects', [
            'name' => 'أول مشروع صحيح',
            'project_type' => 'residential',
            'city' => 'الخبر',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-001'
            );
    }

    public function test_project_numbering_is_independent_per_tenant(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        Sanctum::actingAs($tenantAUser);

        $this->postJson('/api/projects', [
            'name' => 'مشروع Tenant A الأول',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-001'
            );

        Sanctum::actingAs($tenantBUser);

        $this->postJson('/api/projects', [
            'name' => 'مشروع Tenant B الأول',
            'project_type' => 'commercial',
            'city' => 'جدة',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-001'
            );

        Sanctum::actingAs($tenantAUser);

        $this->postJson('/api/projects', [
            'name' => 'مشروع Tenant A الثاني',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-002'
            );

        Sanctum::actingAs($tenantBUser);

        $this->postJson('/api/projects', [
            'name' => 'مشروع Tenant B الثاني',
            'project_type' => 'commercial',
            'city' => 'جدة',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2026-002'
            );
    }

    public function test_authenticated_user_can_list_show_and_update_project(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع قبل التعديل',
            'project_type' => 'land',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->json('data.project.id');

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath(
                'data.data.0.id',
                $projectId
            );

        $this->getJson("/api/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath(
                'data.project.name',
                'مشروع قبل التعديل'
            );
        $this->patchJson("/api/projects/{$projectId}", [
            'name' => 'مشروع بعد التعديل',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.project.name',
                'مشروع بعد التعديل'
            )
            ->assertJsonPath('data.project.status', 'draft')
            ->assertJsonPath(
                'data.project.country_code',
                'SA'
            )
            ->assertJsonPath(
                'data.project.currency',
                'SAR'
            );

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => 'مشروع بعد التعديل',
            'status' => 'draft',
            'updated_by' => $user->id,
        ]);
    }

    public function test_projects_can_be_filtered_by_search_status_and_page(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        foreach ([
            ['name' => 'مشروع النخيل الأول', 'status' => 'active'],
            ['name' => 'مشروع النخيل الثاني', 'status' => 'active'],
            ['name' => 'مشروع الياسمين', 'status' => 'draft'],
        ] as $project) {
            $projectId = $this->postJson('/api/projects', [
                'name' => $project['name'],
                'project_type' => 'residential',
                'city' => 'الرياض',
            ])->assertCreated()->json('data.project.id');

            DB::table('projects')->where('id', $projectId)->update([
                'status' => $project['status'],
            ]);
        }

        $this->getJson('/api/projects?search=النخيل&status=active&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath(
                'data.data.0.name',
                'مشروع النخيل الأول'
            );
    }

    public function test_legacy_project_statuses_are_rejected_by_filters(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        foreach (['planning', 'archived'] as $status) {
            $this->getJson("/api/projects?status={$status}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }
    }

    public function test_project_legacy_soft_delete_column_has_been_removed(): void
    {
        $column = DB::selectOne("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'projects'
              AND column_name = 'deleted_at'
        ");

        $this->assertNull($column);
    }

    public function test_phase_five_migration_aborts_for_unarchived_legacy_soft_deletes(): void
    {
        $migration = require database_path(
            'migrations/2026_07_25_080000_remove_project_legacy_soft_deletes_and_states.php'
        );

        $migration->down();

        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع حذف منطقي غير مؤرشف',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');

        DB::table('projects')->where('id', $projectId)->update([
            'deleted_at' => '2026-07-25 08:00:00+00',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot remove deleted_at while unarchived soft-deleted projects exist.'
        );

        $migration->up();
    }

    public function test_authenticated_user_can_assign_and_remove_project_manager(): void
    {
        $user = $this->createActiveUser();
        $manager = $this->createProjectManagerForTenant(
            $this->tenantIdFor($user),
        );

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع بمدير',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $manager->id,
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.project.project_manager.id',
                $manager->id
            )
            ->assertJsonPath(
                'data.project.project_manager.name',
                $manager->name
            )
            ->json('data.project.id');

        $this->patchJson("/api/projects/{$projectId}", [
            'project_manager_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.project.project_manager_id',
                null
            )
            ->assertJsonPath(
                'data.project.project_manager',
                null
            );
    }

    public function test_project_validation_rejects_invalid_business_values(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'name' => '',
            'project_type' => 'invalid_type',
            'city' => '',
            'estimated_budget' => -1,
            'planned_start_date' => '2026-12-31',
            'planned_end_date' => '2026-01-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'project_type',
                'city',
                'estimated_budget',
                'planned_end_date',
            ]);
    }

    public function test_project_can_be_archived_and_restored_without_changing_its_lifecycle_state(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع للحذف',
            'project_type' => 'villa',
            'city' => 'الرياض',
        ])
            ->assertCreated()
            ->json('data.project.id');

        $this->patchJson("/api/projects/{$projectId}/archive")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تمت أرشفة المشروع بنجاح.'
            )
            ->assertJsonPath('data.project.status', 'draft')
            ->assertJsonPath('data.project.archived_by', $user->id)
            ->assertJsonPath('data.project.restored_by', null);

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'updated_by' => $user->id,
            'archived_by' => $user->id,
            'status' => 'draft',
        ]);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $projectId);

        $this->patchJson("/api/projects/{$projectId}", [
            'name' => 'محاولة تعديل مشروع مؤرشف',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/restore")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تمت استعادة المشروع بنجاح.'
            )
            ->assertJsonPath('data.project.status', 'draft')
            ->assertJsonPath('data.project.archived_at', null)
            ->assertJsonPath('data.project.archived_by', null)
            ->assertJsonPath('data.project.restored_by', $user->id);

        $this->patchJson("/api/projects/{$projectId}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->deleteJson("/api/projects/{$projectId}")
            ->assertMethodNotAllowed();
    }

    public function test_project_lifecycle_commands_enforce_frozen_transitions_and_completion_policy(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProjectForLifecycle('مشروع دورة التنفيذ');

        $this->patchJson("/api/projects/{$projectId}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/revert-to-draft")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/activate")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'active')
            ->assertJsonPath('data.project.archived_at', null);

        $this->patchJson("/api/projects/{$projectId}/activate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}", [
            'actual_start_date' => '2026-07-01',
            'actual_end_date' => '2026-07-15',
        ])->assertOk();

        $this->patchJson("/api/projects/{$projectId}/complete")
            ->assertOk()
            ->assertJsonPath('message', 'تم إكمال المشروع بنجاح.')
            ->assertJsonPath('data.project.status', 'completed')
            ->assertJsonPath('data.project.archived_at', null);

        $this->patchJson("/api/projects/{$projectId}", [
            'name' => 'محاولة تعديل مشروع مكتمل',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/activate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/revert-to-draft")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->patchJson("/api/projects/{$projectId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

    }

    public function test_active_project_can_revert_to_draft_only_without_an_active_reservation(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $revertibleProjectId = $this->createProjectForLifecycle(
            'مشروع قابل للإرجاع',
        );
        $this->patchJson("/api/projects/{$revertibleProjectId}/activate")
            ->assertOk();

        $this->patchJson("/api/projects/{$revertibleProjectId}/revert-to-draft")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'draft');

        $projectId = $this->createProjectForLifecycle(
            'مشروع له حجز نشط',
        );
        $this->patchJson("/api/projects/{$projectId}/activate")
            ->assertOk();

        $unitId = $this->createUnitForProject($projectId, 'A-401');
        $customerId = $this->createCustomerForLifecycle('0500000401');

        $this->postJson('/api/reservations', [
            'unit_id' => $unitId,
            'customer_id' => $customerId,
        ])->assertCreated();

        $this->patchJson("/api/projects/{$projectId}/revert-to-draft")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'active',
        ]);

    }

    public function test_active_project_cannot_be_cancelled_with_outstanding_commitments(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $cancellableProjectId = $this->createProjectForLifecycle(
            'مشروع قابل للإلغاء',
        );
        $this->patchJson("/api/projects/{$cancellableProjectId}/activate")
            ->assertOk();

        $this->patchJson("/api/projects/{$cancellableProjectId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'cancelled');

        $this->patchJson("/api/projects/{$cancellableProjectId}", [
            'name' => 'محاولة تعديل مشروع ملغي',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $projectId = $this->createProjectForLifecycle(
            'مشروع بوحدة مباعة',
        );
        $this->patchJson("/api/projects/{$projectId}/activate")
            ->assertOk();

        $unitId = $this->createUnitForProject($projectId, 'A-501');
        DB::table('units')->where('id', $unitId)->update([
            'status' => 'sold',
        ]);

        $this->patchJson("/api/projects/{$projectId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'active',
        ]);

        $reservedProjectId = $this->createProjectForLifecycle(
            'مشروع بحجز قائم',
        );
        $this->patchJson("/api/projects/{$reservedProjectId}/activate")
            ->assertOk();

        $reservedUnitId = $this->createUnitForProject(
            $reservedProjectId,
            'A-502',
        );
        $reservedCustomerId = $this->createCustomerForLifecycle(
            '0500000502',
        );

        $this->postJson('/api/reservations', [
            'unit_id' => $reservedUnitId,
            'customer_id' => $reservedCustomerId,
        ])->assertCreated();

        $this->patchJson("/api/projects/{$reservedProjectId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');
    }

    public function test_archived_project_must_be_restored_before_any_lifecycle_command(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $projectId = $this->createProjectForLifecycle('مشروع مؤرشف');
        $this->patchJson("/api/projects/{$projectId}/archive")
            ->assertOk();

        foreach ([
            'activate',
            'revert-to-draft',
            'complete',
            'cancel',
        ] as $command) {
            $this->patchJson("/api/projects/{$projectId}/{$command}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('project');
        }

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'draft',
        ]);
    }

    public function test_project_manager_is_limited_to_own_projects_and_self_assignment_on_create(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);
        $firstManager = $this->createProjectManagerForTenant($tenantId);
        $secondManager = $this->createProjectManagerForTenant($tenantId);

        Sanctum::actingAs($administrator);

        $firstProjectId = $this->postJson('/api/projects', [
            'name' => 'مشروع المدير الأول',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $firstManager->id,
        ])->assertCreated()->json('data.project.id');

        $secondProjectId = $this->postJson('/api/projects', [
            'name' => 'مشروع المدير الثاني',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $secondManager->id,
        ])->assertCreated()->json('data.project.id');

        Sanctum::actingAs($firstManager);

        $this->postJson('/api/projects', [
            'name' => 'مشروع المدير لنفسه',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $firstManager->id,
        ])->assertCreated();

        $this->postJson('/api/projects', [
            'name' => 'محاولة إسناد مدير آخر',
            'project_type' => 'residential',
            'city' => 'الرياض',
            'project_manager_id' => $secondManager->id,
        ])->assertForbidden();

        $this->patchJson("/api/projects/{$firstProjectId}", [
            'name' => 'مشروع المدير الأول بعد التعديل',
        ])->assertOk();

        $this->patchJson("/api/projects/{$secondProjectId}", [
            'name' => 'محاولة تعديل مشروع مدير آخر',
        ])->assertForbidden();

        $this->patchJson("/api/projects/{$firstProjectId}", [
            'project_manager_id' => null,
        ])->assertForbidden();

        $this->patchJson("/api/projects/{$firstProjectId}/activate")
            ->assertOk();
        $this->patchJson("/api/projects/{$firstProjectId}/revert-to-draft")
            ->assertOk();
        $this->patchJson("/api/projects/{$firstProjectId}/cancel")
            ->assertForbidden();
        $this->patchJson("/api/projects/{$firstProjectId}/archive")
            ->assertForbidden();
    }

    public function test_project_manager_assignment_requires_an_active_same_tenant_project_manager(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);
        $crossTenantManager = $this->createActiveUser([
            'role' => User::ROLE_PROJECT_MANAGER,
        ]);
        $inactiveManager = $this->createTenantUser(
            $tenantId,
            User::ROLE_PROJECT_MANAGER,
            TenantUser::STATUS_PAUSED,
        );
        $employee = $this->createTenantUser($tenantId, User::ROLE_EMPLOYEE);

        Sanctum::actingAs($administrator);

        foreach ([$crossTenantManager, $inactiveManager, $employee] as $candidate) {
            $this->postJson('/api/projects', [
                'name' => "مشروع مدير غير مؤهل {$candidate->id}",
                'project_type' => 'residential',
                'city' => 'الرياض',
                'project_manager_id' => $candidate->id,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['project_manager_id']);
        }
    }

    public function test_sales_accountant_and_employee_are_read_only_for_projects(): void
    {
        $administrator = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($administrator);

        Sanctum::actingAs($administrator);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'مشروع القراءة فقط',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');

        foreach ([
            User::ROLE_SALES,
            User::ROLE_ACCOUNTANT,
            User::ROLE_EMPLOYEE,
        ] as $role) {
            $user = $this->createTenantUser($tenantId, $role);
            Sanctum::actingAs($user);

            $this->getJson("/api/projects/{$projectId}")->assertOk();
            $this->postJson('/api/projects', [
                'name' => "مشروع غير مصرح {$role}",
                'project_type' => 'residential',
                'city' => 'الرياض',
            ])->assertForbidden();
            $this->patchJson("/api/projects/{$projectId}", [
                'name' => 'محاولة تعديل غير مصرح بها',
            ])->assertForbidden();
            $this->patchJson("/api/projects/{$projectId}/archive")
                ->assertForbidden();
        }
    }

    private function createProjectForLifecycle(string $name): string
    {
        return $this->postJson('/api/projects', [
            'name' => $name,
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');
    }

    private function createUnitForProject(
        string $projectId,
        string $unitNumber,
    ): string {
        return $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => $unitNumber,
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');
    }

    private function createCustomerForLifecycle(string $phone): string
    {
        return $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'عميل اختبار',
            'phone' => $phone,
        ])->assertCreated()->json('data.customer.id');
    }

    private function createProjectManagerForTenant(string $tenantId): User
    {
        return $this->createTenantUser(
            $tenantId,
            User::ROLE_PROJECT_MANAGER,
        );
    }

    private function createTenantUser(
        string $tenantId,
        string $role,
        string $membershipStatus = TenantUser::STATUS_ACTIVE,
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'status' => $membershipStatus,
        ]);

        return $user;
    }

    private function tenantIdFor(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->valueOrFail('tenant_id');
    }
}
