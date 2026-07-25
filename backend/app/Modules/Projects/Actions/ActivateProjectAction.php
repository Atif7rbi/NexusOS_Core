<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\InvalidProjectStatusTransitionException;
use App\Modules\Projects\Exceptions\ProjectAlreadyActiveException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\OperationalReadinessPolicy;
use Illuminate\Support\Facades\DB;

final class ActivateProjectAction
{
    public function __construct(
        private readonly OperationalReadinessPolicy $readinessPolicy,
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

            if ($project->status === ProjectStatus::Active) {
                throw new ProjectAlreadyActiveException();
            }

            if ($project->status !== ProjectStatus::Draft) {
                throw new InvalidProjectStatusTransitionException();
            }

            $this->readinessPolicy->assert($project);

            $project->forceFill([
                'status' => ProjectStatus::Active,
                'updated_by' => $actorId,
            ])->save();

            return $project;
        });
    }
}
