<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Models\User;
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
use Illuminate\Support\Facades\DB;

/**
 * Converts a Lead to a Customer (Milestone B — Lead → Customer Conversion).
 *
 * Two conversion intents are supported:
 *
 * Intent A — create_new:
 *   No existing_customer_id. The Action creates a new Customer from the
 *   Lead's data. If a Customer with the same phone appears inside the
 *   transaction, a LeadConversionNewCustomerConflictException is thrown
 *   and the frontend must re-present the conflict for explicit confirmation.
 *   No automatic linking occurs under any race condition.
 *
 * Intent B — link_existing:
 *   An existing_customer_id is provided. The Action locks and re-validates
 *   the Customer before linking. Behaviour by Customer status:
 *     customer  → link, no status change
 *     inactive  → link, no status change
 *     lead      → link + promote to customer (atomic)
 *     archived  → reject with LeadConversionCustomerArchivedException
 *
 * The entire operation is wrapped in a single database transaction.
 * Lead and Customer rows are locked (SELECT FOR UPDATE) in a fixed order
 * to prevent deadlocks.
 */
final class ConvertLeadToCustomerAction
{
    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * @param  array{
     *   type: string,
     *   category: string,
     * }  $customerData  Required only for create_new intent.
     *
     * @throws LeadNotInOpenStageException
     * @throws LeadAlreadyConvertedException
     * @throws LeadIsArchivedException
     * @throws LeadConversionCustomerArchivedException
     * @throws LeadConversionCustomerChangedException
     * @throws LeadConversionNewCustomerConflictException
     */
    public function execute(
        string $tenantId,
        string $leadId,
        int $actorId,
        string $conversionIntent,    // 'create_new' | 'link_existing'
        ?string $existingCustomerId,
        array $customerData = [],    // type + category for create_new
    ): Lead {
        return DB::transaction(function () use (
            $tenantId,
            $leadId,
            $actorId,
            $conversionIntent,
            $existingCustomerId,
            $customerData,
        ): Lead {
            // Lock Lead first (fixed order: Lead before Customer)
            $lead = Lead::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($leadId)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard: Lead must be in an open stage
            if (! $lead->isOpen()) {
                if ($lead->stage === LeadStage::Won) {
                    throw new LeadAlreadyConvertedException;
                }
                throw new LeadNotInOpenStageException;
            }

            // Guard: Lead must not be archived
            if ($lead->isArchived()) {
                throw new LeadIsArchivedException;
            }

            // Normalize phone now that we have the Lead row
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
    }

    // -------------------------------------------------------------------------

    private function handleCreateNew(
        Lead $lead,
        string $tenantId,
        int $actorId,
        string $normalizedPhone,
        array $customerData,
    ): Lead {
        // Re-check for a matching Customer inside the transaction
        $conflict = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $normalizedPhone)
            ->lockForUpdate()
            ->first();

        if ($conflict !== null) {
            // A Customer appeared since the preview — surface the conflict.
            // Never link automatically.
            throw new LeadConversionNewCustomerConflictException(
                conflictingCustomerId: (string) $conflict->id,
                conflictingCustomerStatus: $conflict->status->value,
            );
        }

        $customer = Customer::query()->create([
            'tenant_id'  => $tenantId,
            'name'       => $lead->name,
            'phone'      => $normalizedPhone,
            'email'      => $lead->email,
            'type'       => $customerData['type'],
            'category'   => $customerData['category'],
            'status'     => CustomerStatus::Customer->value,
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
        // Lock Customer second (fixed order prevents deadlock)
        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($existingCustomerId)
            ->lockForUpdate()
            ->first();

        if ($customer === null) {
            throw new LeadConversionCustomerChangedException;
        }

        // Re-validate that phone still matches (data may have changed)
        $customerPhone = $this->phoneNormalizer->normalizeRequired($customer->phone);
        if ($customerPhone !== $normalizedPhone) {
            throw new LeadConversionCustomerChangedException;
        }

        // Reject archived
        if ($customer->status === CustomerStatus::Archived) {
            throw new LeadConversionCustomerArchivedException;
        }

        $previousStatus = $customer->status;

        // Promote legacy lead → customer
        if ($customer->status === CustomerStatus::Lead) {
            $customer->update([
                'status'     => CustomerStatus::Customer->value,
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

        // customer or inactive — link without modifying status
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
            'stage'            => LeadStage::Won->value,
            'customer_id'      => $customer->id,
            'converted_at'     => now(),
            'converted_by'     => $actorId,
            'conversion_mode'  => $mode->value,
            'next_follow_up_at'=> null,
            'updated_by'       => $actorId,
        ]);

        LeadActivity::query()->create([
            'tenant_id'  => $lead->tenant_id,
            'lead_id'    => $lead->id,
            'type'       => 'stage_change',
            'body'       => null,
            'payload'    => [
                'from_stage'               => $fromStage,
                'to_stage'                 => LeadStage::Won->value,
                'customer_id'              => $customer->id,
                'conversion_mode'          => $mode->value,
                'previous_customer_status' => $previousStatus?->value,
                'new_customer_status'      => $newStatus->value,
            ],
            'occurred_at' => now(),
            'created_by'  => $actorId,
        ]);

        $lead->refresh();

        return $lead;
    }
}
