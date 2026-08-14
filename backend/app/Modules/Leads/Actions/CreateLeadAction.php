<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadActionNotAuthorizedException;
use App\Modules\Leads\Exceptions\LeadPhoneDuplicateDetectedException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadAssigneeResolver;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadDuplicateDetector;
use App\Modules\Leads\Support\LeadInterestValidator;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use App\Modules\Shared\Time\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateLeadAction
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
        private readonly LeadAssigneeResolver $assignees,
        private readonly LeadInterestValidator $interests,
        private readonly LeadDuplicateDetector $duplicates,
        private readonly SaudiMobileNormalizer $phoneNormalizer,
        private readonly TenantClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(
        string $tenantId,
        User $actor,
        array $data,
    ): Lead {
        $this->authorization->assertActor($actor, $tenantId);

        if (
            ! $this->authorization->isAdministrator($actor)
            && array_key_exists('assigned_to', $data)
        ) {
            throw new LeadActionNotAuthorizedException();
        }

        return DB::transaction(function () use ($tenantId, $actor, $data): Lead {
            $this->authorization->assertActor($actor, $tenantId, true);
            $phone = $this->phoneNormalizer->normalizeRequired($data['phone'] ?? null);
            $projectId = $this->nullableString($data['project_id'] ?? null);
            $unitId = $this->nullableString($data['unit_id'] ?? null);
            $this->interests->validate($tenantId, $projectId, $unitId);

            $assignedTo = $this->assignedUserId($tenantId, $actor, $data);
            $source = LeadSource::from((string) ($data['source'] ?? ''));
            $sourceDetail = $this->sourceDetail($source, $data['source_detail'] ?? null);
            $duplicateAcknowledged = filter_var(
                $data['acknowledge_duplicate'] ?? false,
                FILTER_VALIDATE_BOOL,
            );
            $matches = $this->duplicates->visibleMatches(
                tenantId: $tenantId,
                actor: $actor,
                phone: $phone,
            );

            if (
                $matches->isNotEmpty()
                && ! $duplicateAcknowledged
            ) {
                throw new LeadPhoneDuplicateDetectedException($matches);
            }

            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                throw new LeadValidationException(
                    'Lead name is required.',
                    ['name' => ['Lead name is required.']],
                );
            }

            $now = CarbonImmutable::now();

            return Lead::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'phone' => $phone,
                'email' => $this->nullableString($data['email'] ?? null),
                'source' => $source,
                'source_detail' => $sourceDetail,
                'project_id' => $projectId,
                'unit_id' => $unitId,
                'stage' => LeadStage::New,
                'assigned_to' => $assignedTo,
                'next_follow_up_at' => isset($data['next_follow_up_at'])
                    ? $this->clock->parse(
                        $tenantId,
                        (string) $data['next_follow_up_at'],
                    )
                    : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assignedUserId(
        string $tenantId,
        User $actor,
        array $data,
    ): ?int {
        if (! $this->authorization->isAdministrator($actor)) {
            return $actor->id;
        }

        if (! isset($data['assigned_to'])) {
            return null;
        }

        return $this->assignees->resolve(
            $tenantId,
            (int) $data['assigned_to'],
        )->id;
    }

    private function sourceDetail(LeadSource $source, mixed $value): ?string
    {
        if ($source !== LeadSource::Other) {
            return null;
        }

        $detail = trim((string) $value);

        if ($detail === '') {
            throw new LeadValidationException(
                'Source detail is required when the Lead source is other.',
                ['source_detail' => ['Source detail is required.']],
            );
        }

        return $detail;
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
