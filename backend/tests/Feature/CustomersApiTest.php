<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Customers\Actions\CreateCustomerAction;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

final class CustomersApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_access_customers(): void
    {
        $this->getJson('/api/customers')
            ->assertUnauthorized();

        $this->postJson('/api/customers', [])
            ->assertUnauthorized();

        $this->getJson('/api/customers/01J00000000000000000000000')
            ->assertUnauthorized();

        $this->patchJson(
            '/api/customers/01J00000000000000000000000',
            []
        )->assertUnauthorized();

        $this->patchJson(
            '/api/customers/01J00000000000000000000000/archive'
        )->assertUnauthorized();

        $this->patchJson(
            '/api/customers/01J00000000000000000000000/restore'
        )->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_individual_customer(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'محمد عبدالله',
            'phone' => '0500000001',
            'email' => 'mohammed@example.com',
            'national_id' => '1010000001',
            'city' => 'الرياض',
            'address' => 'حي الياسمين',
            'notes' => 'عميل مهتم بشراء وحدة سكنية.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'تم إنشاء العميل بنجاح.'
            )
            ->assertJsonPath(
                'data.customer.tenant_id',
                $tenantId
            )
            ->assertJsonPath(
                'data.customer.type',
                'individual'
            )
            ->assertJsonPath(
                'data.customer.category',
                'buyer'
            )
            ->assertJsonPath(
                'data.customer.status',
                'lead'
            )
            ->assertJsonPath(
                'data.customer.name',
                'محمد عبدالله'
            )
            ->assertJsonPath(
                'data.customer.national_id',
                '1010000001'
            )
            ->assertJsonPath(
                'data.customer.commercial_registration_number',
                null
            )
            ->assertJsonPath(
                'data.customer.created_by',
                $user->id
            )
            ->assertJsonPath(
                'data.customer.updated_by',
                $user->id
            );

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'lead',
            'name' => 'محمد عبدالله',
            'phone' => '0500000001',
            'national_id' => '1010000001',
            'commercial_registration_number' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
    public function test_phone_normalization_and_raw_http_middleware_boundary(): void
    {
        $user = $this->createActiveUser(); Sanctum::actingAs($user);
        $this->postJson('/api/customers', ['type'=>'individual','category'=>'buyer','name'=>'مطبع','phone'=>"\t٠٥٠١٢٣٤٥٦٧ "])
            ->assertCreated()->assertJsonPath('data.customer.phone','0501234567');
        foreach (["\0".'0501234567', "0501234567\0", "0501234567\u{00A0}", '+966501234567'] as $phone) {
            $this->postJson('/api/customers', ['type'=>'individual','category'=>'buyer','name'=>'غير صالح','phone'=>$phone])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $customer = app(CreateCustomerAction::class)->execute($this->tenantIdFor($user), $user->id,
            ['type'=>'individual','category'=>'buyer','name'=>'فارسي','phone'=>'۰۵۰۱۲۳۴۵۶۸']);
        self::assertSame('0501234568', $customer->phone);
    }

    public function test_authenticated_user_can_create_company_customer(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customers', [
            'type' => 'company',
            'category' => 'investor',
            'status' => 'customer',
            'name' => 'شركة المدار العقارية',
            'phone' => '0550000001',
            'email' => 'info@almadar.example',
            'commercial_registration_number' => '1010999999',
            'city' => 'الرياض',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.customer.type',
                'company'
            )
            ->assertJsonPath(
                'data.customer.category',
                'investor'
            )
            ->assertJsonPath(
                'data.customer.status',
                'customer'
            )
            ->assertJsonPath(
                'data.customer.national_id',
                null
            )
            ->assertJsonPath(
                'data.customer.commercial_registration_number',
                '1010999999'
            );

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenantId,
            'type' => 'company',
            'category' => 'investor',
            'status' => 'customer',
            'name' => 'شركة المدار العقارية',
            'commercial_registration_number' => '1010999999',
            'national_id' => null,
        ]);
    }

    public function test_customer_creation_rejects_invalid_business_values(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'type' => 'invalid',
            'category' => 'invalid',
            'status' => 'archived',
            'name' => '',
            'phone' => '',
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'type',
                'category',
                'status',
                'name',
                'phone',
                'email',
            ]);
    }

    public function test_individual_cannot_have_commercial_registration_number(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'عميل فرد',
            'phone' => '0500000002',
            'commercial_registration_number' => '1010123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'commercial_registration_number',
            ]);
    }

    public function test_company_cannot_have_national_id(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'type' => 'company',
            'category' => 'investor',
            'name' => 'شركة اختبار',
            'phone' => '0550000002',
            'national_id' => '1010000002',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'national_id',
            ]);
    }

    public function test_unique_identifiers_are_enforced_inside_same_tenant(): void
    {
        $user = $this->createActiveUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'العميل الأول',
            'phone' => '0500000003',
            'national_id' => '1010000003',
        ])->assertCreated();

        $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'investor',
            'name' => 'العميل الثاني',
            'phone' => '0500000003',
            'national_id' => '1010000003',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'phone',
                'national_id',
            ]);

        $this->postJson('/api/customers', [
            'type' => 'company',
            'category' => 'owner',
            'name' => 'الشركة الأولى',
            'phone' => '0550000003',
            'commercial_registration_number' => '1010000003',
        ])->assertCreated();

        $this->postJson('/api/customers', [
            'type' => 'company',
            'category' => 'broker',
            'name' => 'الشركة الثانية',
            'phone' => '0550000004',
            'commercial_registration_number' => '1010000003',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'commercial_registration_number',
            ]);
    }

    public function test_same_identifiers_are_allowed_between_different_tenants(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        Sanctum::actingAs($tenantAUser);

        $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'عميل الشركة الأولى',
            'phone' => '0500000004',
            'national_id' => '1010000004',
        ])->assertCreated();

        Sanctum::actingAs($tenantBUser);

        $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => 'عميل الشركة الثانية',
            'phone' => '0500000004',
            'national_id' => '1010000004',
        ])->assertCreated();

        $this->assertSame(
            2,
            Customer::query()
                ->whereIn('tenant_id', [
                    $this->tenantIdFor($tenantAUser),
                    $this->tenantIdFor($tenantBUser),
                ])
                ->where('phone', '0500000004')
                ->where('national_id', '1010000004')
                ->count(),
        );

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenantIdFor($tenantAUser),
            'phone' => '0500000004',
            'national_id' => '1010000004',
        ]);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenantIdFor($tenantBUser),
            'phone' => '0500000004',
            'national_id' => '1010000004',
        ]);
    }

    public function test_authenticated_user_can_list_and_show_own_customers(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $first = $this->createCustomer($tenantId, $user, [
            'name' => 'العميل الأول',
            'phone' => '0500000005',
        ]);

        $second = $this->createCustomer($tenantId, $user, [
            'name' => 'العميل الثاني',
            'phone' => '0500000006',
            'category' => 'investor',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(
                2,
                'data.customers.data'
            )
            ->assertJsonFragment([
                'id' => $first->id,
                'name' => 'العميل الأول',
            ])
            ->assertJsonFragment([
                'id' => $second->id,
                'name' => 'العميل الثاني',
            ]);

        $this->getJson("/api/customers/{$first->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.customer.id',
                $first->id
            )
            ->assertJsonPath(
                'data.customer.tenant_id',
                $tenantId
            )
            ->assertJsonPath(
                'data.customer.name',
                'العميل الأول'
            );
    }

    public function test_customer_index_supports_search_and_filters(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $target = $this->createCustomer($tenantId, $user, [
            'type' => 'company',
            'category' => 'investor',
            'status' => 'customer',
            'name' => 'شركة النخبة للاستثمار',
            'phone' => '0555550001',
            'email' => 'elite@example.com',
            'national_id' => null,
            'commercial_registration_number' => '7000000001',
        ]);

        $this->createCustomer($tenantId, $user, [
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'lead',
            'name' => 'سالم المشتري',
            'phone' => '0505550002',
            'national_id' => '1010555002',
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/customers'
            .'?search='.urlencode('النخبة')
            .'&type=company'
            .'&category=investor'
            .'&status=customer'
        )
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.customers.data'
            )
            ->assertJsonPath(
                'data.customers.data.0.id',
                $target->id
            )
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.customers', 1)
            ->assertJsonPath('data.summary.leads', 1)
            ->assertJsonPath('data.summary.archived', 0);
    }

    public function test_authenticated_user_can_update_customer(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $customer = $this->createCustomer(
            $tenantId,
            $user
        );

        Sanctum::actingAs($user);

        $this->patchJson("/api/customers/{$customer->id}", [
            'category' => 'investor',
            'status' => 'customer',
            'name' => 'العميل بعد التحديث',
            'phone' => '0500000010',
            'email' => 'updated@example.com',
            'city' => 'جدة',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تم تحديث العميل بنجاح.'
            )
            ->assertJsonPath(
                'data.customer.name',
                'العميل بعد التحديث'
            )
            ->assertJsonPath(
                'data.customer.category',
                'investor'
            )
            ->assertJsonPath(
                'data.customer.status',
                'customer'
            )
            ->assertJsonPath(
                'data.customer.updated_by',
                $user->id
            );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'tenant_id' => $tenantId,
            'name' => 'العميل بعد التحديث',
            'category' => 'investor',
            'status' => 'customer',
            'phone' => '0500000010',
            'city' => 'جدة',
            'updated_by' => $user->id,
        ]);
    }

    public function test_changing_customer_type_clears_incompatible_identity_field(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $individual = $this->createCustomer(
            $tenantId,
            $user,
            [
                'type' => 'individual',
                'national_id' => '1010000011',
                'commercial_registration_number' => null,
                'phone' => '0500000011',
            ]
        );

        Sanctum::actingAs($user);

        $this->patchJson("/api/customers/{$individual->id}", [
            'type' => 'company',
            'commercial_registration_number' => '1010000011',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.customer.type',
                'company'
            )
            ->assertJsonPath(
                'data.customer.national_id',
                null
            )
            ->assertJsonPath(
                'data.customer.commercial_registration_number',
                '1010000011'
            );

        $company = $this->createCustomer(
            $tenantId,
            $user,
            [
                'type' => 'company',
                'name' => 'شركة للتحويل',
                'phone' => '0550000012',
                'national_id' => null,
                'commercial_registration_number' => '1010000012',
            ]
        );

        $this->patchJson("/api/customers/{$company->id}", [
            'type' => 'individual',
            'national_id' => '1010000012',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.customer.type',
                'individual'
            )
            ->assertJsonPath(
                'data.customer.national_id',
                '1010000012'
            )
            ->assertJsonPath(
                'data.customer.commercial_registration_number',
                null
            );
    }

    public function test_authenticated_user_can_archive_customer(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $customer = $this->createCustomer(
            $tenantId,
            $user
        );

        Sanctum::actingAs($user);

        $this->patchJson(
            "/api/customers/{$customer->id}/archive"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تمت أرشفة العميل بنجاح.'
            )
            ->assertJsonPath(
                'data.customer.status',
                'archived'
            )
            ->assertJsonPath(
                'data.customer.archived_by',
                $user->id
            )
            ->assertJsonPath(
                'data.customer.updated_by',
                $user->id
            )
            ->assertJsonPath(
                'data.customer.archived_at',
                fn (mixed $value): bool =>
                    is_string($value)
                    && $value !== ''
            );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'archived',
            'archived_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->assertNotNull(
            Customer::query()
                ->findOrFail($customer->id)
                ->archived_at
        );
    }

    public function test_archived_customer_cannot_be_updated_or_archived_again(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $customer = $this->createCustomer(
            $tenantId,
            $user,
            [
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $user->id,
            ]
        );

        Sanctum::actingAs($user);

        $this->patchJson("/api/customers/{$customer->id}", [
            'name' => 'اسم غير مسموح',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer',
            ]);

        $this->patchJson(
            "/api/customers/{$customer->id}/archive"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer',
            ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'عميل اختباري',
            'status' => 'archived',
        ]);
    }

    public function test_authenticated_user_can_restore_archived_customer_to_inactive(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $customer = $this->createCustomer(
            $tenantId,
            $user,
            [
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $user->id,
            ]
        );

        Sanctum::actingAs($user);

        $this->patchJson(
            "/api/customers/{$customer->id}/restore"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تمت استعادة العميل بنجاح.'
            )
            ->assertJsonPath(
                'data.customer.status',
                'inactive'
            )
            ->assertJsonPath(
                'data.customer.archived_at',
                null
            )
            ->assertJsonPath(
                'data.customer.archived_by',
                null
            )
            ->assertJsonPath(
                'data.customer.restored_by',
                $user->id
            )
            ->assertJsonPath(
                'data.customer.updated_by',
                $user->id
            );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'inactive',
            'archived_at' => null,
            'archived_by' => null,
            'restored_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_non_archived_customer_cannot_be_restored(): void
    {
        $user = $this->createActiveUser();
        $tenantId = $this->tenantIdFor($user);

        $customer = $this->createCustomer(
            $tenantId,
            $user
        );

        Sanctum::actingAs($user);

        $this->patchJson(
            "/api/customers/{$customer->id}/restore"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'customer',
            ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'lead',
            'restored_by' => null,
        ]);
    }

    public function test_tenant_cannot_show_customer_belonging_to_another_tenant(): void
    {
        [$tenantAUser, $tenantBCustomer] =
            $this->crossTenantScenario();

        Sanctum::actingAs($tenantAUser);

        $this->getJson(
            "/api/customers/{$tenantBCustomer->id}"
        )->assertNotFound();
    }

    public function test_tenant_cannot_update_customer_belonging_to_another_tenant(): void
    {
        [$tenantAUser, $tenantBCustomer] =
            $this->crossTenantScenario();

        Sanctum::actingAs($tenantAUser);

        $this->patchJson(
            "/api/customers/{$tenantBCustomer->id}",
            [
                'name' => 'محاولة تعديل غير مصرح بها',
            ]
        )->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $tenantBCustomer->id,
            'name' => 'عميل اختباري',
        ]);
    }

    public function test_tenant_cannot_archive_customer_belonging_to_another_tenant(): void
    {
        [$tenantAUser, $tenantBCustomer] =
            $this->crossTenantScenario();

        Sanctum::actingAs($tenantAUser);

        $this->patchJson(
            "/api/customers/{$tenantBCustomer->id}/archive"
        )->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $tenantBCustomer->id,
            'status' => 'lead',
            'archived_by' => null,
        ]);
    }

    public function test_tenant_cannot_restore_customer_belonging_to_another_tenant(): void
    {
        [$tenantAUser, $tenantBCustomer] =
            $this->crossTenantScenario([
                'status' => 'archived',
                'archived_at' => now(),
            ]);

        Sanctum::actingAs($tenantAUser);

        $this->patchJson(
            "/api/customers/{$tenantBCustomer->id}/restore"
        )->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $tenantBCustomer->id,
            'status' => 'archived',
        ]);
    }

    public function test_customer_index_does_not_leak_other_tenant_records(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        $tenantACustomer = $this->createCustomer(
            $this->tenantIdFor($tenantAUser),
            $tenantAUser,
            [
                'name' => 'عميل الشركة الأولى',
                'phone' => '0500000020',
            ]
        );

        $tenantBCustomer = $this->createCustomer(
            $this->tenantIdFor($tenantBUser),
            $tenantBUser,
            [
                'name' => 'عميل الشركة الثانية',
                'phone' => '0500000021',
            ]
        );

        Sanctum::actingAs($tenantAUser);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(
                1,
                'data.customers.data'
            )
            ->assertJsonPath(
                'data.customers.data.0.id',
                $tenantACustomer->id
            )
            ->assertJsonMissing([
                'id' => $tenantBCustomer->id,
            ])
            ->assertJsonMissing([
                'name' => 'عميل الشركة الثانية',
            ]);
    }

    private function tenantIdFor(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->valueOrFail('tenant_id');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createCustomer(
        string $tenantId,
        User $actor,
        array $attributes = [],
    ): Customer {
        return Customer::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'lead',
            'name' => 'عميل اختباري',
            'phone' => '05'.fake()->unique()->numerify('########'),
            'email' => null,
            'national_id' => fake()
                ->unique()
                ->numerify('10########'),
            'commercial_registration_number' => null,
            'city' => 'الرياض',
            'address' => null,
            'notes' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'archived_by' => null,
            'restored_by' => null,
            'archived_at' => null,
        ], $attributes));
    }

    /**
     * @param array<string, mixed> $customerAttributes
     * @return array{0: User, 1: Customer}
     */
    private function crossTenantScenario(
        array $customerAttributes = [],
    ): array {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();

        if (
            ($customerAttributes['status'] ?? null)
            === 'archived'
        ) {
            $customerAttributes['archived_at'] ??= now();
            $customerAttributes['archived_by'] ??=
                $tenantBUser->id;
        }

        $tenantBCustomer = $this->createCustomer(
            $this->tenantIdFor($tenantBUser),
            $tenantBUser,
            $customerAttributes
        );

        return [
            $tenantAUser,
            $tenantBCustomer,
        ];
    }
}
