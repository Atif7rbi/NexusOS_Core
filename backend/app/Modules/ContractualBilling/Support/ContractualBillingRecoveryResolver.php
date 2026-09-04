<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
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
                || $entitlement->status
                    !== 'effective'
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
            $schedule = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();

            if ($schedule === null) {
                return [
                    'status' => 'retryable',
                    'schedule_id' => null,
                ];
            }

            if (
                $schedule->source_correction_operation_id === null
                && $schedule->status === 'finalized'
            ) {
                $partialEntitlement = DB::table(
                    'contractual_billing_entitlements',
                )
                    ->where('tenant_id', $tenantId)
                    ->where('schedule_id', $sourceScheduleId)
                    ->whereNotNull(
                        'source_correction_operation_id',
                    )
                    ->exists();

                if ($partialEntitlement) {
                    throw new ContractualBillingConflict(
                        'Source cancellation recovery found partial Entitlement correction truth.',
                    );
                }

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
                || $schedule->source_correction_reference
                    !== $reference
            ) {
                throw new ContractualBillingConflict(
                    'Source cancellation recovery found inconsistent Schedule truth.',
                );
            }

            $actual = [];

            $entitlements = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $sourceScheduleId)
                ->orderBy('id')
                ->get();

            foreach ($entitlements as $entitlement) {
                if (
                    $entitlement->status !== 'reversed'
                    || $entitlement->source_correction_operation_id
                        !== $sourceCorrectionOperationId
                    || $entitlement->reversal_operation_id === null
                    || $entitlement->reversal_reason !== $reason
                    || $entitlement->source_rescission_reference
                        !== $reference
                ) {
                    throw new ContractualBillingConflict(
                        'Source cancellation recovery found inconsistent Entitlement truth.',
                    );
                }

                $actual[(string) $entitlement->id] =
                    (string) $entitlement->reversal_operation_id;
            }

            ksort($actual, SORT_STRING);

            if ($actual !== $entitlementReversals) {
                throw new ContractualBillingConflict(
                    'Source cancellation recovery found mismatched reversal mapping.',
                );
            }

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
            $source = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();

            $successor = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $successorScheduleId)
                ->first();

            if (
                $source === null
                && $successor === null
            ) {
                return [
                    'status' => 'retryable',
                    'source_schedule_id' => null,
                    'successor_schedule_id' => null,
                ];
            }

            if ($source === null || $successor === null) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found partial Schedule truth.',
                );
            }

            if (
                $source->status === 'finalized'
                && $source->source_correction_operation_id === null
                && $successor->status === 'draft'
                && $successor->finalization_operation_id === null
            ) {
                $partialEntitlement = DB::table(
                    'contractual_billing_entitlements',
                )
                    ->where('tenant_id', $tenantId)
                    ->where('schedule_id', $sourceScheduleId)
                    ->whereNotNull(
                        'source_correction_operation_id',
                    )
                    ->exists();

                if ($partialEntitlement) {
                    throw new ContractualBillingConflict(
                        'Supersession recovery found partial Entitlement correction truth.',
                    );
                }

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
                || $source->source_correction_reference
                    !== $reference
                || $successor->status !== 'finalized'
                || $successor->replaces_schedule_id
                    !== $sourceScheduleId
                || $successor->finalization_operation_id
                    !== $successorFinalizationOperationId
            ) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found inconsistent Schedule truth.',
                );
            }

            $actual = [];

            $entitlements = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $sourceScheduleId)
                ->orderBy('id')
                ->get();

            foreach ($entitlements as $entitlement) {
                if (
                    $entitlement->status !== 'reversed'
                    || $entitlement->source_correction_operation_id
                        !== $sourceCorrectionOperationId
                    || $entitlement->reversal_operation_id === null
                ) {
                    throw new ContractualBillingConflict(
                        'Supersession recovery found inconsistent Entitlement truth.',
                    );
                }

                $actual[(string) $entitlement->id] =
                    (string) $entitlement->reversal_operation_id;
            }

            ksort($actual, SORT_STRING);

            if ($actual !== $entitlementReversals) {
                throw new ContractualBillingConflict(
                    'Supersession recovery found mismatched reversal mapping.',
                );
            }

            return [
                'status' => 'committed',
                'source_schedule_id' => (string) $source->id,
                'successor_schedule_id' => (string) $successor->id,
            ];
        });
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
