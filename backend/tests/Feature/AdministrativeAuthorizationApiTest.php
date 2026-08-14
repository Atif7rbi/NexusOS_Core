<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Enums\LeadSource;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesLeadFixtures;

final class AdministrativeAuthorizationApiTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    /**
     * @var list<string>
     */
    private const CRM_ROLES = [
        User::ROLE_SYSTEM_OWNER,
        User::ROLE_ADMINISTRATOR,
        User::ROLE_PROJECT_MANAGER,
        User::ROLE_SALES,
    ];

    /**
     * @var list<string>
     */
    private const NON_CRM_ROLES = [
        User::ROLE_ACCOUNTANT,
        User::ROLE_EMPLOYEE,
    ];

    /**
     * @var list<string>
     */
    private const NON_ADMINISTRATIVE_ROLES = [
        User::ROLE_PROJECT_MANAGER,
        User::ROLE_SALES,
        User::ROLE_ACCOUNTANT,
        User::ROLE_EMPLOYEE,
    ];

    public function test_crm_access_and_creation_match_the_frozen_role_matrix(): void
    {
        foreach (self::CRM_ROLES as $role) {
            [$tenant, $actor] = $this->createActor($role);
            Sanctum::actingAs($actor);

            $this->getJson('/api/leads')->assertOk();
            $this->postJson('/api/leads', $this->leadPayload())
                ->assertCreated()
                ->assertJsonPath('data.lead.tenant_id', (string) $tenant->id);
        }

        foreach (self::NON_CRM_ROLES as $role) {
            [, $actor] = $this->createActor($role);
            Sanctum::actingAs($actor);

            $this->getJson('/api/leads')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'lead_action_not_authorized');
            $this->postJson('/api/leads', $this->leadPayload())
                ->assertForbidden()
                ->assertJsonPath('error.code', 'lead_action_not_authorized');
        }
    }

    public function test_crm_mutation_uses_administrative_or_assigned_actor_authority(): void
    {
        foreach (self::CRM_ROLES as $role) {
            [$tenant, $actor] = $this->createActor($role);
            $ownLead = $this->createLead($tenant, $actor);
            Sanctum::actingAs($actor);

            $this->patchJson("/api/leads/{$ownLead->id}", [
                'name' => "Updated {$role}",
            ])->assertOk();

            if (in_array($role, [User::ROLE_PROJECT_MANAGER, User::ROLE_SALES], true)) {
                $other = $this->createLeadUser($tenant, $role)['user'];
                $otherLead = $this->createLead($tenant, $other);

                $this->patchJson("/api/leads/{$otherLead->id}", [
                    'name' => 'Forbidden update',
                ])->assertNotFound();
            }
        }

        foreach (self::NON_CRM_ROLES as $role) {
            [$tenant, $actor] = $this->createActor($role);
            $lead = $this->createLead($tenant, $actor);
            Sanctum::actingAs($actor);

            $this->patchJson("/api/leads/{$lead->id}", [
                'name' => 'Forbidden update',
            ])->assertForbidden();
        }
    }

    public function test_only_sales_and_project_managers_are_eligible_lead_assignees(): void
    {
        [$tenant, $administrator] = $this->createActor(User::ROLE_ADMINISTRATOR);
        Sanctum::actingAs($administrator);

        foreach ([User::ROLE_PROJECT_MANAGER, User::ROLE_SALES] as $role) {
            $assignee = $this->createLeadUser($tenant, $role)['user'];

            $this->postJson('/api/leads', array_merge($this->leadPayload(), [
                'assigned_to' => $assignee->id,
            ]))
                ->assertCreated()
                ->assertJsonPath('data.lead.assigned_to.id', $assignee->id)
                ->assertJsonPath('data.lead.assigned_to.is_eligible', true);
        }

        foreach ([
            User::ROLE_SYSTEM_OWNER,
            User::ROLE_ADMINISTRATOR,
            User::ROLE_ACCOUNTANT,
            User::ROLE_EMPLOYEE,
        ] as $role) {
            $assignee = $role === User::ROLE_ADMINISTRATOR
                ? $administrator
                : $this->createLeadUser($tenant, $role)['user'];

            $this->postJson('/api/leads', array_merge($this->leadPayload(), [
                'assigned_to' => $assignee->id,
            ]))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'lead_assignee_role_not_eligible');
        }
    }

    public function test_owner_and_administrator_can_manage_every_tenant_user_operation(): void
    {
        foreach ([User::ROLE_SYSTEM_OWNER, User::ROLE_ADMINISTRATOR] as $role) {
            [$tenant, $actor] = $this->createActor($role);
            $target = $this->createMember($tenant, $actor, User::ROLE_EMPLOYEE);
            Sanctum::actingAs($actor);

            $this->getJson('/api/users')->assertOk();
            $this->getJson("/api/users/{$target->id}")->assertOk();
            $this->postJson('/api/users', $this->userPayload($role))
                ->assertCreated();

            $this->putJson("/api/users/{$target->id}", [
                'role' => User::ROLE_SALES,
                'status' => TenantUser::STATUS_PAUSED,
            ])->assertOk();
            $this->putJson("/api/users/{$target->id}", [
                'status' => TenantUser::STATUS_ACTIVE,
            ])->assertOk();
            $this->putJson("/api/users/{$target->id}", [
                'status' => TenantUser::STATUS_SUSPENDED,
            ])->assertOk();
            $this->deleteJson("/api/users/{$target->id}")->assertOk();
        }
    }

    public function test_non_administrative_roles_cannot_manage_tenant_users(): void
    {
        foreach (self::NON_ADMINISTRATIVE_ROLES as $role) {
            [$tenant, $actor] = $this->createActor($role);
            $target = $this->createMember($tenant, $actor, User::ROLE_EMPLOYEE);
            Sanctum::actingAs($actor);

            $this->getJson('/api/users')->assertForbidden();
            $this->getJson("/api/users/{$target->id}")->assertForbidden();
            $this->postJson('/api/users', $this->userPayload($role))
                ->assertForbidden();
            $this->putJson("/api/users/{$target->id}", [
                'role' => User::ROLE_SALES,
                'status' => TenantUser::STATUS_PAUSED,
            ])->assertForbidden();
            $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
        }
    }

    public function test_system_settings_are_readable_by_all_roles_but_mutable_only_by_administrators(): void
    {
        foreach (array_merge(self::CRM_ROLES, self::NON_CRM_ROLES) as $role) {
            [, $actor] = $this->createActor($role);
            Sanctum::actingAs($actor);

            $this->getJson('/api/system-settings')->assertOk();
            $response = $this->putJson('/api/system-settings', [
                'short_name_ar' => "إعدادات {$role}",
            ]);

            if (in_array($role, [User::ROLE_SYSTEM_OWNER, User::ROLE_ADMINISTRATOR], true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_cross_tenant_resources_are_not_disclosed_before_authorization(): void
    {
        [, $administrator] = $this->createActor(User::ROLE_ADMINISTRATOR);
        [$otherTenant, $otherAdministrator] = $this->createActor(User::ROLE_ADMINISTRATOR);
        $otherMembership = TenantUser::query()
            ->where('tenant_id', $otherTenant->id)
            ->where('user_id', $otherAdministrator->id)
            ->firstOrFail();
        $otherLead = $this->createLead($otherTenant, $otherAdministrator);
        Sanctum::actingAs($administrator);

        $this->getJson("/api/users/{$otherMembership->id}")->assertNotFound();
        $this->putJson("/api/users/{$otherMembership->id}", [
            'name' => 'Cross tenant update',
        ])->assertNotFound();
        $this->deleteJson("/api/users/{$otherMembership->id}")->assertNotFound();
        $this->getJson("/api/leads/{$otherLead->id}")->assertNotFound();
        $this->patchJson("/api/leads/{$otherLead->id}", [
            'name' => 'Cross tenant update',
        ])->assertNotFound();
    }

    /**
     * @return array{Tenant, User}
     */
    private function createActor(string $role): array
    {
        $tenant = $this->createLeadTenant();
        $actor = $this->createLeadUser($tenant, $role)['user'];

        return [$tenant, $actor];
    }

    private function createMember(
        Tenant $tenant,
        User $actor,
        string $role,
    ): TenantUser {
        $user = User::factory()->create([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);

        return TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($user)
            ->active()
            ->create([
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function leadPayload(): array
    {
        return [
            'name' => 'CP5 Lead',
            'phone' => $this->nextLeadPhone(),
            'source' => LeadSource::WhatsApp->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function userPayload(string $suffix): array
    {
        return [
            'name' => 'CP5 User',
            'email' => "cp5-{$suffix}@example.test",
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ];
    }
}
