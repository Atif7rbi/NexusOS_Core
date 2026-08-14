<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationUnitUnavailableException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Policies\ReservationPolicy;
use App\Modules\Shared\Time\TenantClock;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class CreateReservationAction
{
    public function __construct(
        private readonly TenantClock $clock,
    ) {
    }

    public function execute(string $tenantId, int|string $actorId, array $data): Reservation
    {
        return DB::transaction(function () use ($tenantId, $actorId, $data): Reservation {
            $unit = Unit::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['unit_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $project = Project::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($unit->project_id)
                ->lockForUpdate()
                ->firstOrFail();

            $customer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $unit->isArchived()
                || $unit->status !== UnitStatus::Available
                || $project->isArchived()
                || $project->status !== ProjectStatus::Active
                || $customer->status === CustomerStatus::Archived
                || $customer->archived_at !== null
                || Reservation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('unit_id', $unit->id)
                    ->where('status', ReservationStatus::Active->value)
                    ->exists()
            ) {
                throw new ReservationUnitUnavailableException();
            }

            $reservedAt = now()->utc();
            $expiresAt = isset($data['expires_at'])
                ? $this->clock->parse(
                    $tenantId,
                    (string) $data['expires_at'],
                )
                : $reservedAt->copy()->add(ReservationPolicy::defaultDuration());

            $reservation = Reservation::query()->create([
                'tenant_id' => $tenantId,
                'unit_id' => $unit->id,
                'customer_id' => $customer->id,
                'status' => ReservationStatus::Active,
                'reserved_at' => $reservedAt,
                'expires_at' => $expiresAt,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $unit->update([
                'status' => UnitStatus::Reserved,
                'updated_by' => $actorId,
            ]);

            return $reservation;
        });
    }
}
