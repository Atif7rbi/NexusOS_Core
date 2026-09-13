<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Support\Facades\DB;

final class UnitHandoverEvidenceRecoveryResolver
{
    public function resolve(
        string $tenantId,
        array $facts,
    ): string {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException(
                'Unit Handover Evidence recovery requires a fresh transaction.',
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $facts,
        ): string {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $byOperation = DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_operation_id',
                    $facts['handover_evidence_operation_id'],
                )
                ->lockForUpdate()
                ->first();

            $byReference = DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_reference',
                    $facts['handover_evidence_reference'],
                )
                ->lockForUpdate()
                ->first();

            if ($byOperation === null && $byReference === null) {
                throw new UnitHandoverConflict(
                    'Unit Handover Evidence outcome is unavailable after uniqueness race.',
                );
            }

            if (
                $byOperation === null
                || $byReference === null
                || $byOperation->id !== $byReference->id
            ) {
                throw new UnitHandoverConflict(
                    'Unit Handover Evidence operation or external identity conflicts with historical truth.',
                );
            }

            $this->assertMatches($byOperation, $facts);

            return (string) $byOperation->id;
        });
    }

    public function assertMatches(
        object $row,
        array $facts,
    ): void {
        if (
            $row->contract_id !== $facts['contract_id']
            || $row->reservation_id !== $facts['reservation_id']
            || $row->unit_id !== $facts['unit_id']
            || $row->customer_id !== $facts['customer_id']
            || $row->handover_evidence_operation_id
                !== $facts['handover_evidence_operation_id']
            || $row->handover_evidence_reference
                !== $facts['handover_evidence_reference']
            || $row->readiness_reference
                !== $facts['readiness_reference']
            || (string) $row->readiness_effective_date
                !== $facts['readiness_effective_date']
            || $row->acceptance_basis
                !== 'explicit_customer_acceptance'
            || $row->customer_acceptance_reference
                !== $facts['customer_acceptance_reference']
            || (string) $row->customer_acceptance_effective_date
                !== $facts['customer_acceptance_effective_date']
            || (string) $row->effective_date
                !== $facts['customer_acceptance_effective_date']
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence operation identity was reused with different canonical facts.',
            );
        }
    }
}
