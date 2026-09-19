<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Actions;

use App\Models\User;
use App\Modules\AccountingRecognition\Support\PerformanceAccountingSourceCorrectionCoordinator;
use App\Modules\ContractConsideration\Support\ContractConsiderationSourceCoordinator;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use App\Modules\UnitHandover\Exceptions\UnitHandoverValidationFailed;
use App\Modules\UnitHandover\Support\UnitHandoverAuthorization;
use App\Modules\UnitHandover\Support\UnitHandoverFacts;
use App\Modules\UnitHandover\Support\UnitHandoverReversalRecoveryResolver;
use App\Modules\UnitHandover\Support\UnitHandoverTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReverseUnitHandoverPerformanceSource
{
    public function __construct(
        private readonly UnitHandoverTransaction $tx,
        private readonly UnitHandoverAuthorization $auth,
        private readonly UnitHandoverReversalRecoveryResolver $recovery,
        private readonly ContractConsiderationSourceCoordinator $consideration,
        private readonly PerformanceAccountingSourceCorrectionCoordinator $performanceAccounting,
    ) {
    }

    public function execute(
        string $tenantId,
        string $acceptanceId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($acceptanceId)) {
            throw new UnitHandoverValidationFailed(
                'handover_acceptance_id must be a ULID.',
            );
        }

        $reversalOperationId = UnitHandoverFacts::operation(
            $input,
            'reversal_operation_id',
        );

        $reason = UnitHandoverFacts::text(
            $input,
            'reversal_reason',
            500,
        );

        $reference = UnitHandoverFacts::text(
            $input,
            'reversal_reference',
            255,
        );

        /*
         * Historical correction remains authorized even when Tenant.status
         * is no longer active. Membership, actor state, Tenant existence,
         * and tenant-administrator authority are still mandatory.
         */
        $this->auth->authorizeCorrection(
            $tenantId,
            $actor,
        );

        $evidenceId = null;

        try {
            return $this->tx->run(
                function () use (
                    $tenantId,
                    $acceptanceId,
                    $actor,
                    $reversalOperationId,
                    $reason,
                    $reference,
                    &$evidenceId,
                ): string {
                    return $this->reverse(
                        $tenantId,
                        $acceptanceId,
                        $actor,
                        $reversalOperationId,
                        $reason,
                        $reference,
                        $evidenceId,
                    );
                },
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            if (! is_string($evidenceId)) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal uniqueness conflict occurred before source identity was established.',
                    previous: $exception,
                );
            }

            $this->recovery->resolve(
                $tenantId,
                $acceptanceId,
                $evidenceId,
                $reversalOperationId,
                $reason,
                $reference,
            );

            return $this->execute(
                $tenantId,
                $acceptanceId,
                $actor,
                $input,
            );
        }
    }

    private function reverse(
        string $tenantId,
        string $acceptanceId,
        User $actor,
        string $reversalOperationId,
        string $reason,
        string $reference,
        ?string &$evidenceId,
    ): string {
        $this->auth->authorizeCorrectionTransactional(
            $tenantId,
            $actor,
        );

        /*
         * Identity discovery only.
         *
         * No source lock is taken before the frozen parent-to-child
         * correction corridor is established.
         */
        $identity = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->first();

        if ($identity === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance does not exist for this Tenant.',
            );
        }

        $evidenceId = (string) $identity->handover_evidence_id;

        /*
         * Frozen correction lock order:
         *
         * membership
         * -> User
         * -> Tenant
         * -> Contract
         * -> Reservation
         * -> Unit
         * -> Evidence
         * -> Acceptance
         * -> Contract Consideration
         *
         * Contract Consideration appends after the complete source corridor.
         */
        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->contract_id)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal source Contract is missing.',
            );
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $contract->reservation_id)
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal source Reservation is missing.',
            );
        }

        $unit = DB::table('units')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservation->unit_id)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal source Unit is missing.',
            );
        }

        $evidence = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->lockForUpdate()
            ->first();

        if ($evidence === null) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal Evidence is missing.',
            );
        }

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if ($acceptance === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance disappeared during correction.',
            );
        }

        if (
            $acceptance->handover_evidence_id !== $evidence->id
            || $acceptance->contract_id !== $contract->id
            || $acceptance->reservation_id !== $reservation->id
            || $acceptance->unit_id !== $unit->id
            || $acceptance->customer_id !== $reservation->customer_id
            || $evidence->contract_id !== $contract->id
            || $evidence->reservation_id !== $reservation->id
            || $evidence->unit_id !== $unit->id
            || $evidence->customer_id !== $reservation->customer_id
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal source provenance is inconsistent.',
            );
        }

        if (
            $acceptance->status === 'reversed'
            || $evidence->status === 'reversed'
        ) {
            $this->recovery->assertReplay(
                $acceptance,
                $evidence,
                $evidenceId,
                $reversalOperationId,
                $reason,
                $reference,
            );

            $this->performanceAccounting->assertSourceCorrectionReplay(
                $tenantId,
                $acceptance,
                $reversalOperationId,
            );

            $this->consideration->reverseUnitHandoverAcceptance(
                $tenantId,
                $acceptanceId,
            );

            return (string) $acceptance->id;
        }

        if (
            $acceptance->status !== 'effective'
            || $evidence->status !== 'effective'
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal requires effective Acceptance and Evidence.',
            );
        }

        $sameAcceptanceOperation =
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'reversal_operation_id',
                    $reversalOperationId,
                )
                ->lockForUpdate()
                ->first();

        if (
            $sameAcceptanceOperation !== null
            && $sameAcceptanceOperation->id !== $acceptanceId
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal operation identity is already used by another Acceptance.',
            );
        }

        $sameEvidenceOperation =
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'reversal_operation_id',
                    $reversalOperationId,
                )
                ->lockForUpdate()
                ->first();

        if (
            $sameEvidenceOperation !== null
            && $sameEvidenceOperation->id !== $evidenceId
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal operation identity is already used by another Evidence record.',
            );
        }

        $this->performanceAccounting->reverseForSourceCorrection(
            $tenantId,
            $acceptance,
            $actor,
            $reversalOperationId,
            $reason,
        );

        $now = CarbonImmutable::now('UTC');

        $updatedAcceptance =
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('id', $acceptanceId)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $reversalOperationId,
                    'reversal_reason' => $reason,
                    'reversal_reference' => $reference,
                    'reversed_by' => $actor->id,
                    'reversed_at' => $now,
                    'updated_at' => $now,
                ]);

        if ($updatedAcceptance !== 1) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance changed during reversal.',
            );
        }

        /*
         * Acceptance MUST be reversed first. PostgreSQL then permits the
         * dependent Evidence reversal in the same transaction.
         */
        $updatedEvidence =
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $reversalOperationId,
                    'reversal_reason' => $reason,
                    'reversal_reference' => $reference,
                    'reversed_by' => $actor->id,
                    'reversed_at' => $now,
                    'updated_at' => $now,
                ]);

        if ($updatedEvidence !== 1) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence changed during reversal.',
            );
        }

        $this->consideration->reverseUnitHandoverAcceptance(
            $tenantId,
            $acceptanceId,
        );

        return $acceptanceId;
    }
}
