<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

final class SystemOwnerConsistencyTest extends ApiTestCase
{
    public function test_normal_users_api_cannot_create_or_assign_system_owner_role(): void
    {
        $tenant = Tenant::factory()->create();
        [$administrator] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );
        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'مالك إضافي',
            'email' => 'new-owner@example.com',
            'role' => User::ROLE_SYSTEM_OWNER,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        [, $membership] = $this->tenantMember(
            $tenant,
            User::ROLE_EMPLOYEE,
        );

        $this->putJson("/api/users/{$membership->id}", [
            'role' => User::ROLE_SYSTEM_OWNER,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_system_owner_is_platform_identity_without_tenant_membership(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create([
            'role' => User::ROLE_SYSTEM_OWNER,
            'status' => User::STATUS_ACTIVE,
        ]);

        [$administrator] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );

        Sanctum::actingAs($administrator);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1);

        $this->assertDatabaseMissing('tenant_users', [
            'user_id' => $owner->id,
        ]);
    }

    public function test_legacy_system_owner_membership_is_not_exposed_or_counted(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create([
            'role' => User::ROLE_SYSTEM_OWNER,
            'status' => User::STATUS_ACTIVE,
        ]);
        TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($owner)
            ->active()
            ->create();

        [$administrator] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );

        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1);

        $emails = collect($response->json('data.users.data'))
            ->pluck('user.email')
            ->all();

        $this->assertNotContains($owner->email, $emails);
    }

    public function test_tenant_user_cannot_change_own_role_status_or_remove_own_membership(): void
    {
        $tenant = Tenant::factory()->create();
        [$administrator, $membership] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );

        Sanctum::actingAs($administrator);

        $this->putJson("/api/users/{$membership->id}", [
            'role' => User::ROLE_EMPLOYEE,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->putJson("/api/users/{$membership->id}", [
            'status' => TenantUser::STATUS_PAUSED,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->deleteJson("/api/users/{$membership->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_administrator_can_manage_regular_users_and_other_administrators(): void
    {
        $tenant = Tenant::factory()->create();
        [$administrator] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );
        [$otherAdministrator, $otherAdministratorMembership] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );
        [$employee, $employeeMembership] = $this->tenantMember(
            $tenant,
            User::ROLE_EMPLOYEE,
        );

        Sanctum::actingAs($administrator);

        $this->putJson("/api/users/{$otherAdministratorMembership->id}", [
            'name' => 'مدير محدث',
        ])->assertOk();

        $this->putJson("/api/users/{$employeeMembership->id}", [
            'role' => User::ROLE_SALES,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $otherAdministrator->id,
            'name' => 'مدير محدث',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'role' => User::ROLE_SALES,
        ]);
    }

    /** @return array{User, TenantUser} */
    private function tenantMember(Tenant $tenant, string $role): array
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
        $membership = TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($user)
            ->active()
            ->create();

        return [$user, $membership];
    }
}
