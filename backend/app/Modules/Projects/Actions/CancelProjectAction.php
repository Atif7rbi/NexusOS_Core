<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\OutstandingCommitmentsPolicy;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

final class CancelProjectAction
{
    public function __construct(
        private readonly OutstandingCommitmentsPolicy $commitmentsPolicy,
    ) {
    }

    public function execute(
        string $tenantId,
        string $projectId,
        int|string $actorId,
    ): Project {
        return DB::transaction(function () use (
            $tenantId,
            $projectId,
            $actorId,
        ): Project {
            $project = Project::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($projectId)
                ->lockForUpdate()
                ->firstOrFail();

            $hasActiveReservation = Reservation::query()
                ->where('tenant_id', $tenantId)
                ->where('status', ReservationStatus::Active->value)
                ->whereHas(
                    'unit',
                    fn ($query) => $query->where('project_id', $project->id),
                )
                ->exists();

            $hasSoldUnit = Unit::query()
                ->where('tenant_id', $tenantId)
                ->where('project_id', $project->id)
                ->where('status', UnitStatus::Sold->value)
                ->exists();

            $this->commitmentsPolicy->assert(
                $project,
                $hasActiveReservation,
                $hasSoldUnit,
            );

            $project->forceFill([
                'status' => ProjectStatus::Cancelled,
                'updated_by' => $actorId,
            ])->save();

            return $project;
        });
    }
}
