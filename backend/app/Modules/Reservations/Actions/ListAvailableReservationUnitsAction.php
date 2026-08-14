<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListAvailableReservationUnitsAction
{
    /**
     * @return Collection<int, array{id: string, unit_number: string, project_name: string}>
     */
    public function execute(
        string $tenantId,
        string $projectId,
    ): Collection
    {
        return Unit::query()
            ->with('project:id,name,status,archived_at')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', UnitStatus::Available->value)
            ->whereNull('archived_at')
            ->whereHas(
                'project',
                fn (Builder $query) => $query
                    ->where('status', ProjectStatus::Active->value)
                    ->whereNull('archived_at'),
            )
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('reservations')
                    ->whereColumn('reservations.unit_id', 'units.id')
                    ->where(
                        'reservations.status',
                        ReservationStatus::Active->value,
                    );
            })
            ->orderBy('unit_number')
            ->get(['id', 'project_id', 'unit_number'])
            ->map(fn (Unit $unit): array => [
                'id' => $unit->id,
                'unit_number' => $unit->unit_number,
                'project_name' => $unit->project?->name ?? '',
            ])
            ->values();
    }
}
