<?php

namespace App\Modules\Projects\Actions;

use App\Models\SystemSetting;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Contracts\BusinessNumberGeneratorInterface;
use App\Modules\Shared\Enums\DataOrigin;
use Illuminate\Support\Facades\DB;

class CreateProjectAction
{
    public function __construct(
        private readonly BusinessNumberGeneratorInterface $numberGenerator,
    ) {
    }

    public function execute(
        string $tenantId,
        int|string $actorId,
        array $data,
    ): Project
    {
        return DB::transaction(function () use (
            $tenantId,
            $actorId,
            $data,
        ): Project {
            $timezone = SystemSetting::forTenant($tenantId)->timezone
                ?? config('app.timezone');

            $year = now($timezone)->year;

            $number = $this->numberGenerator->generate(
                prefix: 'PRJ',
                year: $year,
                requestedSequence: isset($data['sequence_number'])
                    ? (int) $data['sequence_number']
                    : null,
            );

            unset($data['sequence_number']);
            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_at'],
                $data['archived_by'],
                $data['restored_by'],
            );

            $data['tenant_id'] = $tenantId;
            $data['project_number'] = $number['number'];
            $data['project_number_year'] = $number['year'];
            $data['project_sequence_number'] = $number['sequence'];
            $data['status'] = ProjectStatus::Draft->value;
            $data['country_code'] = 'SA';
            $data['currency'] = 'SAR';
            $data['data_origin'] = DataOrigin::User->value;
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;

            return Project::query()->create($data);
        });
    }
}
