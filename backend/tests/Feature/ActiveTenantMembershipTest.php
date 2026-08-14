<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ActiveTenantMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_member_can_access_protected_api(): void
    {
        $user = $this->createUserWithMembership(
            TenantUser::STATUS_ACTIVE
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertOk();
    }

    public function test_paused_member_cannot_access_protected_api(): void
    {
        $user = $this->createUserWithMembership(
            TenantUser::STATUS_PAUSED
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'tenant_membership_paused'
            );
    }

    public function test_suspended_member_cannot_access_protected_api(): void
    {
        $user = $this->createUserWithMembership(
            TenantUser::STATUS_SUSPENDED
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'tenant_membership_suspended'
            );
    }

    public function test_removed_member_cannot_access_protected_api(): void
    {
        $user = $this->createUserWithMembership(
            TenantUser::STATUS_REMOVED
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'tenant_membership_removed'
            );
    }

    public function test_user_without_membership_cannot_access_protected_api(): void
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'tenant_membership_missing'
            );
    }

    private function createUserWithMembership(
        string $membershipStatus
    ): User {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => $membershipStatus,
            'joined_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }
}
