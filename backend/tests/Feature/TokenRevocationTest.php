<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

final class TokenRevocationTest extends ApiTestCase
{
    public function test_administrative_membership_deactivation_revokes_every_user_token(): void
    {
        foreach ([
            TenantUser::STATUS_PAUSED,
            TenantUser::STATUS_SUSPENDED,
        ] as $status) {
            [$administrator, $tenant] = $this->administratorContext();
            [$target, $membership] = $this->tenantMember($tenant);
            $oldToken = $target->createToken('old-device')->plainTextToken;
            $target->createToken('other-device');

            Sanctum::actingAs($administrator);

            $this->putJson("/api/users/{$membership->id}", [
                'status' => $status,
            ])->assertOk();

            $this->assertDatabaseMissing('personal_access_tokens', [
                'tokenable_id' => $target->id,
            ]);

            app('auth')->forgetGuards();

            $this->withToken($oldToken)
                ->getJson('/api/auth/user')
                ->assertUnauthorized();
        }
    }

    public function test_administrative_removal_archives_user_and_revokes_every_token(): void
    {
        [$administrator, $tenant] = $this->administratorContext();
        [$target, $membership] = $this->tenantMember($tenant);
        $oldToken = $target->createToken('old-device')->plainTextToken;
        $target->createToken('other-device');

        Sanctum::actingAs($administrator);

        $this->deleteJson("/api/users/{$membership->id}")
            ->assertOk();

        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'status' => TenantUser::STATUS_REMOVED,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => User::STATUS_ARCHIVED,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
        ]);

        app('auth')->forgetGuards();

        $this->withToken($oldToken)
            ->getJson('/api/auth/user')
            ->assertUnauthorized();
    }

    public function test_role_or_password_change_revokes_existing_tokens(): void
    {
        foreach ([
            ['role' => User::ROLE_SALES],
            [
                'password' => 'ChangedPassword8',
                'password_confirmation' => 'ChangedPassword8',
            ],
        ] as $payload) {
            [$administrator, $tenant] = $this->administratorContext();
            [$target, $membership] = $this->tenantMember($tenant);
            $target->createToken('device-1');
            $target->createToken('device-2');

            Sanctum::actingAs($administrator);

            $this->putJson("/api/users/{$membership->id}", $payload)
                ->assertOk();

            $this->assertDatabaseMissing('personal_access_tokens', [
                'tokenable_id' => $target->id,
            ]);
        }
    }

    public function test_state_enforcement_blocks_a_token_even_if_state_changed_outside_admin_api(): void
    {
        [, $tenant] = $this->administratorContext();
        [$target, $membership] = $this->tenantMember($tenant);
        $token = $target->createToken('existing-device')->plainTextToken;

        $membership->forceFill([
            'status' => TenantUser::STATUS_PAUSED,
        ])->save();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $target->id,
        ]);

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'tenant_membership_paused',
            );
    }

    /** @return array{User, Tenant} */
    private function administratorContext(): array
    {
        $tenant = Tenant::factory()->create();
        $administrator = User::factory()->create([
            'role' => User::ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($administrator)
            ->active()
            ->create();

        return [$administrator, $tenant];
    }

    /** @return array{User, TenantUser} */
    private function tenantMember(Tenant $tenant): array
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
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
