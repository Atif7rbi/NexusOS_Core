<?php

declare(strict_types=1);

namespace App\Modules\Units\Actions;

use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class CreateUnitAction
{
    public function execute(
        string $tenantId,
        int|string $actorId,
        array $data,
    ): Unit {
        return DB::transaction(function () use (
            $tenantId,
            $actorId,
            $data,
        ): Unit {
            Project::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['project_id'])
                ->firstOrFail();

            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_by'],
                $data['restored_by'],
                $data['archived_at'],
            );

            $data['tenant_id'] = $tenantId;
            $data['status'] ??= UnitStatus::Available->value;
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;

            return Unit::query()->create($data);
        });
    }
}
