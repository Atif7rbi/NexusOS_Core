<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use App\Modules\ContractualBilling\Support\EntitlementReceivableSourceCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CorrectFinalizedContractualBillingSchedule
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
        private readonly EntitlementReceivableSourceCorrection $receivableCorrection,
    ) {}

    public function execute(
        string $tenantId,
        string $scheduleId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($scheduleId)) {
            throw new ContractualBillingValidationFailed(
                'schedule_id must be a ULID.',
            );
        }

        $facts = $this->canonicalize($input);

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $scheduleId,
            $actor,
            $facts,
        ): string {
            $this->auth->authorizeTransactional(
                $tenantId,
                $actor,
            );

            $identity = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            /*
             * Frozen correction lock order:
             * authorization -> Contract -> Schedule -> Obligations ->
             * Entitlements -> existing Links -> linked Receivables.
             */
            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $schedule = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->lockForUpdate()
                ->first();

            if (
                $schedule === null
                || $schedule->contract_id !== $contract->id
            ) {
                throw new ContractualBillingConflict(
                    'Source Schedule provenance changed during correction.',
                );
            }

            DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $scheduleId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $schedule->status === 'cancelled'
                && $schedule->finalization_operation_id !== null
            ) {
                return $this->replay(
                    $tenantId,
                    $schedule,
                    $facts,
                );
            }

            if ($schedule->status !== 'finalized') {
                throw new ContractualBillingConflict(
                    'Only a finalized Schedule can use source correction.',
                );
            }

            $sameOperation = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'source_correction_operation_id',
                    $facts['source_correction_operation_id'],
                )
                ->lockForUpdate()
                ->first();

            if (
                $sameOperation !== null
                && $sameOperation->id !== $scheduleId
            ) {
                throw new ContractualBillingConflict(
                    'Source correction operation identity was reused with different facts.',
                );
            }

            $entitlements = DB::table(
                'contractual_billing_entitlements',
            )
                ->where('tenant_id', $tenantId)
                ->where('schedule_id', $scheduleId)
                ->where('status', 'effective')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $expectedIds = $entitlements
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $providedIds = array_keys(
                $facts['entitlement_reversals'],
            );

            if ($providedIds !== $expectedIds) {
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

            $downstream = $this->receivableCorrection
                ->lockForFirstCorrection($tenantId, $entitlements);

            $now = CarbonImmutable::now('UTC');

            $this->receivableCorrection->cancelLinkedReceivables(
                $tenantId,
                $downstream,
                $actor,
                $facts['source_correction_operation_id'],
                $now,
                $facts['source_correction_reason'],
            );

            foreach ($entitlements as $entitlement) {
                $entitlementId = (string) $entitlement->id;

                DB::table('contractual_billing_entitlements')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $entitlementId)
                    ->update([
                        'status' => 'reversed',
                        'reversal_operation_id' => $facts['entitlement_reversals'][$entitlementId],
                        'reversed_by' => $actor->id,
                        'reversed_at' => $now,
                        'reversal_reason' => $facts['source_correction_reason'],
                        'source_correction_operation_id' => $facts['source_correction_operation_id'],
                        'source_rescission_reference' => $facts['source_correction_reference'],
                        'updated_at' => $now,
                    ]);
            }

            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->update([
                    'status' => 'cancelled',
                    'source_correction_operation_id' => $facts['source_correction_operation_id'],
                    'source_corrected_by' => $actor->id,
                    'source_corrected_at' => $now,
                    'source_correction_reason' => $facts['source_correction_reason'],
                    'source_correction_reference' => $facts['source_correction_reference'],
                    'updated_at' => $now,
                ]);

            return $scheduleId;
        });
    }

    /**
     * @return array{
     *   source_correction_operation_id:string,
     *   source_correction_reason:string,
     *   source_correction_reference:?string,
     *   entitlement_reversals:array<string,string>
     * }
     */
    private function canonicalize(array $input): array
    {
        $operationId = ContractualBillingFacts::operation(
            $input,
            'source_correction_operation_id',
        );

        $reason = ContractualBillingFacts::text(
            $input,
            'source_correction_reason',
        );

        $reference = ContractualBillingFacts::optionalText(
            $input,
            'source_correction_reference',
        );

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
                    'entitlement_reversals must contain ULID entitlement and reversal operation identities.',
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
            'source_correction_operation_id' => $operationId,
            'source_correction_reason' => $reason,
            'source_correction_reference' => $reference,
            'entitlement_reversals' => $canonical,
        ];
    }

    private function replay(
        string $tenantId,
        object $schedule,
        array $facts,
    ): string {
        if (
            $schedule->source_correction_operation_id
                !== $facts['source_correction_operation_id']
            || $schedule->source_correction_reason
                !== $facts['source_correction_reason']
            || $schedule->source_correction_reference
                !== $facts['source_correction_reference']
        ) {
            throw new ContractualBillingConflict(
                'Source correction operation was replayed with different facts.',
            );
        }

        $entitlements = DB::table(
            'contractual_billing_entitlements',
        )
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $actualMapping = [];

        foreach ($entitlements as $entitlement) {
            if (
                $entitlement->status !== 'reversed'
                || $entitlement->source_correction_operation_id
                    !== $facts['source_correction_operation_id']
                || $entitlement->reversal_operation_id === null
                || $entitlement->reversal_reason
                    !== $facts['source_correction_reason']
                || $entitlement->source_rescission_reference
                    !== $facts['source_correction_reference']
            ) {
                throw new ContractualBillingConflict(
                    'Source correction replay found inconsistent Entitlement history.',
                );
            }

            $actualMapping[(string) $entitlement->id] =
                (string) $entitlement->reversal_operation_id;
        }

        ksort($actualMapping, SORT_STRING);

        if (
            $actualMapping
            !== $facts['entitlement_reversals']
        ) {
            throw new ContractualBillingConflict(
                'Source correction replay used a different Entitlement reversal mapping.',
            );
        }

        $this->receivableCorrection->assertReplayCoherent(
            $tenantId,
            $entitlements,
            $facts['source_correction_operation_id'],
        );

        return (string) $schedule->id;
    }
}
