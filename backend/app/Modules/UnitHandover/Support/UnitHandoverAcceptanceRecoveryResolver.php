<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Support\Facades\DB;

final class UnitHandoverAcceptanceRecoveryResolver
{
    public function resolve(
        string $tenantId,
        array $facts,
    ): string {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Unit Handover Acceptance recovery requires a fresh transaction.',
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $facts,
        ): string {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            /*
             * Recovery performs immutable historical reads only.
             *
             * It intentionally does not introduce a new lock order after the
             * failed construction transaction has rolled back.
             */
            $byOperation = DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_acceptance_operation_id',
                    $facts['handover_acceptance_operation_id'],
                )
                ->first();

            $byEvidence = DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_id',
                    $facts['handover_evidence_id'],
                )
                ->first();

            if ($byOperation !== null) {
                $this->assertMatches(
                    $byOperation,
                    $facts,
                );

                if (
                    $byEvidence !== null
                    && $byEvidence->id !== $byOperation->id
                ) {
                    throw new UnitHandoverConflict(
                        'Unit Handover Acceptance operation conflicts with historical Evidence consumption.',
                    );
                }

                return (string) $byOperation->id;
            }

            if ($byEvidence !== null) {
                throw new UnitHandoverConflict(
                    'Unit Handover Evidence already has historical Acceptance truth.',
                );
            }

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
                    'Contract already has effective Unit Handover Acceptance.',
                );
            }

            throw new UnitHandoverConflict(
                'Unit Handover Acceptance outcome is unavailable after uniqueness race.',
            );
        });
    }

    public function assertMatches(
        object $row,
        array $facts,
    ): void {
        if (
            $row->handover_evidence_id
                !== $facts['handover_evidence_id']
            || $row->contract_id
                !== $facts['contract_id']
            || $row->reservation_id
                !== $facts['reservation_id']
            || $row->unit_id
                !== $facts['unit_id']
            || $row->customer_id
                !== $facts['customer_id']
            || $row->handover_acceptance_operation_id
                !== $facts['handover_acceptance_operation_id']
            || (string) $row->performance_date
                !== $facts['performance_date']
            || (string) $row->performance_amount
                !== $facts['performance_amount']
            || $row->currency
                !== $facts['currency']
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance operation identity was reused with different canonical facts.',
            );
        }
    }
}
