<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Exceptions\ProjectNotArchivedException;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class RestoreProjectAction
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

            if (! $project->isArchived()) {
                throw new ProjectNotArchivedException();
            }

            $project->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'restored_by' => $actorId,
                'updated_by' => $actorId,
            ])->save();

            return $project;
        });
    }
}
