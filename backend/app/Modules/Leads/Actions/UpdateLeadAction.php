<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadInterestValidator;
use App\Modules\Leads\Support\LeadLocator;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class UpdateLeadAction
{
    public function __construct(
        private readonly LeadLocator $leads,
        private readonly LeadAuthorization $authorization,
        private readonly LeadInterestValidator $interests,
        private readonly SaudiMobileNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(
        string $tenantId,
        string $leadId,
        User $actor,
        array $data,
    ): Lead {
        return DB::transaction(function () use ($tenantId, $leadId, $actor, $data): Lead {
            $lead = $this->leads->lockVisible($tenantId, $leadId, $actor);

            if ($lead->isArchived()) {
                throw new LeadIsArchivedException();
            }

            if (! $lead->isOpen()) {
                throw new LeadNotInOpenStageException();
            }

            $this->authorization->assertCanModify($lead, $actor);

            $projectId = $lead->project_id === null
                ? null
                : (string) $lead->project_id;
            $unitId = $lead->unit_id === null ? null : (string) $lead->unit_id;

            if (array_key_exists('project_id', $data)) {
                $newProjectId = $this->nullableString($data['project_id']);

                if (! array_key_exists('unit_id', $data) && $newProjectId !== $projectId) {
                    $unitId = null;
                }

                $projectId = $newProjectId;
            }

            if (array_key_exists('unit_id', $data)) {
                $unitId = $this->nullableString($data['unit_id']);
            }

            $this->interests->validate($tenantId, $projectId, $unitId);

            $source = array_key_exists('source', $data)
                ? LeadSource::from((string) $data['source'])
                : $lead->source;
            $sourceDetail = array_key_exists('source_detail', $data)
                ? $this->nullableString($data['source_detail'])
                : $lead->source_detail;

            if ($source !== LeadSource::Other) {
                $sourceDetail = null;
            } elseif ($sourceDetail === null) {
                throw new LeadValidationException(
                    'Source detail is required when the Lead source is other.',
                    ['source_detail' => ['Source detail is required.']],
                );
            }

            $attributes = [
                'source' => $source,
                'source_detail' => $sourceDetail,
                'project_id' => $projectId,
                'unit_id' => $unitId,
                'updated_by' => $actor->id,
                'updated_at' => CarbonImmutable::now(),
            ];

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);

                if ($name === '') {
                    throw new LeadValidationException(
                        'Lead name is required.',
                        ['name' => ['Lead name is required.']],
                    );
                }

                $attributes['name'] = $name;
            }

            if (array_key_exists('phone', $data)) {
                $attributes['phone'] = $this->phoneNormalizer
                    ->normalizeRequired($data['phone']);
            }

            if (array_key_exists('email', $data)) {
                $attributes['email'] = $this->nullableString($data['email']);
            }

            if (array_key_exists('next_follow_up_at', $data)) {
                $attributes['next_follow_up_at'] = $data['next_follow_up_at'] === null
                    ? null
                    : CarbonImmutable::parse((string) $data['next_follow_up_at']);
            }

            $lead->forceFill($attributes)->save();

            return $lead->refresh();
        }, 3);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
