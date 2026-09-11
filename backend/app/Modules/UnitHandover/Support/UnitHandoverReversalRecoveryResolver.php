<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Support\Facades\DB;

final class UnitHandoverReversalRecoveryResolver
{
    /**
     * Resolve a uniqueness race only after the failed construction
     * transaction has completely rolled back.
     */
    public function resolve(
        string $tenantId,
        string $acceptanceId,
        string $evidenceId,
        string $reversalOperationId,
        string $reason,
        string $reference,
    ): string {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Unit Handover reversal recovery requires a fresh transaction.',
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $acceptanceId,
            $evidenceId,
            $reversalOperationId,
            $reason,
            $reference,
        ): string {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $acceptance = DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('id', $acceptanceId)
                ->first();

            $evidence = DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->first();

            if ($acceptance === null || $evidence === null) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal recovery found missing source truth.',
                );
            }

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
                $acceptance->status === 'reversed'
                && $evidence->status === 'reversed'
            ) {
                $this->assertReplay(
                    $acceptance,
                    $evidence,
                    $evidenceId,
                    $reversalOperationId,
                    $reason,
                    $reference,
                );

                return (string) $acceptance->id;
            }

            if (
                $acceptanceUsingOperation !== null
                && $acceptanceUsingOperation->id !== $acceptanceId
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal operation identity is already used by another Acceptance.',
                );
            }

            if (
                $evidenceUsingOperation !== null
                && $evidenceUsingOperation->id !== $evidenceId
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover reversal operation identity is already used by another Evidence record.',
                );
            }

            throw new UnitHandoverConflict(
                'Unit Handover reversal outcome is unavailable after uniqueness race.',
            );
        });
    }

    public function assertReplay(
        object $acceptance,
        object $evidence,
        string $expectedEvidenceId,
        string $reversalOperationId,
        string $reason,
        string $reference,
    ): void {
        if (
            $acceptance->status !== 'reversed'
            || $evidence->status !== 'reversed'
            || $acceptance->handover_evidence_id
                !== $expectedEvidenceId
            || $evidence->id !== $expectedEvidenceId
            || $acceptance->reversal_operation_id
                !== $reversalOperationId
            || $evidence->reversal_operation_id
                !== $reversalOperationId
            || $acceptance->reversal_reason !== $reason
            || $evidence->reversal_reason !== $reason
            || $acceptance->reversal_reference !== $reference
            || $evidence->reversal_reference !== $reference
            || $acceptance->reversed_by !== $evidence->reversed_by
            || (string) $acceptance->reversed_at
                !== (string) $evidence->reversed_at
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover reversal operation was replayed with different or inconsistent canonical truth.',
            );
        }
    }
}
