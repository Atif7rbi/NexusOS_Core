<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCustomerBusinessContextFixtures;

final class CustomerBusinessContextApiTest extends ApiTestCase
{
    use CreatesCustomerBusinessContextFixtures;

    public function test_customer_record_returns_historical_journeys_newest_first(): void
    {
        [$tenantId, $userId] = $this->authenticateCustomerContext();
        $customerId = $this->createContextCustomer($tenantId, $userId, 'customer');
        $older = CarbonImmutable::parse('2026-08-01T09:00:00Z');
        $newer = CarbonImmutable::parse('2026-08-02T09:00:00Z');
        $olderReservation = $this->createContextReservation(
            $tenantId,
            $userId,
            $customerId,
            $older,
            'expired',
        );
        $newerReservation = $this->createContextReservation(
            $tenantId,
            $userId,
            $customerId,
            $newer,
            'converted',
        );
        $cancelledContract = $this->createContextContract(
            $tenantId,
            $userId,
            $newerReservation,
            $newer,
            'cancelled',
            '125000.00',
        );
        $activeContract = $this->createContextContract(
            $tenantId,
            $userId,
            $newerReservation,
            $newer->addHour(),
            'active',
            '130000.00',
        );
        $this->createContextCollection(
            $tenantId,
            $userId,
            $activeContract,
            2,
            '75000.25',
            '2026-10-01',
        );
        $firstCollection = $this->createContextCollection(
            $tenantId,
            $userId,
            $activeContract,
            1,
            '55000.50',
            '2026-09-01',
        );

        $response = $this->getJson("/api/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.business_context.summary.reservations_total', 2)
            ->assertJsonPath('data.business_context.summary.contracts_total', 2)
            ->assertJsonCount(2, 'data.business_context.journeys')
            ->assertJsonPath(
                'data.business_context.journeys.0.reservation.id',
                $newerReservation,
            )
            ->assertJsonPath(
                'data.business_context.journeys.1.reservation.id',
                $olderReservation,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.id',
                $activeContract,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.1.id',
                $cancelledContract,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.state',
                'scheduled',
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.scheduled_total',
                '130000.75',
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.scheduled_lines_count',
                2,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.next_scheduled_collection.id',
                $firstCollection,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.1.collection_schedule.state',
                'absent',
            );

        $journey = $response->json('data.business_context.journeys.0');
        $expectedProjectNumber = (string) DB::table('projects')
            ->join('units', 'units.project_id', '=', 'projects.id')
            ->join('reservations', 'reservations.unit_id', '=', 'units.id')
            ->where('reservations.id', $newerReservation)
            ->value('projects.project_number');

        $this->assertSame('2026-08-02T09:00:00.000000Z', $journey['reservation']['reserved_at']);
        $this->assertSame($expectedProjectNumber, $journey['project']['project_number']);
        $this->assertSame('مشروع 3', $journey['project']['name']);
        $this->assertSame('UNIT-3', $journey['unit']['unit_number']);
        $this->assertSame('apartment', $journey['unit']['unit_type']);
        $this->assertSame('2026-09-01', $journey['contracts'][0]['collection_schedule']['next_scheduled_collection']['due_date']);
    }

    public function test_schedule_summary_uses_only_scheduled_rows_without_payment_semantics(): void
    {
        [$tenantId, $userId] = $this->authenticateCustomerContext();
        $customerId = $this->createContextCustomer($tenantId, $userId, 'customer');
        $reservationId = $this->createContextReservation(
            $tenantId,
            $userId,
            $customerId,
            CarbonImmutable::parse('2026-08-03T10:00:00Z'),
        );
        $draftContract = $this->createContextContract(
            $tenantId,
            $userId,
            $reservationId,
            CarbonImmutable::parse('2026-08-03T11:00:00Z'),
            'draft',
        );
        $this->createContextCollection(
            $tenantId,
            $userId,
            $draftContract,
            1,
            '500.00',
            '2026-09-03',
            'draft',
        );

        $this->getJson("/api/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.state',
                'draft',
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.scheduled_total',
                '0.00',
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.scheduled_lines_count',
                0,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.next_scheduled_collection',
                null,
            );
    }

    public function test_archived_customer_keeps_business_context_readable(): void
    {
        [$tenantId, $userId] = $this->authenticateCustomerContext();
        $customerId = $this->createContextCustomer($tenantId, $userId, 'archived');
        $reservationId = $this->createContextReservation(
            $tenantId,
            $userId,
            $customerId,
            CarbonImmutable::parse('2026-08-04T10:00:00Z'),
        );
        $contractId = $this->createContextContract(
            $tenantId,
            $userId,
            $reservationId,
            CarbonImmutable::parse('2026-08-04T11:00:00Z'),
        );
        $this->createContextCollection(
            $tenantId,
            $userId,
            $contractId,
            1,
            '100000.00',
            '2026-09-04',
            'cancelled',
        );

        $this->getJson("/api/customers/{$customerId}")
            ->assertOk()
            ->assertJsonPath('data.customer.status', 'archived')
            ->assertJsonPath('data.business_context.summary.reservations_total', 1)
            ->assertJsonPath('data.business_context.summary.contracts_total', 1)
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.id',
                $contractId,
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.state',
                'cancelled',
            )
            ->assertJsonPath(
                'data.business_context.journeys.0.contracts.0.collection_schedule.scheduled_total',
                '0.00',
            );
    }

    public function test_business_context_is_customer_and_tenant_scoped(): void
    {
        [$tenantId, $userId] = $this->authenticateCustomerContext();
        $visibleCustomer = $this->createContextCustomer($tenantId, $userId, 'customer');
        $otherCustomer = $this->createContextCustomer($tenantId, $userId, 'customer');
        $this->createContextReservation(
            $tenantId,
            $userId,
            $otherCustomer,
            CarbonImmutable::parse('2026-08-05T10:00:00Z'),
        );

        $otherUser = $this->createActiveUser();
        $otherTenantId = (string) TenantUser::query()
            ->where('user_id', $otherUser->id)
            ->value('tenant_id');
        $otherTenantCustomer = $this->createContextCustomer(
            $otherTenantId,
            $otherUser->id,
            'customer',
        );
        $this->createContextReservation(
            $otherTenantId,
            $otherUser->id,
            $otherTenantCustomer,
            CarbonImmutable::parse('2026-08-06T10:00:00Z'),
        );

        $this->getJson("/api/customers/{$visibleCustomer}")
            ->assertOk()
            ->assertJsonPath('data.business_context.summary.reservations_total', 0)
            ->assertJsonPath('data.business_context.summary.contracts_total', 0)
            ->assertJsonPath('data.business_context.journeys', []);

        $this->getJson("/api/customers/{$otherTenantCustomer}")
            ->assertNotFound();
    }

    public function test_business_context_query_count_is_bounded(): void
    {
        [$tenantId, $userId] = $this->authenticateCustomerContext();
        $customerId = $this->createContextCustomer($tenantId, $userId, 'customer');
        $this->createContextJourney($tenantId, $userId, $customerId, 1);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/customers/{$customerId}")->assertOk();
        $singleJourneyQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 8) as $index) {
            $this->createContextJourney($tenantId, $userId, $customerId, $index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/customers/{$customerId}")->assertOk();
        $manyJourneysQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleJourneyQueryCount, $manyJourneysQueryCount);
    }
}
