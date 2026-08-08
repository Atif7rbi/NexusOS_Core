<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Contracts\Enums\ContractStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class CollectionsIndexApiTest extends ApiTestCase
{
    private int $fixtureSequence = 0;

    public function test_it_returns_all_valid_schedule_states_and_tenant_wide_summary(): void
    {
        [$tenantId, $userId] = $this->authenticate();

        $absent = $this->createContract($tenantId, $userId, 'عميل بدون جدول', 'A-101');
        $draft = $this->createContract($tenantId, $userId, 'عميل مسودة', 'A-102');
        $scheduled = $this->createContract($tenantId, $userId, 'عميل معتمد', 'A-103');
        $cancelled = $this->createContract($tenantId, $userId, 'عميل ملغى', 'A-104');
        $invalid = $this->createContract($tenantId, $userId, 'عميل غير صالح', 'A-105');

        $this->createCollection($tenantId, $draft, $userId, CollectionStatus::Draft, 1, '300.25');
        $scheduledCollection = $this->createCollection(
            $tenantId,
            $scheduled,
            $userId,
            CollectionStatus::Scheduled,
            1,
            '700.50',
        );
        $this->createCollection($tenantId, $scheduled, $userId, CollectionStatus::Cancelled, 2, '99.99');
        $this->createCollection($tenantId, $cancelled, $userId, CollectionStatus::Cancelled, 1, '400.00');
        $this->createCollection($tenantId, $invalid, $userId, CollectionStatus::Draft, 1, '100.00');
        $this->createCollection($tenantId, $invalid, $userId, CollectionStatus::Scheduled, 2, '200.00');

        $response = $this->getJson('/api/collections?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.summary.total_contracts', 4)
            ->assertJsonPath('data.summary.absent_count', 1)
            ->assertJsonPath('data.summary.draft_count', 1)
            ->assertJsonPath('data.summary.scheduled_count', 1)
            ->assertJsonPath('data.summary.cancelled_count', 1);

        $items = collect($response->json('data.items'))->keyBy('contract_id');

        $this->assertSame('absent', $items[$absent]['schedule_state']);
        $this->assertSame('0.00', $items[$absent]['schedule_active_total']);
        $this->assertNull($items[$absent]['next_scheduled_collection']);
        $this->assertSame('draft', $items[$draft]['schedule_state']);
        $this->assertSame('300.25', $items[$draft]['schedule_active_total']);
        $this->assertNull($items[$draft]['next_scheduled_collection']);
        $this->assertSame('scheduled', $items[$scheduled]['schedule_state']);
        $this->assertSame('700.50', $items[$scheduled]['schedule_active_total']);
        $this->assertSame(
            $scheduledCollection,
            $items[$scheduled]['next_scheduled_collection']['id'],
        );
        $this->assertSame('cancelled', $items[$cancelled]['schedule_state']);
        $this->assertSame('0.00', $items[$cancelled]['schedule_active_total']);
        $this->assertNull($items[$cancelled]['next_scheduled_collection']);
        $this->assertFalse($items->has($invalid));
    }

    public function test_search_status_and_schedule_filters_compose_without_changing_summary(): void
    {
        [$tenantId, $userId] = $this->authenticate();

        $matching = $this->createContract(
            $tenantId,
            $userId,
            'شركة الندى العقارية',
            'B-201',
            ContractStatus::Active,
        );
        $this->createCollection($tenantId, $matching, $userId, CollectionStatus::Scheduled, 1, '1000.00');

        $customerOnly = $this->createContract(
            $tenantId,
            $userId,
            'شركة الندى العقارية',
            'C-301',
            ContractStatus::Draft,
        );
        $this->createCollection($tenantId, $customerOnly, $userId, CollectionStatus::Draft, 1, '500.00');

        $unitOnly = $this->createContract(
            $tenantId,
            $userId,
            'عميل مختلف',
            'NADA-401',
            ContractStatus::Active,
        );
        $this->createCollection($tenantId, $unitOnly, $userId, CollectionStatus::Draft, 1, '250.00');

        $baselineSummary = $this->getJson('/api/collections')
            ->assertOk()
            ->json('data.summary');

        foreach ([
            '/api/collections?search='.urlencode('الندى'),
            '/api/collections?status=active',
            '/api/collections?schedule_state=scheduled',
        ] as $url) {
            $this->assertSame(
                $baselineSummary,
                $this->getJson($url)
                    ->assertOk()
                    ->json('data.summary'),
            );
        }

        $filtered = $this->getJson(
            '/api/collections?search='.urlencode('  الندى  ')
            .'&status=active&schedule_state=scheduled',
        )->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.contract_id', $matching)
            ->json('data.summary');

        $this->assertSame($baselineSummary, $filtered);

        $this->getJson('/api/collections?search='.urlencode('nada-4'))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.contract_id', $unitOnly);
    }

    public function test_project_search_is_tenant_scoped_and_does_not_change_summary(): void
    {
        [$tenantId, $userId] = $this->authenticate();

        $matching = $this->createContract(
            $tenantId,
            $userId,
            'عميل المشروع المطابق',
            'PROJECT-101',
            projectName: 'واحة النخيل السكنية',
        );
        $this->createContract(
            $tenantId,
            $userId,
            'عميل مشروع آخر',
            'PROJECT-102',
            projectName: 'روابي الشرق',
        );

        $otherUser = $this->createActiveUser();
        $otherTenantId = (string) TenantUser::query()
            ->where('user_id', $otherUser->id)
            ->value('tenant_id');
        $this->createContract(
            $otherTenantId,
            $otherUser->id,
            'عميل مستأجر آخر',
            'PROJECT-103',
            projectName: 'واحة النخيل الدولية',
        );

        $baselineSummary = $this->getJson('/api/collections')
            ->assertOk()
            ->json('data.summary');

        $response = $this->getJson(
            '/api/collections?search='.urlencode('  واحة النخيل  '),
        )->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.contract_id', $matching);

        $this->assertSame(
            $baselineSummary,
            $response->json('data.summary'),
        );
    }

    public function test_pagination_ordering_and_decimal_representation_are_exact(): void
    {
        [$tenantId, $userId] = $this->authenticate();
        $older = CarbonImmutable::parse('2026-07-01 10:00:00');
        $newer = CarbonImmutable::parse('2026-07-02 10:00:00');

        $olderId = $this->createContract(
            $tenantId,
            $userId,
            'العميل الأقدم',
            'D-101',
            createdAt: $older,
            totalAmount: '12.30',
        );
        $newerId = $this->createContract(
            $tenantId,
            $userId,
            'العميل الأحدث',
            'D-102',
            createdAt: $newer,
            totalAmount: '45.60',
        );

        $this->getJson('/api/collections?page=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.contract_id', $newerId)
            ->assertJsonPath('data.items.0.contract_total_amount', '45.60')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/collections?page=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.contract_id', $olderId)
            ->assertJsonPath('data.items.0.contract_total_amount', '12.30');
    }

    public function test_next_scheduled_collection_is_selected_and_sorted_deterministically(): void
    {
        [$tenantId, $userId] = $this->authenticate();
        $firstContract = $this->createContract(
            $tenantId,
            $userId,
            'عميل التحصيل الأول',
            'NEXT-101',
        );
        $secondContract = $this->createContract(
            $tenantId,
            $userId,
            'عميل التحصيل الثاني',
            'NEXT-102',
        );

        $this->createCollection(
            $tenantId,
            $firstContract,
            $userId,
            CollectionStatus::Cancelled,
            1,
            '10.00',
            '2026-08-09',
            'بند ملغى',
        );
        $expectedId = $this->createCollection(
            $tenantId,
            $firstContract,
            $userId,
            CollectionStatus::Scheduled,
            2,
            '65.50',
            '2026-09-01',
            'الدفعة الأولى',
        );
        $this->createCollection(
            $tenantId,
            $firstContract,
            $userId,
            CollectionStatus::Scheduled,
            3,
            '70.00',
            '2026-09-01',
            'الدفعة الثانية',
        );
        $this->createCollection(
            $tenantId,
            $secondContract,
            $userId,
            CollectionStatus::Scheduled,
            1,
            '80.00',
            '2026-08-20',
            'دفعة أقرب',
        );

        $baselineSummary = $this->getJson('/api/collections')
            ->assertOk()
            ->json('data.summary');

        $response = $this->getJson(
            '/api/collections?schedule_state=scheduled'
            .'&sort=next_scheduled_collection&per_page=5',
        )->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.contract_id', $secondContract)
            ->assertJsonPath('data.items.1.contract_id', $firstContract)
            ->assertJsonPath('data.items.1.next_scheduled_collection.id', $expectedId)
            ->assertJsonPath('data.items.1.next_scheduled_collection.title', 'الدفعة الأولى')
            ->assertJsonPath('data.items.1.next_scheduled_collection.amount', '65.50')
            ->assertJsonPath('data.items.1.next_scheduled_collection.due_date', '2026-09-01');

        $this->assertSame(
            $baselineSummary,
            $response->json('data.summary'),
        );
    }

    public function test_invalid_parameters_return_validation_errors_and_unknown_parameters_are_ignored(): void
    {
        $this->authenticate();

        foreach ([
            '/api/collections?page=0',
            '/api/collections?page=not-an-integer',
            '/api/collections?per_page=101',
            '/api/collections?per_page=not-an-integer',
            '/api/collections?status=unknown',
            '/api/collections?schedule_state=invalid',
            '/api/collections?sort=invalid',
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }

        $this->getJson('/api/collections?unknown=value')->assertOk();
    }

    public function test_empty_tenant_returns_an_empty_page_and_zero_summary(): void
    {
        $this->authenticate();

        $this->getJson('/api/collections')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonPath('data.summary.total_contracts', 0)
            ->assertJsonPath('data.summary.scheduled_count', 0)
            ->assertJsonPath('data.summary.draft_count', 0)
            ->assertJsonPath('data.summary.absent_count', 0)
            ->assertJsonPath('data.summary.cancelled_count', 0);
    }

    public function test_contracts_from_another_tenant_are_never_returned(): void
    {
        [$tenantId, $userId] = $this->authenticate();
        $visible = $this->createContract($tenantId, $userId, 'عميل المستأجر', 'E-101');
        $visibleCollection = $this->createCollection(
            $tenantId,
            $visible,
            $userId,
            CollectionStatus::Scheduled,
            1,
            '100.00',
        );

        $otherUser = $this->createActiveUser();
        $otherTenantId = (string) TenantUser::query()
            ->where('user_id', $otherUser->id)
            ->value('tenant_id');
        $hidden = $this->createContract($otherTenantId, $otherUser->id, 'عميل آخر', 'E-102');
        $hiddenCollection = $this->createCollection(
            $otherTenantId,
            $hidden,
            $otherUser->id,
            CollectionStatus::Scheduled,
            1,
            '200.00',
        );

        $response = $this->getJson(
            '/api/collections?schedule_state=scheduled&sort=next_scheduled_collection',
        )
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.contract_id', $visible)
            ->assertJsonPath('data.items.0.next_scheduled_collection.id', $visibleCollection);

        $ids = collect($response->json('data.items'))->pluck('contract_id');
        $collectionIds = collect($response->json('data.items'))
            ->pluck('next_scheduled_collection.id');

        $this->assertTrue($ids->contains($visible));
        $this->assertFalse($ids->contains($hidden));
        $this->assertFalse($collectionIds->contains($hiddenCollection));
    }

    public function test_query_count_is_bounded_when_page_size_grows(): void
    {
        [$tenantId, $userId] = $this->authenticate();

        foreach (range(1, 25) as $index) {
            $contractId = $this->createContract(
                $tenantId,
                $userId,
                "عميل الأداء {$index}",
                "PERF-{$index}",
            );
            $this->createCollection(
                $tenantId,
                $contractId,
                $userId,
                CollectionStatus::Draft,
                1,
                '100.00',
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/collections?per_page=1')->assertOk();
        $singleItemQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson('/api/collections?per_page=25')->assertOk();
        $fullPageQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleItemQueryCount, $fullPageQueryCount);
        $this->assertLessThanOrEqual(5, $fullPageQueryCount);
    }

    /** @return array{string, int} */
    private function authenticate(): array
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        return [
            (string) TenantUser::query()
                ->where('user_id', $user->id)
                ->value('tenant_id'),
            $user->id,
        ];
    }

    private function createContract(
        string $tenantId,
        int $userId,
        string $customerName,
        string $unitNumber,
        ContractStatus $status = ContractStatus::Draft,
        ?CarbonImmutable $createdAt = null,
        string $totalAmount = '1000.00',
        ?string $projectName = null,
    ): string {
        $this->fixtureSequence++;
        $createdAt ??= CarbonImmutable::now();
        $projectId = (string) Str::ulid();
        $customerId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $reservationId = (string) Str::ulid();
        $contractId = (string) Str::ulid();

        DB::table('projects')->insert([
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'project_number' => "IDX-{$this->fixtureSequence}",
            'project_number_year' => 2026,
            'project_sequence_number' => $this->fixtureSequence,
            'name' => $projectName ?? "مشروع {$this->fixtureSequence}",
            'project_type' => 'residential',
            'status' => 'active',
            'city' => 'الرياض',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'customer',
            'name' => $customerName,
            'phone' => '05'.str_pad((string) $this->fixtureSequence, 8, '0', STR_PAD_LEFT),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'unit_number' => $unitNumber,
            'unit_type' => 'apartment',
            'status' => 'available',
            'selling_price' => $totalAmount,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('reservations')->insert([
            'id' => $reservationId,
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'status' => 'active',
            'reserved_at' => $createdAt,
            'expires_at' => $createdAt->addMonth(),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('contracts')->insert([
            'id' => $contractId,
            'tenant_id' => $tenantId,
            'reservation_id' => $reservationId,
            'status' => $status->value,
            'total_amount' => $totalAmount,
            'currency' => 'SAR',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $contractId;
    }

    private function createCollection(
        string $tenantId,
        string $contractId,
        int $userId,
        CollectionStatus $status,
        int $sequence,
        string $amount,
        ?string $dueDate = null,
        ?string $title = null,
    ): string {
        $timestamp = CarbonImmutable::now();
        $scheduled = $status === CollectionStatus::Scheduled;
        $cancelled = $status === CollectionStatus::Cancelled;
        $collectionId = (string) Str::ulid();

        DB::table('collections')->insert([
            'id' => $collectionId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'sequence' => $sequence,
            'title' => $title ?? "بند التحصيل {$sequence}",
            'amount' => $amount,
            'due_date' => $dueDate ?? $timestamp->addMonths($sequence)->toDateString(),
            'notes' => null,
            'status' => $status->value,
            'scheduled_at' => $scheduled ? $timestamp : null,
            'scheduled_by' => $scheduled ? $userId : null,
            'cancelled_at' => $cancelled ? $timestamp : null,
            'cancelled_by' => $cancelled ? $userId : null,
            'cancellation_reason' => $cancelled ? 'إلغاء للاختبار' : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $collectionId;
    }
}
