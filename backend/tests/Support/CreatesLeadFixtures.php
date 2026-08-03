<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Customers\Enums\CustomerCategory;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\ArchiveReason;
use App\Modules\Leads\Enums\ConversionMode;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Enums\LostReason;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Enums\DataOrigin;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Enums\UnitType;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Str;

trait CreatesLeadFixtures
{
    private int $leadPhoneSequence = 0;

    private int $leadProjectSequence = 0;

    private int $leadUnitSequence = 0;

    private int $leadCustomerSequence = 0;

    protected function createLeadTenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->create($attributes);
    }

    /**
     * @return array{user: User, membership: TenantUser}
     */
    protected function createLeadUser(
        Tenant $tenant,
        string $role = User::ROLE_EMPLOYEE,
        string $membershipStatus = TenantUser::STATUS_ACTIVE,
        array $userAttributes = [],
    ): array {
        $user = User::factory()->create(array_merge([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ], $userAttributes));

        $membership = TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($user)
            ->create([
                'status' => $membershipStatus,
                'joined_at' => now(),
                'removed_at' => null,
            ]);

        return compact('user', 'membership');
    }

    protected function createLeadProject(
        Tenant $tenant,
        User $actor,
        array $attributes = [],
    ): Project {
        $this->leadProjectSequence++;
        $sequence = $this->leadProjectSequence;

        return Project::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'project_number' => sprintf('CRM-%04d-%04d', now()->year, $sequence),
            'project_number_year' => now()->year,
            'project_sequence_number' => $sequence,
            'name' => "CRM Project {$sequence}",
            'project_type' => ProjectType::Residential,
            'status' => ProjectStatus::Active,
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'currency' => 'SAR',
            'data_origin' => DataOrigin::User,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $attributes));
    }

    protected function archiveLeadProject(Project $project, User $actor): Project
    {
        $project->forceFill([
            'archived_at' => now(),
            'archived_by' => $actor->id,
        ])->save();

        return $project->refresh();
    }

    protected function createLeadUnit(
        Tenant $tenant,
        Project $project,
        User $actor,
        array $attributes = [],
    ): Unit {
        $this->leadUnitSequence++;

        return Unit::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'unit_number' => 'CRM-U-'.$this->leadUnitSequence,
            'unit_type' => UnitType::Apartment,
            'status' => UnitStatus::Available,
            'selling_price' => '500000.00',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $attributes));
    }

    protected function archiveLeadUnit(Unit $unit, User $actor): Unit
    {
        $unit->forceFill([
            'archived_at' => now(),
            'archived_by' => $actor->id,
        ])->save();

        return $unit->refresh();
    }

    protected function createLeadCustomer(
        Tenant $tenant,
        User $actor,
        array $attributes = [],
    ): Customer {
        $this->leadCustomerSequence++;

        return Customer::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => CustomerType::Individual,
            'category' => CustomerCategory::Buyer,
            'status' => CustomerStatus::Customer,
            'name' => 'CRM Customer '.$this->leadCustomerSequence,
            'phone' => $this->nextLeadPhone(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $attributes));
    }

    protected function createLead(
        Tenant $tenant,
        User $actor,
        array $attributes = [],
    ): Lead {
        $stageValue = $attributes['stage'] ?? LeadStage::New;
        $sourceValue = $attributes['source'] ?? LeadSource::WhatsApp;
        $stage = $stageValue instanceof LeadStage
            ? $stageValue
            : LeadStage::from((string) $stageValue);
        $source = $sourceValue instanceof LeadSource
            ? $sourceValue
            : LeadSource::from((string) $sourceValue);
        $now = now();
        $values = [
            'tenant_id' => $tenant->id,
            'name' => 'CRM Lead '.Str::lower(Str::random(8)),
            'phone' => $this->nextLeadPhone(),
            'email' => null,
            'source' => $source,
            'source_detail' => $source === LeadSource::Other ? 'Other source' : null,
            'project_id' => null,
            'unit_id' => null,
            'stage' => $stage,
            'assigned_to' => $actor->id,
            'next_follow_up_at' => $stage->isOpen() ? $now->copy()->addDay() : null,
            'lost_reason' => null,
            'lost_reason_detail' => null,
            'lost_at' => null,
            'lost_by' => null,
            'customer_id' => null,
            'converted_at' => null,
            'converted_by' => null,
            'conversion_mode' => null,
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
            'archive_reason_detail' => null,
            'restored_by' => null,
            'restored_at' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];

        if ($stage === LeadStage::Lost) {
            $values = array_merge($values, [
                'lost_reason' => LostReason::NotReady,
                'lost_at' => $now,
                'lost_by' => $actor->id,
            ]);
        }

        if ($stage === LeadStage::Won) {
            $customer = $this->createLeadCustomer($tenant, $actor);
            $values = array_merge($values, [
                'customer_id' => $customer->id,
                'converted_at' => $now,
                'converted_by' => $actor->id,
                'conversion_mode' => ConversionMode::LinkedExisting,
            ]);
        }

        if (($attributes['archived_at'] ?? null) !== null) {
            $values = array_merge($values, [
                'archived_at' => $attributes['archived_at'],
                'archived_by' => $attributes['archived_by'] ?? $actor->id,
                'archive_reason' => $attributes['archive_reason'] ?? ArchiveReason::Duplicate,
            ]);
        }

        return Lead::query()->create(array_merge($values, $attributes));
    }

    protected function createLeadActivity(
        Lead $lead,
        User $actor,
        ActivityType $type = ActivityType::Note,
        array $attributes = [],
    ): LeadActivity {
        return LeadActivity::query()->create(array_merge([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'type' => $type,
            'body' => $type === ActivityType::Note ? 'CRM note' : null,
            'payload' => $type === ActivityType::Note
                ? null
                : ['schema_version' => 1],
            'occurred_at' => now(),
            'created_by' => $actor->id,
        ], $attributes));
    }

    protected function nextLeadPhone(): string
    {
        $this->leadPhoneSequence++;

        return sprintf('05%08d', $this->leadPhoneSequence);
    }
}
