<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Leads\Enums\ConversionMode;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Exceptions\LeadAlreadyConvertedException;
use App\Modules\Leads\Exceptions\LeadConversionCustomerArchivedException;
use App\Modules\Leads\Exceptions\LeadConversionCustomerChangedException;
use App\Modules\Leads\Exceptions\LeadConversionNewCustomerConflictException;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Converts a Lead to a Customer atomically.
 *
 * The main transaction owns every mutation. A PostgreSQL unique violation
 * aborts that transaction, so conflict translation happens only after
 * DB::transaction() has rolled it back and re-thrown the QueryException.
 */
final class ConvertLeadToCustomerAction
{
    private const CUSTOMERS_PHONE_UNIQUE_CONSTRAINT = 'customers_tenant_phone_unique';

    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * @param  array{type: string, category: string}  $customerData
     */
    public function execute(
        string $tenantId,
        string $leadId,
        int $actorId,
        string $conversionIntent,
        ?string $existingCustomerId,
        array $customerData = [],
    ): Lead {
        try {
            return DB::transaction(function () use (
                $tenantId,
                $leadId,
                $actorId,
                $conversionIntent,
                $existingCustomerId,
                $customerData,
            ): Lead {
                $lead = Lead::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($leadId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lead->isOpen()) {
                    if ($lead->stage === LeadStage::Won) {
                        throw new LeadAlreadyConvertedException;
                    }

                    throw new LeadNotInOpenStageException;
                }

                if ($lead->isArchived()) {
                    throw new LeadIsArchivedException;
                }

                $normalizedPhone = $this->phoneNormalizer->normalizeRequired($lead->phone);

                if ($conversionIntent === 'create_new') {
                    return $this->handleCreateNew(
                        $lead,
                        $tenantId,
                        $actorId,
                        $normalizedPhone,
                        $customerData,
                    );
                }

                return $this->handleLinkExisting(
                    $lead,
                    $tenantId,
                    $actorId,
                    $existingCustomerId,
                    $normalizedPhone,
                );
            });
        } catch (QueryException $exception) {
            if (! $this->isCustomersPhoneUniqueViolation($exception)) {
                throw $exception;
            }

            // The failed transaction has already been rolled back here.
            $leadPhone = Lead::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($leadId)
                ->value('phone');

            if (! is_string($leadPhone) || $leadPhone === '') {
                throw $exception;
            }

            $normalizedPhone = $this->phoneNormalizer->normalizeRequired($leadPhone);
            $conflict = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('phone', $normalizedPhone)
                ->first();

            if ($conflict === null) {
                throw $exception;
            }

            throw new LeadConversionNewCustomerConflictException(
                conflictingCustomerId: (string) $conflict->id,
                conflictingCustomerStatus: $conflict->status->value,
                conflictingCustomerName: $conflict->name,
                conflictSource: LeadConversionNewCustomerConflictException::SOURCE_UNIQUE_VIOLATION,
            );
        }
    }

    /**
     * @param  array{type: string, category: string}  $customerData
     */
    private function handleCreateNew(
        Lead $lead,
        string $tenantId,
        int $actorId,
        string $normalizedPhone,
        array $customerData,
    ): Lead {
        $conflict = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $normalizedPhone)
            ->lockForUpdate()
            ->first();

        if ($conflict !== null) {
            throw new LeadConversionNewCustomerConflictException(
                conflictingCustomerId: (string) $conflict->id,
                conflictingCustomerStatus: $conflict->status->value,
                conflictingCustomerName: $conflict->name,
                conflictSource: LeadConversionNewCustomerConflictException::SOURCE_PRECHECK,
            );
        }

        $customer = Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $lead->name,
            'phone' => $normalizedPhone,
            'email' => $lead->email,
            'type' => $customerData['type'],
            'category' => $customerData['category'],
            'status' => CustomerStatus::Customer->value,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $this->finalise(
            $lead,
            $customer,
            $actorId,
            ConversionMode::Created,
            previousStatus: null,
            newStatus: CustomerStatus::Customer,
        );
    }

    private function handleLinkExisting(
        Lead $lead,
        string $tenantId,
        int $actorId,
        ?string $existingCustomerId,
        string $normalizedPhone,
    ): Lead {
        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($existingCustomerId)
            ->lockForUpdate()
            ->first();

        if ($customer === null) {
            throw new LeadConversionCustomerChangedException;
        }

        $customerPhone = $this->phoneNormalizer->normalizeRequired($customer->phone);
        if ($customerPhone !== $normalizedPhone) {
            throw new LeadConversionCustomerChangedException;
        }

        if ($customer->status === CustomerStatus::Archived) {
            throw new LeadConversionCustomerArchivedException;
        }

        $previousStatus = $customer->status;

        if ($customer->status === CustomerStatus::Lead) {
            $customer->update([
                'status' => CustomerStatus::Customer->value,
                'updated_by' => $actorId,
            ]);
            $customer->refresh();

            return $this->finalise(
                $lead,
                $customer,
                $actorId,
                ConversionMode::LinkedAndPromoted,
                previousStatus: $previousStatus,
                newStatus: CustomerStatus::Customer,
            );
        }

        return $this->finalise(
            $lead,
            $customer,
            $actorId,
            ConversionMode::LinkedExisting,
            previousStatus: $previousStatus,
            newStatus: $previousStatus,
        );
    }

    private function finalise(
        Lead $lead,
        Customer $customer,
        int $actorId,
        ConversionMode $mode,
        ?CustomerStatus $previousStatus,
        CustomerStatus $newStatus,
    ): Lead {
        $fromStage = $lead->stage->value;

        $lead->update([
            'stage' => LeadStage::Won->value,
            'customer_id' => $customer->id,
            'converted_at' => now(),
            'converted_by' => $actorId,
            'conversion_mode' => $mode->value,
            'next_follow_up_at' => null,
            'updated_by' => $actorId,
        ]);

        LeadActivity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'type' => 'stage_change',
            'body' => null,
            'payload' => [
                'from_stage' => $fromStage,
                'to_stage' => LeadStage::Won->value,
                'customer_id' => $customer->id,
                'conversion_mode' => $mode->value,
                'previous_customer_status' => $previousStatus?->value,
                'new_customer_status' => $newStatus->value,
            ],
            'occurred_at' => now(),
            'created_by' => $actorId,
        ]);

        $lead->refresh();

        return $lead;
    }

    private function isCustomersPhoneUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $details = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return $sqlState === '23505'
            && str_contains($details, self::CUSTOMERS_PHONE_UNIQUE_CONSTRAINT);
    }
}
