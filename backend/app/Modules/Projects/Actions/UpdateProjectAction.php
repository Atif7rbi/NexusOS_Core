<?php

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Exceptions\ArchivedProjectCannotBeUpdatedException;
use App\Modules\Projects\Exceptions\FinalProjectCannotBeUpdatedException;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

class UpdateProjectAction
{
    public function execute(
        string $tenantId,
        string $projectId,
        array $data,
        int|string $actorId,
    ): Project {
        return DB::transaction(function () use (
            $tenantId,
            $projectId,
            $data,
            $actorId,
        ): Project {
            $project = Project::query()
                ->withTrashed()
                ->where('tenant_id', $tenantId)
                ->whereKey($projectId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($project->isArchived()) {
                throw new ArchivedProjectCannotBeUpdatedException();
            }

            if (in_array($project->status, [
                ProjectStatus::Completed,
                ProjectStatus::Cancelled,
            ], true)) {
                throw new FinalProjectCannotBeUpdatedException();
            }

            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_at'],
                $data['archived_by'],
                $data['restored_by'],
                $data['status'],
                $data['country_code'],
                $data['currency'],
            );

            $data['country_code'] = 'SA';
            $data['currency'] = 'SAR';
            $data['updated_by'] = $actorId;

            $project->update($data);

            return $project->refresh();
        });
    }
}
