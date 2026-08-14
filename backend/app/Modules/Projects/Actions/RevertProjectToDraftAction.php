<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Policies\OperationalFootprintPolicy;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\ProjectOperationalDependencies;
use Illuminate\Support\Facades\DB;

final class RevertProjectToDraftAction
{
    public function __construct(
        private readonly OperationalFootprintPolicy $footprintPolicy,
        private readonly ProjectOperationalDependencies $dependencies,
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

            $this->footprintPolicy->assert(
                $project,
                $this->dependencies->hasActiveReservation(
                    $tenantId,
                    $projectId,
                ),
                $this->dependencies->hasLiveContract(
                    $tenantId,
                    $projectId,
                ),
            );

            $project->forceFill([
                'status' => ProjectStatus::Draft,
                'updated_by' => $actorId,
            ])->save();

            return $project;
        });
    }
}
