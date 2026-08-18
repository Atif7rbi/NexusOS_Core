<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantUsersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_users(): void
    {
        $this->getJson('/api/users')
            ->assertUnauthorized();
    }

    public function test_company_administrator_can_list_users(): void
    {
        [$administrator] = $this->createCompanyAdministrator();

        Sanctum::actingAs($administrator);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.limit', 5)
            ->assertJsonPath(
                'data.users.data.0.user.email',
                $administrator->email
            );
    }

    public function test_company_administrator_can_create_user(): void
    {
        [$administrator] = $this->createCompanyAdministrator();

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'موظف المبيعات',
            'email' => 'sales@example.com',
            'phone' => '0500000000',
            'role' => User::ROLE_SALES,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.user.email', 'sales@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'sales@example.com',
            'role' => User::ROLE_SALES,
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'user_id' => User::query()
                ->where('email', 'sales@example.com')
                ->value('id'),
            'status' => TenantUser::STATUS_ACTIVE,
        ]);
    }

    public function test_system_owner_legacy_membership_is_not_exposed_or_counted(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        $owner = User::factory()->create([
            'role' => User::ROLE_SYSTEM_OWNER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $ownerMembership = TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Sanctum::actingAs($administrator);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonMissing(['email' => $owner->email]);

        $this->getJson("/api/users/{$ownerMembership->id}")
            ->assertNotFound();
    }

    public function test_user_phone_normalization_and_raw_http_boundary(): void
    {
        [$administrator] = $this->createCompanyAdministrator();
        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/users', [
            'name' => 'هاتف',
            'email' => 'phone@example.com',
            'phone' => "\t۰۵۰۱۲۳۴۵۶۷ ",
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ]);
        $response->assertCreated()->assertJsonPath('data.user.user.phone', '0501234567');

        foreach (["\0".'0501234567', "0501234567\0", "0501234567\u{00A0}", '+966501234567'] as $i => $phone) {
            $this->postJson('/api/users', [
                'name' => 'غير صالح',
                'email' => "bad{$i}@example.com",
                'phone' => $phone,
                'role' => User::ROLE_EMPLOYEE,
                'password' => 'Password8',
                'password_confirmation' => 'Password8',
            ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
    }

    public function test_password_requires_uppercase_lowercase_and_number(): void
    {
        [$administrator] = $this->createCompanyAdministrator();

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'مستخدم',
            'email' => 'user@example.com',
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_plan_user_limit_is_enforced_at_five_tenant_seats(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        foreach (range(1, 4) as $index) {
            $user = User::factory()->create([
                'role' => User::ROLE_EMPLOYEE,
                'status' => User::STATUS_ACTIVE,
            ]);

            TenantUser::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => TenantUser::STATUS_ACTIVE,
                'joined_at' => now(),
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
        }

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'مستخدم سادس',
            'email' => 'sixth@example.com',
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'user_seat_limit_reached')
            ->assertJsonPath('error.limit', 5)
            ->assertJsonPath('error.current', 5);
    }

    public function test_removed_membership_does_not_consume_a_seat(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        foreach (range(1, 4) as $index) {
            $user = User::factory()->create([
                'role' => User::ROLE_EMPLOYEE,
                'status' => User::STATUS_ACTIVE,
            ]);

            TenantUser::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => $index === 4
                    ? TenantUser::STATUS_REMOVED
                    : TenantUser::STATUS_ACTIVE,
                'joined_at' => now(),
                'removed_at' => $index === 4 ? now() : null,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
        }

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'مستخدم بديل',
            'email' => 'replacement@example.com',
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ])->assertCreated();
    }

    public function test_missing_current_license_fails_closed_for_user_creation(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();
        TenantLicense::query()->where('tenant_id', $tenant->id)->delete();

        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'مستخدم جديد',
            'email' => 'blocked@example.com',
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Password8',
            'password_confirmation' => 'Password8',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'user_seat_limit_unavailable');
    }

    public function test_administrator_can_update_user(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'status' => User::STATUS_ACTIVE,
        ]);

        $membership = TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Sanctum::actingAs($administrator);

        $this->putJson(
            "/api/users/{$membership->id}",
            [
                'name' => 'مدير المشاريع',
                'role' => User::ROLE_PROJECT_MANAGER,
                'status' => TenantUser::STATUS_PAUSED,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.user.user.role', User::ROLE_PROJECT_MANAGER)
            ->assertJsonPath('data.user.status', TenantUser::STATUS_PAUSED);
    }

    public function test_administrator_can_remove_membership(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'status' => User::STATUS_ACTIVE,
        ]);

        $membership = TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Sanctum::actingAs($administrator);

        $this->deleteJson("/api/users/{$membership->id}")->assertOk();

        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'status' => TenantUser::STATUS_REMOVED,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => User::STATUS_ARCHIVED,
        ]);
    }

    public function test_non_administrator_cannot_list_or_show_users(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        $salesUser = User::factory()->create([
            'role' => User::ROLE_SALES,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $salesUser->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Sanctum::actingAs($salesUser);

        $salesMembership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $salesUser->id)
            ->firstOrFail();

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson("/api/users/{$salesMembership->id}")
            ->assertForbidden();
    }

    public function test_non_administrator_cannot_update_or_remove_users(): void
    {
        [$administrator, $tenant] = $this->createCompanyAdministrator();

        $salesUser = User::factory()->create([
            'role' => User::ROLE_SALES,
            'status' => User::STATUS_ACTIVE,
        ]);

        $salesMembership = TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $salesUser->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        Sanctum::actingAs($salesUser);

        $this->putJson("/api/users/{$salesMembership->id}", [
            'name' => 'اسم معدل',
        ])->assertForbidden();

        $this->deleteJson("/api/users/{$salesMembership->id}")
            ->assertForbidden();
    }

    private function createCompanyAdministrator(
        string $role = User::ROLE_ADMINISTRATOR,
    ): array {
        $tenant = Tenant::query()->create([
            'name' => 'شركة أفق',
            'slug' => 'ufq',
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
        ]);

        $plan = Plan::query()->create([
            'key' => 'pilot_full',
            'name_ar' => 'الباقة التجريبية الكاملة',
            'name_en' => 'Pilot Full',
            'status' => Plan::STATUS_ACTIVE,
            'users_limit' => 5,
        ]);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => TenantLicense::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'grace_ends_at' => null,
        ]);

        $administrator = User::factory()->create([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $administrator->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        return [$administrator, $tenant];
    }
}
