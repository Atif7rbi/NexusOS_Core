<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\TenantUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class SystemSettingApiTest extends ApiTestCase
{
    public function test_authenticated_tenant_member_can_read_its_company_profile(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonPath('data.company_name_ar', 'شركة أفق السكنية')
            ->assertJsonPath('data.short_name_ar', 'أفق')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh');

        $membership = TenantUser::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('system_settings', [
            'tenant_id' => $membership->tenant_id,
            'company_name_ar' => 'شركة أفق السكنية',
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
}
