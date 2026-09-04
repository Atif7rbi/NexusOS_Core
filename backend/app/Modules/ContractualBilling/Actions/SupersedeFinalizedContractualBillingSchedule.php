<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupersedeFinalizedContractualBillingSchedule
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        string $sourceScheduleId,
        string $successorScheduleId,
        User $actor,
        array $input,
    ): string {
        if (
            ! Str::isUlid($sourceScheduleId)
            || ! Str::isUlid($successorScheduleId)
        ) {
            throw new ContractualBillingValidationFailed(
                'source_schedule_id and successor_schedule_id must be ULIDs.',
            );
        }

        $facts = $this->canonicalize($input);

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $sourceScheduleId,
            $successorScheduleId,
            $actor,
            $facts,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            $identity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $source = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $successor = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $successorScheduleId)
                ->lockForUpdate()
                ->first();

            if ($successor === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            if (
                $source->contract_id !== $contract->id
                || $successor->contract_id !== $contract->id
                || $successor->replaces_schedule_id
                    !== $sourceScheduleId
            ) {
                throw new ContractualBillingConflict(
                    'Successor Schedule provenance is inconsistent.',
                );
            }

            if ($source->status === 'superseded') {
                return $this->replay(
                    $tenantId,
                    $source,
                    $successor,
                    $facts,
                );
            }

            if (
                $source->status !== 'finalized'
                || $successor->status !== 'draft'
            ) {
                throw new ContractualBillingConflict(
                    'Supersession requires finalized source and draft successor Schedules.',
                );
            }

            if (
                ! in_array($contract->status, ['active', 'completed'], true)
                || $contract->currency !== 'SAR'
            ) {
                throw new ContractualBillingConflict(
                    'Contract is not eligible for Schedule supersession.',
                );
            }

            $sourceObligations = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $sourceScheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $successorObligations = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $successorScheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $included = $successorObligations->filter(
                static fn (object $row): bool => $row->draft_membership_status === 'included',
            );

            if ($included->isEmpty()) {
                throw new ContractualBillingConflict(
                    'Successor Schedule requires at least one included Obligation.',
                );
            }

            $total = BigDecimal::zero();

            foreach ($included as $obligation) {
                if (
                    $obligation->contract_id !== $contract->id
                    || $obligation->currency !== 'SAR'
                    || $obligation->trigger_kind
                        !== 'fixed_date_unconditional'
                ) {
                    throw new ContractualBillingConflict(
                        'Successor contains an ineligible Obligation.',
                    );
                }

                $total = $total->plus(
                    BigDecimal::of((string) $obligation->amount),
                );
            }

            if (
                $total->compareTo(
                    BigDecimal::of((string) $contract->total_amount),
                ) !== 0
            ) {
                throw new ContractualBillingConflict(
                    'Successor total must equal Contract total_amount.',
                );
            }

            $entitlements = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $sourceScheduleId)
                ->where('status', 'effective')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $expectedIds = $entitlements
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if (
                array_keys($facts['entitlement_reversals'])
                !== $expectedIds
            ) {
                throw new ContractualBillingConflict(
                    'Entitlement reversal mapping must exactly match the locked effective Entitlement set.',
                );
            }

            if (
                $expectedIds !== []
                && $facts['source_correction_reference'] === null
            ) {
                throw new ContractualBillingValidationFailed(
                    'source_correction_reference is required when reversing effective Entitlements.',
                );
            }

            $timezone = DB::table('tenants')
                ->where('id', $tenantId)
                ->value('timezone');

            if (! is_string($timezone) || trim($timezone) === '') {
                throw new ContractualBillingConflict(
                    'Tenant contractual timezone is unavailable.',
                );
            }

            $now = now();

            foreach ($entitlements as $entitlement) {
                $id = (string) $entitlement->id;

                DB::table('contractual_billing_entitlements')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $id)
                    ->update([
                        'status' => 'reversed',
                        'reversal_operation_id' => $facts['entitlement_reversals'][$id],
                        'reversed_by' => $actor->id,
                        'reversed_at' => $now,
                        'reversal_reason' => $facts['source_correction_reason'],
                        'source_correction_operation_id' => $facts['source_correction_operation_id'],
                        'source_rescission_reference' => $facts['source_correction_reference'],
                        'updated_at' => $now,
                    ]);
            }

            /*
             * Source must stop being current before successor finalization
             * reaches the immediate partial unique index.
             */
            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->update([
                    'status' => 'superseded',
                    'source_correction_operation_id' => $facts['source_correction_operation_id'],
                    'source_corrected_by' => $actor->id,
                    'source_corrected_at' => $now,
                    'source_correction_reason' => $facts['source_correction_reason'],
                    'source_correction_reference' => $facts['source_correction_reference'],
                    'updated_at' => $now,
                ]);

            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $successorScheduleId)
                ->update([
                    'status' => 'finalized',
                    'contractual_timezone' => $timezone,
                    'finalization_operation_id' => $facts['successor_finalization_operation_id'],
                    'finalized_by' => $actor->id,
                    'finalized_at' => $now,
                    'updated_at' => $now,
                ]);

            return $successorScheduleId;
        });
    }

    private function canonicalize(array $input): array
    {
        $mapping = $input['entitlement_reversals'] ?? null;

        if (! is_array($mapping)) {
            throw new ContractualBillingValidationFailed(
                'entitlement_reversals must be an entitlement_id to reversal_operation_id mapping.',
            );
        }

        $canonical = [];

        foreach ($mapping as $entitlementId => $reversalOperationId) {
            if (
                ! is_string($entitlementId)
                || ! Str::isUlid($entitlementId)
                || ! is_string($reversalOperationId)
                || ! Str::isUlid($reversalOperationId)
            ) {
                throw new ContractualBillingValidationFailed(
                    'entitlement_reversals must contain ULID identities.',
                );
            }

            $canonical[$entitlementId] = $reversalOperationId;
        }

        ksort($canonical, SORT_STRING);

        if (
            count(array_unique(array_values($canonical)))
            !== count($canonical)
        ) {
            throw new ContractualBillingValidationFailed(
                'Each Entitlement reversal requires a distinct reversal_operation_id.',
            );
        }

        return [
            'source_correction_operation_id' => ContractualBillingFacts::operation(
                $input,
                'source_correction_operation_id',
            ),
            'successor_finalization_operation_id' => ContractualBillingFacts::operation(
                $input,
                'successor_finalization_operation_id',
            ),
            'source_correction_reason' => ContractualBillingFacts::text(
                $input,
                'source_correction_reason',
            ),
            'source_correction_reference' => ContractualBillingFacts::optionalText(
                $input,
                'source_correction_reference',
            ),
            'entitlement_reversals' => $canonical,
        ];
    }

    private function replay(
        string $tenantId,
        object $source,
        object $successor,
        array $facts,
    ): string {
        if (
            $source->source_correction_operation_id
                !== $facts['source_correction_operation_id']
            || $source->source_correction_reason
                !== $facts['source_correction_reason']
            || $source->source_correction_reference
                !== $facts['source_correction_reference']
            || $successor->status !== 'finalized'
            || $successor->finalization_operation_id
                !== $facts['successor_finalization_operation_id']
        ) {
            throw new ContractualBillingConflict(
                'Schedule supersession was replayed with different facts.',
            );
        }

        $entitlements = DB::table(
            'contractual_billing_entitlements',
        )
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $source->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $actual = [];

        foreach ($entitlements as $entitlement) {
            if (
                $entitlement->status !== 'reversed'
                || $entitlement->source_correction_operation_id
                    !== $facts['source_correction_operation_id']
                || $entitlement->reversal_operation_id === null
            ) {
                throw new ContractualBillingConflict(
                    'Schedule supersession replay found inconsistent Entitlement history.',
                );
            }

            $actual[(string) $entitlement->id] =
                (string) $entitlement->reversal_operation_id;
        }

        ksort($actual, SORT_STRING);

        if ($actual !== $facts['entitlement_reversals']) {
            throw new ContractualBillingConflict(
                'Schedule supersession replay used a different Entitlement reversal mapping.',
            );
        }

        return (string) $successor->id;
    }
}
