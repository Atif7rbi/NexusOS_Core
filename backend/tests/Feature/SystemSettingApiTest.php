<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class SystemSettingApiTest extends ApiTestCase
{
    public function test_authenticated_tenant_member_can_read_its_company_profile(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        $membership = TenantUser::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
        $tenant = Tenant::query()->findOrFail($membership->tenant_id);

        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonPath('data.company_name_ar', $tenant->name)
            ->assertJsonPath('data.timezone', $tenant->timezone)
            ->assertJsonPath('data.currency', $tenant->currency);

        $this->assertDatabaseHas('system_settings', [
            'tenant_id' => $membership->tenant_id,
            'company_name_ar' => $tenant->name,
        ]);
    }

    public function test_guest_cannot_read_or_update_system_settings(): void
    {
        $this->getJson('/api/system-settings')->assertUnauthorized();
        $this->putJson('/api/system-settings', [
            'short_name_ar' => 'اختبار',
        ])->assertUnauthorized();
    }

    public function test_authorized_administrator_can_update_company_profile(): void
    {
        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/system-settings', [
            'company_name_ar' => 'شركة الاختبار',
            'company_name_en' => 'Test Company',
            'short_name_ar' => 'اختبار',
            'short_name_en' => 'Test',
            'phone' => ' ٠٥٠٠٠٠٠٠٠٠ ',
            'email' => 'settings@example.test',
            'website' => 'https://example.test',
            'address' => 'الرياض',
            'commercial_registration' => '1234567890',
            'vat_number' => '300000000000003',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.company_name_ar', 'شركة الاختبار')
            ->assertJsonPath('data.short_name_ar', 'اختبار')
            ->assertJsonPath('data.phone', '0500000000')
            ->assertJsonPath('data.email', 'settings@example.test')
            ->assertJsonPath('data.vat_number', '300000000000003');

        $membership = TenantUser::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('system_settings', [
            'tenant_id' => $membership->tenant_id,
            'company_name_ar' => 'شركة الاختبار',
            'commercial_registration' => '1234567890',
        ]);
    }

    public function test_non_administrator_cannot_mutate_company_profile(): void
    {
        Sanctum::actingAs($this->createActiveUser([
            'role' => User::ROLE_SALES,
        ]));

        $this->putJson('/api/system-settings', [
            'company_name_ar' => 'شركة غير مصرح بها',
        ])->assertForbidden();
    }

    public function test_company_profile_is_scoped_to_the_authenticated_tenant(): void
    {
        $firstAdmin = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $secondAdmin = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        Sanctum::actingAs($firstAdmin);
        $this->putJson('/api/system-settings', [
            'company_name_ar' => 'شركة المستأجر الأول',
            'short_name_ar' => 'الأول',
        ])->assertOk();

        Sanctum::actingAs($secondAdmin);
        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonMissing([
                'company_name_ar' => 'شركة المستأجر الأول',
            ]);

        $firstMembership = TenantUser::query()->where('user_id', $firstAdmin->id)->firstOrFail();
        $secondMembership = TenantUser::query()->where('user_id', $secondAdmin->id)->firstOrFail();
        self::assertNotSame($firstMembership->tenant_id, $secondMembership->tenant_id);
        self::assertSame('شركة المستأجر الأول', SystemSetting::forTenant((string) $firstMembership->tenant_id)->company_name_ar);
        self::assertNotSame('شركة المستأجر الأول', SystemSetting::forTenant((string) $secondMembership->tenant_id)->company_name_ar);
    }

    public function test_new_tenant_settings_are_derived_from_that_tenant_not_ufq_identity(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'شركة المستأجر الجديد',
            'timezone' => 'UTC',
            'locale' => 'en-US',
            'currency' => 'USD',
        ]);

        $settings = SystemSetting::forTenant((string) $tenant->id);

        self::assertSame('شركة المستأجر الجديد', $settings->company_name_ar);
        self::assertSame('شركة المستأجر الجديد', $settings->short_name_ar);
        self::assertSame('UTC', $settings->timezone);
        self::assertSame('en-US', $settings->language);
        self::assertSame('USD', $settings->currency);
        self::assertNotSame('شركة أفق السكنية', $settings->company_name_ar);
        self::assertNotSame('Ufq', $settings->short_name_en);
    }

    public function test_existing_tenant_company_profile_is_not_replaced_by_new_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $existing = SystemSetting::query()->create([
            'tenant_id' => $tenant->id,
            'company_name_ar' => 'شركة أفق السكنية',
            'short_name_ar' => 'أفق',
        ]);

        $resolved = SystemSetting::forTenant((string) $tenant->id);

        self::assertSame($existing->id, $resolved->id);
        self::assertSame('شركة أفق السكنية', $resolved->company_name_ar);
        self::assertSame('أفق', $resolved->short_name_ar);
    }

    public function test_company_profile_validation_and_phone_normalization_are_preserved(): void
    {
        Sanctum::actingAs($this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]));

        $this->putJson('/api/system-settings', [
            'company_name_ar' => '',
            'short_name_ar' => '',
            'email' => 'invalid-email',
            'website' => 'not-a-url',
            'phone' => '+966500000000',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'company_name_ar',
            'short_name_ar',
            'email',
            'website',
            'phone',
        ]);
    }

    public function test_update_rejects_invalid_branding_and_regional_values(): void
    {
        Sanctum::actingAs($this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]));

        $this->putJson('/api/system-settings', [
            'primary_color' => 'blue',
            'secondary_color' => '#12345',
            'timezone' => 'Invalid/Timezone',
            'currency' => 'SA',
            'email' => 'invalid-email',
            'website' => 'not-a-url',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'primary_color',
            'secondary_color',
            'timezone',
            'currency',
            'email',
            'website',
        ]);
    }

    public function test_partial_update_preserves_existing_settings_values(): void
    {
        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        Sanctum::actingAs($user);

        $membership = TenantUser::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
        $settings = SystemSetting::forTenant((string) $membership->tenant_id);

        $this->putJson('/api/system-settings', [
            'phone' => ' ٠٥٠٠٠٠٠٠٠٠ ',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '0500000000')
            ->assertJsonPath('data.company_name_ar', $settings->company_name_ar)
            ->assertJsonPath('data.currency', $settings->currency);
    }

    public function test_phone_raw_http_boundary_is_preserved(): void
    {
        Sanctum::actingAs($this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]));

        foreach ([
            "\0".'0500000000',
            "0500000000\0",
            "0500000000\u{00A0}",
            '+966500000000',
        ] as $phone) {
            $this->putJson('/api/system-settings', ['phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }

        $this->putJson('/api/system-settings', [
            'phone' => " \t\r\n\v\f",
        ])->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_demo_mode_rejects_central_company_profile_mutation(): void
    {
        config(['nexusos.demo_mode' => true]);
        Sanctum::actingAs($this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]));

        $this->putJson('/api/system-settings', [
            'company_name_ar' => 'لا يجب حفظها',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'company_profile_demo_read_only');
    }

    public function test_demo_mode_returns_aswar_baseline_without_mutating_tenant_settings(): void
    {
        $user = $this->createActiveUser();
        $membership = TenantUser::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
        $storedSettings = SystemSetting::forTenant(
            (string) $membership->tenant_id,
        );
        $storedCompanyName = $storedSettings->company_name_ar;

        config([
            'nexusos.demo_mode' => true,
            'nexusos.demo_company_profile' => [
                'company_name_ar' => 'شركة أسوار للتطوير العقاري',
                'company_name_en' => 'Aswar Real Estate Development',
                'short_name_ar' => 'أسوار',
                'short_name_en' => 'Aswar',
            ],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonPath(
                'data.company_name_ar',
                'شركة أسوار للتطوير العقاري',
            )
            ->assertJsonPath(
                'data.company_name_en',
                'Aswar Real Estate Development',
            )
            ->assertJsonPath('data.short_name_ar', 'أسوار')
            ->assertJsonPath('data.short_name_en', 'Aswar');

        $storedSettings->refresh();
        self::assertSame($storedCompanyName, $storedSettings->company_name_ar);
    }
}
