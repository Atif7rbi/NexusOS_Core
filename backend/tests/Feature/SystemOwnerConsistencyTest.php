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
        [$owner, $tenant] = $this->ownerContext();
        Sanctum::actingAs($owner);

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

    public function test_administrator_cannot_mutate_demote_deactivate_or_remove_system_owner(): void
    {
        [$owner, $tenant] = $this->ownerContext();
        [$administrator] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );
        $ownerMembership = TenantUser::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        Sanctum::actingAs($administrator);

        foreach ([
            ['name' => 'اسم غير مسموح'],
            ['role' => User::ROLE_EMPLOYEE],
            ['status' => TenantUser::STATUS_PAUSED],
            ['status' => TenantUser::STATUS_SUSPENDED],
        ] as $payload) {
            $this->putJson(
                "/api/users/{$ownerMembership->id}",
                $payload,
            )->assertForbidden();
        }

        $this->deleteJson("/api/users/{$ownerMembership->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'role' => User::ROLE_SYSTEM_OWNER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'id' => $ownerMembership->id,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);
    }

    public function test_user_cannot_change_own_role_status_or_remove_own_membership(): void
    {
        [$owner] = $this->ownerContext();
        $membership = TenantUser::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        Sanctum::actingAs($owner);

        $this->putJson("/api/users/{$membership->id}", [
            'role' => User::ROLE_ADMINISTRATOR,
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

    public function test_system_owner_can_manage_regular_users_and_administrators(): void
    {
        [$owner, $tenant] = $this->ownerContext();
        [$administrator, $administratorMembership] = $this->tenantMember(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        );
        [$employee, $employeeMembership] = $this->tenantMember(
            $tenant,
            User::ROLE_EMPLOYEE,
        );

        Sanctum::actingAs($owner);

        $this->putJson("/api/users/{$administratorMembership->id}", [
            'name' => 'مدير محدث',
        ])->assertOk();

        $this->putJson("/api/users/{$employeeMembership->id}", [
            'role' => User::ROLE_SALES,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $administrator->id,
            'name' => 'مدير محدث',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'role' => User::ROLE_SALES,
        ]);
    }

    /** @return array{User, Tenant} */
    private function ownerContext(): array
    {
        $tenant = Tenant::factory()->create();
        [$owner] = $this->tenantMember(
            $tenant,
            User::ROLE_SYSTEM_OWNER,
        );

        return [$owner, $tenant];
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
