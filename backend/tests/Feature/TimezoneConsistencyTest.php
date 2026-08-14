<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Shared\Time\TenantClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class TimezoneConsistencyTest extends ApiTestCase
{
    use CreatesDomainIntegrityFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_tenant_clock_interprets_business_time_in_tenant_timezone_and_returns_utc(): void
    {
        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenantId = $this->tenantId($user);

        $parsed = app(TenantClock::class)->parse(
            $tenantId,
            '2026-09-01 09:30:00',
        );

        $this->assertSame('UTC', $parsed->timezoneName);
        $this->assertSame(
            '2026-09-01T06:30:00.000000Z',
            $parsed->toISOString(),
        );
    }

    public function test_project_business_year_uses_tenant_calendar_at_utc_year_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-12-31T22:30:00Z');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/projects', [
            'name' => 'مشروع سنة الرياض',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()
            ->assertJsonPath('data.project.project_number_year', 2027)
            ->assertJsonPath(
                'data.project.project_number',
                'PRJ-2027-001',
            );
    }

    public function test_project_completion_uses_tenant_business_date_at_utc_day_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-12-31T22:30:00Z');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenantId = $this->tenantId($user);
        $project = $this->createIntegrityProject(
            $tenantId,
            $user->id,
            'active',
            [
                'actual_start_date' => '2027-01-01',
                'actual_end_date' => '2027-01-01',
            ],
        );

        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'completed');
    }

    public function test_reservation_calendar_expiry_is_persisted_and_returned_as_utc(): void
    {
        $now = CarbonImmutable::parse('2026-08-14T08:00:00Z');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $user = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenantId = $this->tenantId($user);
        $project = $this->createIntegrityProject(
            $tenantId,
            $user->id,
        );
        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $user->id,
        );
        $customer = $this->createIntegrityCustomer(
            $tenantId,
            $user->id,
        );

        Sanctum::actingAs($user);

        $reservationId = $this->postJson('/api/reservations', [
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'expires_at' => '2026-08-15 09:30:00',
        ])->assertCreated()
            ->assertJsonPath(
                'data.reservation.expires_at',
                '2026-08-15T06:30:00.000000Z',
            )
            ->json('data.reservation.id');

        $stored = Reservation::query()->findOrFail($reservationId);

        $this->assertSame(
            '2026-08-15T06:30:00.000000Z',
            $stored->expires_at?->utc()->toISOString(),
        );
    }

    private function tenantId(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');
    }
}
