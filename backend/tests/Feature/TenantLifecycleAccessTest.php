<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

final class TenantLifecycleAccessTest extends ApiTestCase
{
    public function test_active_user_membership_and_tenant_allow_login_and_protected_access(): void
    {
        [$user, , $token] = $this->lifecycleContext();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password8',
        ])->assertOk();

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_every_non_active_tenant_state_denies_login_and_existing_token_access(): void
    {
        foreach ([
            Tenant::STATUS_INVITED => 'tenant_invited',
            Tenant::STATUS_PAUSED => 'tenant_paused',
            Tenant::STATUS_SUSPENDED => 'tenant_suspended',
            Tenant::STATUS_REMOVED => 'tenant_removed',
        ] as $status => $code) {
            [$user, , $token] = $this->lifecycleContext(
                tenantStatus: $status,
            );

            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password8',
            ])->assertForbidden()
                ->assertJsonPath('error.code', $code)
                ->assertJsonPath('error.status', $status);

            app('auth')->forgetGuards();

            $this->withToken($token)
                ->getJson('/api/auth/user')
                ->assertForbidden()
                ->assertJsonPath('error.code', $code);
        }
    }

    public function test_every_non_active_membership_state_has_a_distinct_denial(): void
    {
        foreach ([
            TenantUser::STATUS_INVITED => 'tenant_membership_invited',
            TenantUser::STATUS_PAUSED => 'tenant_membership_paused',
            TenantUser::STATUS_SUSPENDED => 'tenant_membership_suspended',
            TenantUser::STATUS_REMOVED => 'tenant_membership_removed',
        ] as $status => $code) {
            [$user, , $token] = $this->lifecycleContext(
                membershipStatus: $status,
            );

            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password8',
            ])->assertForbidden()
                ->assertJsonPath('error.code', $code)
                ->assertJsonPath('error.status', $status);

            app('auth')->forgetGuards();

            $this->withToken($token)
                ->getJson('/api/auth/user')
                ->assertForbidden()
                ->assertJsonPath('error.code', $code);
        }
    }

    public function test_non_active_user_state_is_denied_independently_of_membership_and_tenant(): void
    {
        foreach ([
            User::STATUS_SUSPENDED => 'user_suspended',
            User::STATUS_ARCHIVED => 'user_archived',
        ] as $status => $code) {
            [$user, , $token] = $this->lifecycleContext(
                userStatus: $status,
            );

            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password8',
            ])->assertForbidden()
                ->assertJsonPath('error.code', $code)
                ->assertJsonPath('error.status', $status);

            app('auth')->forgetGuards();

            $this->withToken($token)
                ->getJson('/api/auth/user')
                ->assertForbidden()
                ->assertJsonPath('error.code', $code);
        }
    }

    public function test_cross_tenant_resource_is_hidden_without_disclosing_foreign_tenant_lifecycle(): void
    {
        [$actor] = $this->lifecycleContext();
        [, $foreignMembership] = $this->lifecycleContext(
            tenantStatus: Tenant::STATUS_SUSPENDED,
        );

        Sanctum::actingAs($actor);

        $this->getJson("/api/users/{$foreignMembership->id}")
            ->assertNotFound()
            ->assertJsonMissing(['code' => 'tenant_suspended']);
    }

    /** @return array{User, TenantUser, string} */
    private function lifecycleContext(
        string $tenantStatus = Tenant::STATUS_ACTIVE,
        string $membershipStatus = TenantUser::STATUS_ACTIVE,
        string $userStatus = User::STATUS_ACTIVE,
    ): array {
        $tenant = Tenant::factory()->create([
            'status' => $tenantStatus,
        ]);
        $user = User::factory()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('Password8'),
            'role' => User::ROLE_ADMINISTRATOR,
            'status' => $userStatus,
        ]);
        $membership = TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($user)
            ->create([
                'status' => $membershipStatus,
            ]);

        return [
            $user,
            $membership,
            $user->createToken('lifecycle-test')->plainTextToken,
        ];
    }
}
