<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExpireReservations;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

final class ReservationsApiTest extends ApiTestCase
{
    private int $projectCount = 0;
    private int $customerCount = 0;
    private int $unitCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-22 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_guest_cannot_access_reservations(): void
    {
        $id = '01J00000000000000000000000';
        $this->getJson('/api/reservations')->assertUnauthorized();
        $this->getJson('/api/reservations/available-units')->assertUnauthorized();
        $this->postJson('/api/reservations', [])->assertUnauthorized();
        $this->getJson("/api/reservations/{$id}")->assertUnauthorized();
        $this->patchJson("/api/reservations/{$id}", [])->assertUnauthorized();
        $this->patchJson("/api/reservations/{$id}/cancel")->assertUnauthorized();
    }

    public function test_active_project_available_unit_and_customer_can_be_reserved_with_default_expiry(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $unitId = $this->createAvailableUnit();
        $customerId = $this->createCustomer();

        $response = $this->postJson('/api/reservations', [
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'notes' => 'بانتظار قرار العميل.',
            'tenant_id' => '01J00000000000000000000000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء الحجز بنجاح.')
            ->assertJsonPath('data.reservation.unit_id', $unitId)
            ->assertJsonPath('data.reservation.customer_id', $customerId)
            ->assertJsonPath('data.reservation.status', 'active')
            ->assertJsonPath(
                'data.reservation.expires_at',
                now()->addHours(48)->utc()->toISOString()
            );

        $this->assertDatabaseHas('reservations', [
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_reservation_validates_expiry_and_only_allows_mutable_fields_to_be_updated(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->postJson('/api/reservations', [
            'unit_id' => $this->createAvailableUnit(),
            'customer_id' => $this->createCustomer(),
            'expires_at' => '2026-07-22 12:59:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);

        $this->patchJson("/api/reservations/{$reservationId}", [
            'expires_at' => '2026-07-23 12:00:00',
            'notes' => 'تم تمديد الحجز.',
        ])->assertOk()
            ->assertJsonPath(
                'data.reservation.expires_at',
                Carbon::parse(
                    '2026-07-23 12:00:00',
                    'Asia/Riyadh',
                )->utc()->toISOString()
            )
            ->assertJsonPath('data.reservation.notes', 'تم تمديد الحجز.');

        $this->patchJson("/api/reservations/{$reservationId}", [
            'unit_id' => $this->createAvailableUnit(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
    }

    public function test_ineligible_project_unit_and_customer_cannot_be_reserved(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $customerId = $this->createCustomer();

        $inactiveUnit = $this->createAvailableUnit(false);
        $this->postJson('/api/reservations', ['unit_id' => $inactiveUnit, 'customer_id' => $customerId])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);

        $soldUnit = $this->createAvailableUnit();
        Unit::query()->whereKey($soldUnit)->update(['status' => 'sold']);
        $this->postJson('/api/reservations', ['unit_id' => $soldUnit, 'customer_id' => $customerId])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);

        $archivedCustomer = $this->createCustomer();
        $this->patchJson("/api/customers/{$archivedCustomer}/archive")->assertOk();
        $this->postJson('/api/reservations', ['unit_id' => $this->createAvailableUnit(), 'customer_id' => $archivedCustomer])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
    }

    public function test_only_one_active_reservation_can_exist_for_a_unit_and_database_constraint_is_enforced(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $unitId = $this->createAvailableUnit();
        $first = $this->postJson('/api/reservations', ['unit_id' => $unitId, 'customer_id' => $this->createCustomer()])
            ->assertCreated()->json('data.reservation.id');

        $this->postJson('/api/reservations', ['unit_id' => $unitId, 'customer_id' => $this->createCustomer()])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);

        $reservation = Reservation::query()->findOrFail($first);
        $this->expectException(QueryException::class);
        Reservation::query()->create([
            'tenant_id' => $reservation->tenant_id,
            'unit_id' => $unitId,
            'customer_id' => $this->createCustomer(),
            'status' => ReservationStatus::Active,
            'reserved_at' => now(),
            'expires_at' => now()->addHours(48),
        ]);
    }

    public function test_database_rejects_an_expiry_at_or_before_the_reservation_time(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $unitId = $this->createAvailableUnit();
        $customerId = $this->createCustomer();

        $this->expectException(QueryException::class);
        Reservation::query()->create([
            'tenant_id' => $this->tenantIdFor($user),
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'status' => ReservationStatus::Active,
            'reserved_at' => now(),
            'expires_at' => now(),
        ]);
    }

    public function test_active_reservation_can_be_cancelled_but_cannot_be_cancelled_or_updated_again(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->patchJson("/api/reservations/{$reservationId}/cancel", ['cancellation_reason' => 'تراجع العميل.'])
            ->assertOk()
            ->assertJsonPath('data.reservation.status', 'cancelled')
            ->assertJsonPath('data.reservation.cancellation_reason', 'تراجع العميل.')
            ->assertJsonPath('data.reservation.cancelled_by', $user->id);

        $this->patchJson("/api/reservations/{$reservationId}", ['notes' => 'لا ينبغي الحفظ'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reservation']);
        $this->patchJson("/api/reservations/{$reservationId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['reservation']);
    }

    public function test_expiration_job_expires_the_reservation_and_releases_the_unit_for_a_new_reservation(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);
        $unitId = $this->createAvailableUnit();
        $reservationId = $this->postJson('/api/reservations', [
            'unit_id' => $unitId,
            'customer_id' => $this->createCustomer(),
            'expires_at' => '2026-07-22 13:01:00',
        ])->assertCreated()->json('data.reservation.id');

        (new ExpireReservations)->expireAt(
            app(\App\Modules\Reservations\Actions\ExpireReservationAction::class),
            Carbon::parse(
                '2026-07-22 10:02:00',
                config('app.timezone'),
            ),
        );

        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'expired']);
        $this->postJson('/api/reservations', ['unit_id' => $unitId, 'customer_id' => $this->createCustomer()])
            ->assertCreated();
    }

    public function test_reservations_are_tenant_scoped_and_can_be_listed_filtered_and_summarized(): void
    {
        $tenantAUser = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantBUser = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($tenantAUser);
        $reservationId = $this->createReservation();

        $this->patchJson("/api/reservations/{$reservationId}/cancel")->assertOk();
        $active = $this->createReservation();

        $this->getJson('/api/reservations?search=U-2&status=active&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.reservations.meta.total', 1)
            ->assertJsonPath('data.reservations.data.0.id', $active)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.active', 1)
            ->assertJsonPath('data.summary.cancelled', 1);

        Sanctum::actingAs($tenantBUser);
        $this->getJson("/api/reservations/{$active}")->assertNotFound();
        $this->patchJson("/api/reservations/{$active}", ['notes' => 'x'])->assertNotFound();
        $this->patchJson("/api/reservations/{$active}/cancel")->assertNotFound();
    }

    public function test_available_units_returns_only_units_eligible_for_reservation(): void
    {
        $tenantAUser = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantBUser = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($tenantAUser);

        $eligibleUnit = $this->createAvailableUnit();
        $eligibleProjectId = Unit::query()
            ->findOrFail($eligibleUnit)
            ->project_id;
        $reservedUnit = $this->createAvailableUnit();
        $this->postJson('/api/reservations', [
            'unit_id' => $reservedUnit,
            'customer_id' => $this->createCustomer(),
        ])->assertCreated();

        $soldUnit = $this->createAvailableUnit();
        Unit::query()->whereKey($soldUnit)->update(['status' => 'sold']);

        $this->createAvailableUnit(false);

        $archivedUnit = $this->createAvailableUnit();
        $this->archiveUnitFixture($archivedUnit);

        Sanctum::actingAs($tenantBUser);
        $this->createAvailableUnit();

        Sanctum::actingAs($tenantAUser);
        $this->getJson('/api/reservations/available-units')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id']);

        $this->getJson(
            "/api/reservations/available-units?project_id={$eligibleProjectId}",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.units')
            ->assertJsonPath('data.units.0.id', $eligibleUnit)
            ->assertJsonStructure([
                'data' => [
                    'units' => [
                        ['id', 'unit_number', 'project_name'],
                    ],
                ],
            ]);
    }

    public function test_creating_reservation_marks_unit_as_reserved(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);

        $unitId = $this->createAvailableUnit();

        $this->postJson('/api/reservations', [
            'unit_id' => $unitId,
            'customer_id' => $this->createCustomer(),
        ])->assertCreated();

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'reserved',
        ]);
    }

    public function test_cancelling_reservation_releases_unit_back_to_available(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);

        $reservationId = $this->createReservation();

        $unitId = Reservation::query()
            ->whereKey($reservationId)
            ->value('unit_id');

        $this->patchJson("/api/reservations/{$reservationId}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'available',
        ]);
    }

    public function test_expiring_reservation_releases_unit_back_to_available(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);

        $unitId = $this->createAvailableUnit();

        $reservationId = $this->postJson('/api/reservations', [
            'unit_id' => $unitId,
            'customer_id' => $this->createCustomer(),
            'expires_at' => '2026-07-22 13:01:00',
        ])->assertCreated()
            ->json('data.reservation.id');

        (new ExpireReservations)->expireAt(
            app(\App\Modules\Reservations\Actions\ExpireReservationAction::class),
            Carbon::parse(
                '2026-07-22 10:02:00',
                config('app.timezone'),
            ),
        );

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'expired',
        ]);

        $this->assertDatabaseHas('units', [
            'id' => $unitId,
            'status' => 'available',
        ]);
    }

    private function createReservation(): string
    {
        return (string) $this->postJson('/api/reservations', [
            'unit_id' => $this->createAvailableUnit(),
            'customer_id' => $this->createCustomer(),
        ])->assertCreated()->json('data.reservation.id');
    }

    private function createAvailableUnit(bool $activeProject = true): string
    {
        $this->unitCount++;
        $projectId = $this->createProject($activeProject);

        return (string) Unit::query()->create([
            'tenant_id' => $this->tenantIdFor(auth()->user()),
            'project_id' => $projectId,
            'unit_number' => "U-{$this->unitCount}",
            'unit_type' => 'apartment',
            'status' => 'available',
            'selling_price' => 500000,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function createCustomer(): string
    {
        $this->customerCount++;
        return (string) $this->postJson('/api/customers', [
            'type' => 'individual', 'category' => 'buyer',
            'name' => "عميل الحجز {$this->customerCount}",
            'phone' => '050000'.str_pad((string) $this->customerCount, 4, '0', STR_PAD_LEFT),
        ])->assertCreated()->json('data.customer.id');
    }

    private function archiveUnitFixture(string $unitId): void
    {
        Unit::query()
            ->whereKey($unitId)
            ->update([
                'archived_at' => now(),
                'archived_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
    }

    private function createProject(bool $active): string
    {
        $this->projectCount++;
        $year = now('Asia/Riyadh')->year;
        $sequence = (int) Project::query()
            ->where('project_number_year', $year)
            ->max('project_sequence_number') + 1;

        return (string) Project::query()->create([
            'tenant_id' => $this->tenantIdFor(auth()->user()),
            'project_number' => sprintf('PRJ-%d-%03d', $year, $sequence),
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => "مشروع الحجز {$this->projectCount}",
            'project_type' => 'residential',
            'status' => $active
                ? ProjectStatus::Active->value
                : ProjectStatus::Draft->value,
            'country_code' => 'SA',
            'city' => 'الرياض',
            'currency' => 'SAR',
            'data_origin' => 'user',
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function tenantIdFor(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->valueOrFail('tenant_id');
    }
}
