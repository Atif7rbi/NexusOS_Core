<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Exceptions\ProjectAlreadyArchivedException;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Exceptions\InvalidProjectStatusTransitionException;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class ArchiveProjectAction
{
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

            if ($project->isArchived()) {
                throw new ProjectAlreadyArchivedException();
            }

            if (! in_array($project->status, [
                ProjectStatus::Draft,
                ProjectStatus::Active,
                ProjectStatus::Completed,
                ProjectStatus::Cancelled,
            ], true)) {
                throw new InvalidProjectStatusTransitionException();
            }

            $project->forceFill([
                'archived_at' => now(),
                'archived_by' => $actorId,
                'restored_by' => null,
                'updated_by' => $actorId,
            ])->save();

            return $project;
        });
    }
}
