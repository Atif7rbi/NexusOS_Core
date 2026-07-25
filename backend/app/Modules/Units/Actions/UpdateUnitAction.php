<?php

declare(strict_types=1);

namespace App\Modules\Units\Actions;

use App\Modules\Units\Exceptions\ArchivedUnitCannotBeUpdatedException;
use App\Modules\Units\Models\Unit;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class UpdateUnitAction
{
    public function execute(
        string $tenantId,
        string $unitId,
        int|string $actorId,
        array $data,
    ): Unit {
        return DB::transaction(function () use (
            $tenantId,
            $unitId,
            $actorId,
            $data,
        ): Unit {
            $unit = Unit::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($unitId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($unit->isArchived()) {
                throw new ArchivedUnitCannotBeUpdatedException();
            }

            if (isset($data['project_id'])) {
                Project::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($data['project_id'])
                    ->firstOrFail();
            }

            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_by'],
                $data['restored_by'],
                $data['archived_at'],
            );

            $data['updated_by'] = $actorId;

            $unit->fill($data);
            $unit->save();

            return $unit;
        });
    }
}
