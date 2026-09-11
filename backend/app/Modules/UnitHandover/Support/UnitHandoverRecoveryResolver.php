<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Support\Facades\DB;

final class UnitHandoverRecoveryResolver
{
    public function __construct(
        private readonly UnitHandoverEvidenceRecoveryResolver $evidence,
        private readonly UnitHandoverAcceptanceRecoveryResolver $acceptance,
        private readonly UnitHandoverReversalRecoveryResolver $reversal,
    ) {}

    /**
     * @return array{
     *   status:'retryable'|'committed',
     *   evidence_id:?string
     * }
     */
    public function recoverEvidence(
        string $tenantId,
        array $facts,
    ): array {
        $this->assertFreshTransaction();

        return DB::transaction(function () use (
            $tenantId,
            $facts,
        ): array {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $byOperation = DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_operation_id',
                    $facts['handover_evidence_operation_id'],
                )
                ->first();

            $byReference = DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_reference',
                    $facts['handover_evidence_reference'],
                )
                ->first();

            if (
                $byOperation === null
                && $byReference === null
            ) {
                return [
                    'status' => 'retryable',
                    'evidence_id' => null,
                ];
            }

            if (
                $byOperation === null
                || $byReference === null
                || $byOperation->id !== $byReference->id
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover Evidence recovery found partial or contradictory durable truth.',
                );
            }

            $this->evidence->assertMatches(
                $byOperation,
                $facts,
            );

            return [
                'status' => 'committed',
                'evidence_id' => (string) $byOperation->id,
            ];
        });
    }

    /**
     * @return array{
     *   status:'retryable'|'committed',
     *   acceptance_id:?string
     * }
     */
    public function recoverAcceptance(
        string $tenantId,
        array $facts,
    ): array {
        $this->assertFreshTransaction();

        return DB::transaction(function () use (
            $tenantId,
            $facts,
        ): array {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $byOperation =
                DB::table('unit_handover_acceptances')
                    ->where('tenant_id', $tenantId)
                    ->where(
                        'handover_acceptance_operation_id',
                        $facts['handover_acceptance_operation_id'],
                    )
                    ->first();

            $byEvidence =
                DB::table('unit_handover_acceptances')
                    ->where('tenant_id', $tenantId)
                    ->where(
                        'handover_evidence_id',
                        $facts['handover_evidence_id'],
                    )
                    ->first();

            if (
                $byOperation === null
                && $byEvidence === null
            ) {
                $effectiveForContract =
                    DB::table('unit_handover_acceptances')
                        ->where('tenant_id', $tenantId)
                        ->where(
                            'contract_id',
                            $facts['contract_id'],
                        )
                        ->where('status', 'effective')
                        ->first();

                if ($effectiveForContract !== null) {
                    throw new UnitHandoverConflict(
                        'Unit Handover Acceptance recovery found conflicting effective Contract truth.',
                    );
                }

                return [
                    'status' => 'retryable',
                    'acceptance_id' => null,
                ];
            }

            if (
                $byOperation === null
                || $byEvidence === null
                || $byOperation->id !== $byEvidence->id
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover Acceptance recovery found partial or contradictory durable truth.',
                );
            }

            $this->acceptance->assertMatches(
                $byOperation,
                $facts,
            );

            return [
                'status' => 'committed',
                'acceptance_id' => (string) $byOperation->id,
            ];
        });
    }

    /**
     * @return array{
     *   status:'retryable'|'committed',
     *   acceptance_id:?string
     * }
     */
    public function recoverReversal(
        string $tenantId,
        string $acceptanceId,
        string $evidenceId,
        string $reversalOperationId,
        string $reason,
        string $reference,
    ): array {
        $this->assertFreshTransaction();

        return DB::transaction(function () use (
            $tenantId,
            $acceptanceId,
            $evidenceId,
            $reversalOperationId,
            $reason,
            $reference,
        ): array {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $acceptance =
                DB::table('unit_handover_acceptances')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $acceptanceId)
                    ->first();

            $evidence =
                DB::table('unit_handover_evidence')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $evidenceId)
                    ->first();

            if ($acceptance === null || $evidence === null) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal recovery source truth is missing.',
                );
            }

            if (
                $acceptance->handover_evidence_id !== $evidenceId
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal recovery source provenance is inconsistent.',
                );
            }

            if (
                $acceptance->status === 'effective'
                && $evidence->status === 'effective'
                && $acceptance->reversal_operation_id === null
                && $evidence->reversal_operation_id === null
            ) {
                $acceptanceUsingOperation =
                    DB::table('unit_handover_acceptances')
                        ->where('tenant_id', $tenantId)
                        ->where(
                            'reversal_operation_id',
                            $reversalOperationId,
                        )
                        ->first();

                $evidenceUsingOperation =
                    DB::table('unit_handover_evidence')
                        ->where('tenant_id', $tenantId)
                        ->where(
                            'reversal_operation_id',
                            $reversalOperationId,
                        )
                        ->first();

                if (
                    $acceptanceUsingOperation !== null
                    || $evidenceUsingOperation !== null
                ) {
                    throw new UnitHandoverConflict(
                        'Unit Handover reversal recovery found operation identity attached to different durable truth.',
                    );
                }

                return [
                    'status' => 'retryable',
                    'acceptance_id' => null,
                ];
            }

            if (
                $acceptance->status === 'reversed'
                && $evidence->status === 'reversed'
            ) {
                $this->reversal->assertReplay(
                    $acceptance,
                    $evidence,
                    $evidenceId,
                    $reversalOperationId,
                    $reason,
                    $reference,
                );

                return [
                    'status' => 'committed',
                    'acceptance_id' => (string) $acceptance->id,
                ];
            }

            throw new UnitHandoverConflict(
                'Unit Handover reversal recovery found partial or contradictory durable truth.',
            );
        });
    }

    private function assertFreshTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Unit Handover recovery requires a fresh transaction.',
            );
        }
    }
}
