<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Customers\Models\Customer;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait CreatesDomainIntegrityFixtures
{
    private int $integrityFixtureSequence = 0;

    protected function integrityTenantId(User $user): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');
    }

    protected function createIntegrityProject(
        string $tenantId,
        int $actorId,
        string $status = 'active',
        array $attributes = [],
    ): Project {
        $this->integrityFixtureSequence++;
        $now = CarbonImmutable::parse('2026-08-14T08:00:00Z');
        $year = $now->year;
        $sequence = ((int) DB::table('projects')
            ->where('project_number_year', $year)
            ->max('project_sequence_number')) + 1;

        $project = Project::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'project_number' => "INT-{$year}-{$sequence}",
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => "مشروع تكامل {$this->integrityFixtureSequence}",
            'project_type' => 'residential',
            'status' => $status,
            'city' => 'الرياض',
            'actual_start_date' => '2026-08-01',
            'actual_end_date' => '2026-08-10',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ], $attributes));

        return $project->fresh();
    }

    protected function createIntegrityUnit(
        string $tenantId,
        string $projectId,
        int $actorId,
        string $status = 'available',
        array $attributes = [],
    ): Unit {
        $this->integrityFixtureSequence++;

        return Unit::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'unit_number' => "INT-{$this->integrityFixtureSequence}",
            'unit_type' => 'apartment',
            'status' => $status,
            'selling_price' => '500000.00',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ], $attributes));
    }

    protected function createIntegrityCustomer(
        string $tenantId,
        int $actorId,
        array $attributes = [],
    ): Customer {
        $this->integrityFixtureSequence++;
        $phone = '058'.str_pad(
            (string) $this->integrityFixtureSequence,
            7,
            '0',
            STR_PAD_LEFT,
        );

        return Customer::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'customer',
            'name' => "عميل تكامل {$this->integrityFixtureSequence}",
            'phone' => $phone,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ], $attributes));
    }

    protected function createIntegrityReservation(
        string $tenantId,
        string $unitId,
        string $customerId,
        int $actorId,
        string $status = 'active',
        array $attributes = [],
    ): Reservation {
        $now = CarbonImmutable::parse('2026-08-14T08:00:00Z');
        $cancellation = $status === 'cancelled'
            ? [
                'cancellation_reason' => 'إلغاء fixture',
                'cancelled_at' => $now,
                'cancelled_by' => $actorId,
            ]
            : [];

        return Reservation::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'status' => $status,
            'reserved_at' => $now,
            'expires_at' => $now->addDays(2),
            'created_by' => $actorId,
            'updated_by' => $actorId,
            ...$cancellation,
        ], $attributes));
    }

    protected function createIntegrityContract(
        string $tenantId,
        string $reservationId,
        int $actorId,
        string $status = 'draft',
        array $attributes = [],
    ): Contract {
        $now = CarbonImmutable::parse('2026-08-14T08:00:00Z');

        $contract = new Contract(array_merge([
            'tenant_id' => $tenantId,
            'reservation_id' => $reservationId,
            'status' => $status,
            'total_amount' => '450000.00',
            'activated_at' => in_array($status, ['active', 'completed'], true)
                ? $now
                : null,
            'completed_at' => $status === 'completed' ? $now : null,
            'cancelled_at' => $status === 'cancelled' ? $now : null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ], $attributes));
        $contract->currency = 'SAR';
        $contract->save();

        return $contract;
    }
}
