<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ContractualBillingRecoveryResolver
{
    /**
     * @return array{
     *   status:'retryable'|'committed',
     *   entitlement_id:?string
     * }
     */
    public function resolveEntitlementActivation(
        string $tenantId,
        string $operationId,
        string $obligationId,
    ): array {
        $this->assertFreshTransaction();

        return DB::transaction(function () use (
            $tenantId,
            $operationId,
            $obligationId,
        ): array {
            $entitlement = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'billing_entitlement_operation_id',
                    $operationId,
                )
                ->first();

            if ($entitlement === null) {
                $historical = DB::table(
                    'contractual_billing_entitlements',
                )
                    ->where('tenant_id', $tenantId)
                    ->where('obligation_id', $obligationId)
                    ->first();

                if ($historical !== null) {
                    throw new ContractualBillingConflict(
                        'Entitlement recovery found conflicting historical truth.',
                    );
                }

                return [
                    'status' => 'retryable',
                    'entitlement_id' => null,
                ];
            }

            if (
                $entitlement->obligation_id !== $obligationId
                || $entitlement->status !== 'effective'
            ) {
                throw new ContractualBillingConflict(
                    'Entitlement recovery found inconsistent committed facts.',
                );
            }

            return [
                'status' => 'committed',
                'entitlement_id' => (string) $entitlement->id,
            ];
        });
    }

    /**
     * @param  array<string,string>  $entitlementReversals
     * @return array{
     *   status:'retryable'|'committed',
     *   schedule_id:?string
     * }
     */
    public function resolveSourceCancellation(
        string $tenantId,
        string $sourceScheduleId,
        string $sourceCorrectionOperationId,
        string $reason,
        ?string $reference,
        array $entitlementReversals,
    ): array {
        $this->assertFreshTransaction();
        ksort($entitlementReversals, SORT_STRING);

        return DB::transaction(function () use (
            $tenantId,
            $sourceScheduleId,
            $sourceCorrectionOperationId,
            $reason,
            $reference,
            $entitlementReversals,
        ): array {
            $identity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();

            if ($identity === null) {
                return [
                    'status' => 'retryable',
                    'schedule_id' => null,
                ];
            }

            $locked = $this->lockSourceRecoveryCorridor(
                $tenantId,
                $sourceScheduleId,
                null,
            );
            $schedule = $locked['source'];

            if (
                $schedule->source_correction_operation_id === null
                && $schedule->status === 'finalized'
            ) {
                $this->assertRetryableDownstreamState(
                    $locked['entitlements'],
                    $locked['links'],
                    $locked['receivables'],
                );

                return [
                    'status' => 'retryable',
                    'schedule_id' => null,
                ];
            }

            if (
                $schedule->status !== 'cancelled'
                || $schedule->finalization_operation_id === null
                || $schedule->source_correction_operation_id
                    !== $sourceCorrectionOperationId
                || $schedule->source_correction_reason !== $reason
                || $schedule->source_correction_reference !== $reference
            ) {
                throw new ContractualBillingConflict(
                    'Source cancellation recovery found inconsistent Schedule truth.',
                );
            }

            $actual = $this->assertEntitlementCorrectionTruth(
                $locked['entitlements'],
                $sourceCorrectionOperationId,
                $reason,
                $reference,
                true,
            );

            if ($actual !== $entitlementReversals) {
                throw new ContractualBillingConflict(
                    'Source cancellation recovery found mismatched reversal mapping.',
                );
            }

            $this->assertCommittedDownstreamState(
                $locked['entitlements'],
                $locked['links'],
                $locked['receivables'],
                $sourceCorrectionOperationId,
            );

            return [
                'status' => 'committed',
                'schedule_id' => (string) $schedule->id,
            ];
        });
    }

    /**
     * @param  array<string,string>  $entitlementReversals
     * @return array{
     *   status:'retryable'|'committed',
     *   source_schedule_id:?string,
     *   successor_schedule_id:?string
     * }
     */
    public function resolveSupersession(
        string $tenantId,
        string $sourceScheduleId,
        string $successorScheduleId,
        string $sourceCorrectionOperationId,
        string $successorFinalizationOperationId,
        string $reason,
        ?string $reference,
        array $entitlementReversals,
    ): array {
        $this->assertFreshTransaction();
        ksort($entitlementReversals, SORT_STRING);

        return DB::transaction(function () use (
            $tenantId,
            $sourceScheduleId,
            $successorScheduleId,
            $sourceCorrectionOperationId,
            $successorFinalizationOperationId,
            $reason,
            $reference,
            $entitlementReversals,
        ): array {
            $sourceIdentity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();
            $successorIdentity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $successorScheduleId)
                ->first();

            if ($sourceIdentity === null && $successorIdentity === null) {
                return [
                    'status' => 'retryable',
                    'source_schedule_id' => null,
                    'successor_schedule_id' => null,
                ];
            }

            if ($sourceIdentity === null || $successorIdentity === null) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found partial Schedule truth.',
                );
            }

            $locked = $this->lockSourceRecoveryCorridor(
                $tenantId,
                $sourceScheduleId,
                $successorScheduleId,
            );
            $source = $locked['source'];
            $successor = $locked['successor'];

            if (
                $source->status === 'finalized'
                && $source->source_correction_operation_id === null
                && $successor->status === 'draft'
                && $successor->finalization_operation_id === null
            ) {
                $this->assertRetryableDownstreamState(
                    $locked['entitlements'],
                    $locked['links'],
                    $locked['receivables'],
                );

                return [
                    'status' => 'retryable',
                    'source_schedule_id' => null,
                    'successor_schedule_id' => null,
                ];
            }

            if (
                $source->status !== 'superseded'
                || $source->source_correction_operation_id
                    !== $sourceCorrectionOperationId
                || $source->source_correction_reason !== $reason
                || $source->source_correction_reference !== $reference
                || $successor->status !== 'finalized'
                || $successor->replaces_schedule_id !== $sourceScheduleId
                || $successor->finalization_operation_id
                    !== $successorFinalizationOperationId
            ) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found inconsistent Schedule truth.',
                );
            }

            $actual = $this->assertEntitlementCorrectionTruth(
                $locked['entitlements'],
                $sourceCorrectionOperationId,
                $reason,
                $reference,
                false,
            );

            if ($actual !== $entitlementReversals) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found mismatched reversal mapping.',
                );
            }

            $this->assertCommittedDownstreamState(
                $locked['entitlements'],
                $locked['links'],
                $locked['receivables'],
                $sourceCorrectionOperationId,
            );

            return [
                'status' => 'committed',
                'source_schedule_id' => (string) $source->id,
                'successor_schedule_id' => (string) $successor->id,
            ];
        });
    }

    /**
     * @return array{
     *   source:object,
     *   successor:?object,
     *   entitlements:Collection<int,object>,
     *   links:Collection<int,object>,
     *   receivables:Collection<int,object>
     * }
     */
    private function lockSourceRecoveryCorridor(
        string $tenantId,
        string $sourceScheduleId,
        ?string $successorScheduleId,
    ): array {
        $identity = DB::table('contractual_billing_schedules')
            ->where('tenant_id', $tenantId)
            ->where('id', $sourceScheduleId)
            ->first();

        if ($identity === null) {
            throw new ContractualBillingConflict(
                'Contractual Billing recovery source Schedule disappeared.',
            );
        }

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->contract_id)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new ContractualBillingConflict(
                'Contractual Billing recovery source Contract is unavailable.',
            );
        }

        $source = DB::table('contractual_billing_schedules')
            ->where('tenant_id', $tenantId)
            ->where('id', $sourceScheduleId)
            ->lockForUpdate()
            ->first();

        if ($source === null || $source->contract_id !== $contract->id) {
            throw new ContractualBillingConflict(
                'Contractual Billing recovery source Schedule provenance is inconsistent.',
            );
        }

        $successor = null;

        if ($successorScheduleId !== null) {
            $successor = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $successorScheduleId)
                ->lockForUpdate()
                ->first();

            if (
                $successor === null
                || $successor->contract_id !== $contract->id
                || $successor->replaces_schedule_id !== $sourceScheduleId
            ) {
                throw new ContractualBillingConflict(
                    'Contractual Billing recovery successor Schedule provenance is inconsistent.',
                );
            }
        }

        DB::table('contractual_billing_obligations')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $sourceScheduleId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($successorScheduleId !== null) {
            DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $successorScheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $entitlements = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $sourceScheduleId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $entitlementIds = $entitlements
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $links = $entitlementIds === []
            ? collect()
            : DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->whereIn('entitlement_id', $entitlementIds)
                ->orderBy('entitlement_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        $receivableIds = $links
            ->pluck('receivable_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();

        $receivables = $receivableIds === []
            ? collect()
            : DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $receivableIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        return [
            'source' => $source,
            'successor' => $successor,
            'entitlements' => $entitlements,
            'links' => $links,
            'receivables' => $receivables,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function assertEntitlementCorrectionTruth(
        Collection $entitlements,
        string $sourceCorrectionOperationId,
        string $reason,
        ?string $reference,
        bool $requireReasonAndReference,
    ): array {
        $actual = [];

        foreach ($entitlements as $entitlement) {
            if (
                $entitlement->status !== 'reversed'
                || $entitlement->source_correction_operation_id
                    !== $sourceCorrectionOperationId
                || $entitlement->reversal_operation_id === null
                || (
                    $requireReasonAndReference
                    && (
                        $entitlement->reversal_reason !== $reason
                        || $entitlement->source_rescission_reference
                            !== $reference
                    )
                )
            ) {
                throw new ContractualBillingConflict(
                    'Contractual Billing recovery found inconsistent Entitlement truth.',
                );
            }

            $actual[(string) $entitlement->id] =
                (string) $entitlement->reversal_operation_id;
        }

        ksort($actual, SORT_STRING);

        return $actual;
    }

    private function assertRetryableDownstreamState(
        Collection $entitlements,
        Collection $links,
        Collection $receivables,
    ): void {
        $entitlementMap = $entitlements->keyBy('id');
        $receivableMap = $receivables->keyBy('id');

        foreach ($entitlements as $entitlement) {
            if ($entitlement->status !== 'effective') {
                throw new ContractualBillingConflict(
                    'Source correction recovery found partial Entitlement correction truth.',
                );
            }
        }

        foreach ($links as $link) {
            $entitlement = $entitlementMap->get($link->entitlement_id);
            $receivable = $receivableMap->get($link->receivable_id);

            if (
                $entitlement === null
                || $receivable === null
                || $link->source_correction_operation_id !== null
                || $receivable->status !== 'recognized'
            ) {
                throw new ContractualBillingConflict(
                    'Source correction recovery found incoherent retryable downstream truth.',
                );
            }
        }
    }

    private function assertCommittedDownstreamState(
        Collection $entitlements,
        Collection $links,
        Collection $receivables,
        string $sourceCorrectionOperationId,
    ): void {
        $entitlementMap = $entitlements->keyBy('id');
        $receivableMap = $receivables->keyBy('id');

        foreach ($links as $link) {
            $entitlement = $entitlementMap->get($link->entitlement_id);
            $receivable = $receivableMap->get($link->receivable_id);

            if (
                $entitlement === null
                || $receivable === null
                || $entitlement->status !== 'reversed'
                || $receivable->status !== 'cancelled'
                || $link->source_correction_operation_id
                    !== $sourceCorrectionOperationId
                || $entitlement->source_correction_operation_id
                    !== $sourceCorrectionOperationId
            ) {
                throw new ContractualBillingConflict(
                    'Source correction recovery found incoherent committed downstream truth.',
                );
            }
        }
    }

    private function assertFreshTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Contractual Billing unknown-outcome recovery requires a fresh transaction.',
            );
        }
    }
}
