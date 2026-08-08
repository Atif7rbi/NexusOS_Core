<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\TenantUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait CreatesCustomerBusinessContextFixtures
{
    private int $customerContextFixtureSequence = 0;

    /** @return array{string, int} */
    private function authenticateCustomerContext(): array
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

    private function createContextCustomer(
        string $tenantId,
        int $userId,
        string $status,
    ): string {
        $this->customerContextFixtureSequence++;
        $id = (string) Str::ulid();
        $now = CarbonImmutable::parse('2026-08-01T08:00:00Z');

        DB::table('customers')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => $status,
            'name' => "عميل {$this->customerContextFixtureSequence}",
            'phone' => '05'.str_pad(
                (string) $this->customerContextFixtureSequence,
                8,
                '0',
                STR_PAD_LEFT,
            ),
            'created_by' => $userId,
            'updated_by' => $userId,
            'archived_by' => $status === 'archived' ? $userId : null,
            'archived_at' => $status === 'archived' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function createContextJourney(
        string $tenantId,
        int $userId,
        string $customerId,
        int $day,
    ): void {
        $createdAt = CarbonImmutable::parse(
            sprintf('2026-08-%02dT10:00:00Z', $day),
        );
        $reservationId = $this->createContextReservation(
            $tenantId,
            $userId,
            $customerId,
            $createdAt,
        );
        $contractId = $this->createContextContract(
            $tenantId,
            $userId,
            $reservationId,
            $createdAt->addHour(),
        );
        $this->createContextCollection(
            $tenantId,
            $userId,
            $contractId,
            1,
            '100000.00',
            $createdAt->addMonth()->toDateString(),
            'draft',
        );
    }

    private function createContextReservation(
        string $tenantId,
        int $userId,
        string $customerId,
        CarbonImmutable $createdAt,
        string $status = 'active',
    ): string {
        $this->customerContextFixtureSequence++;
        $projectId = (string) Str::ulid();
        $unitId = (string) Str::ulid();
        $reservationId = (string) Str::ulid();
        $projectYear = $createdAt->year;
        $projectSequence = $this->nextContextProjectSequence($projectYear);
        $projectNumber = sprintf(
            'CTX-%d-%d',
            $projectYear,
            $projectSequence,
        );

        DB::table('projects')->insert([
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'project_number' => $projectNumber,
            'project_number_year' => $projectYear,
            'project_sequence_number' => $projectSequence,
            'name' => "مشروع {$this->customerContextFixtureSequence}",
            'project_type' => 'residential',
            'status' => 'active',
            'city' => 'الرياض',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'unit_number' => "UNIT-{$this->customerContextFixtureSequence}",
            'unit_type' => 'apartment',
            'status' => 'sold',
            'selling_price' => '150000.00',
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
            'status' => $status,
            'reserved_at' => $createdAt,
            'expires_at' => $createdAt->addMonth(),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservationId;
    }

    private function nextContextProjectSequence(int $year): int
    {
        $currentMaximum = DB::table('projects')
            ->where('project_number_year', $year)
            ->max('project_sequence_number');

        return ((int) $currentMaximum) + 1;
    }

    private function createContextContract(
        string $tenantId,
        int $userId,
        string $reservationId,
        CarbonImmutable $createdAt,
        string $status = 'draft',
        string $totalAmount = '100000.00',
    ): string {
        $id = (string) Str::ulid();

        DB::table('contracts')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'reservation_id' => $reservationId,
            'status' => $status,
            'total_amount' => $totalAmount,
            'currency' => 'SAR',
            'activated_at' => $status === 'active' ? $createdAt : null,
            'cancelled_at' => $status === 'cancelled' ? $createdAt : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function createContextCollection(
        string $tenantId,
        int $userId,
        string $contractId,
        int $sequence,
        string $amount,
        string $dueDate,
        string $status = 'scheduled',
    ): string {
        $id = (string) Str::ulid();
        $now = CarbonImmutable::parse('2026-08-07T10:00:00Z');
        $scheduled = $status === 'scheduled';
        $cancelled = $status === 'cancelled';

        DB::table('collections')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'sequence' => $sequence,
            'title' => "دفعة {$sequence}",
            'amount' => $amount,
            'due_date' => $dueDate,
            'status' => $status,
            'scheduled_at' => $scheduled ? $now : null,
            'scheduled_by' => $scheduled ? $userId : null,
            'cancelled_at' => $cancelled ? $now : null,
            'cancelled_by' => $cancelled ? $userId : null,
            'cancellation_reason' => $cancelled ? 'إلغاء تاريخي' : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
