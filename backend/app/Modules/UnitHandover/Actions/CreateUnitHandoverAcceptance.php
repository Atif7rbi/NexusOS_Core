<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Actions;

use App\Models\User;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use App\Modules\UnitHandover\Support\UnitHandoverAcceptanceRecoveryResolver;
use App\Modules\UnitHandover\Support\UnitHandoverAuthorization;
use App\Modules\UnitHandover\Support\UnitHandoverFacts;
use App\Modules\UnitHandover\Support\UnitHandoverTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateUnitHandoverAcceptance
{
    public function __construct(
        private readonly UnitHandoverTransaction $tx,
        private readonly UnitHandoverAuthorization $auth,
        private readonly UnitHandoverAcceptanceRecoveryResolver $recovery,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        array $input,
    ): string {
        $evidenceId = UnitHandoverFacts::ulid(
            $input,
            'handover_evidence_id',
        );

        $operationId = UnitHandoverFacts::operation(
            $input,
            'handover_acceptance_operation_id',
        );

        /*
         * Fast preflight only.
         *
         * Authoritative authorization is repeated inside the transaction
         * before source locks are acquired.
         */
        $this->auth->authorizeAcceptance(
            $tenantId,
            $actor,
        );

        $canonicalFacts = null;

        try {
            return $this->tx->run(
                function () use (
                    $tenantId,
                    $actor,
                    $evidenceId,
                    $operationId,
                    &$canonicalFacts,
                ): string {
                    return $this->create(
                        $tenantId,
                        $actor,
                        $evidenceId,
                        $operationId,
                        $canonicalFacts,
                    );
                },
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            if (! is_array($canonicalFacts)) {
                throw new UnitHandoverConflict(
                    'Unit Handover Acceptance uniqueness conflict occurred before canonical source facts were established.',
                    previous: $exception,
                );
            }

            return $this->recovery->resolve(
                $tenantId,
                $canonicalFacts,
            );
        }
    }

    private function create(
        string $tenantId,
        User $actor,
        string $evidenceId,
        string $operationId,
        ?array &$canonicalFacts,
    ): string {
        $this->auth->authorizeAcceptanceTransactional(
            $tenantId,
            $actor,
        );

        /*
         * Identity discovery only.
         *
         * This read does not acquire a source lock. Every discovered fact is
         * revalidated after the frozen parent-to-child locks are acquired.
         */
        $evidenceIdentity =
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->first();

        if ($evidenceIdentity === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence does not exist for this Tenant.',
            );
        }

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where(
                'id',
                $evidenceIdentity->contract_id,
            )
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance source Contract is missing.',
            );
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where(
                'id',
                $contract->reservation_id,
            )
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance source Reservation is missing.',
            );
        }

        $unit = DB::table('units')
            ->where('tenant_id', $tenantId)
            ->where(
                'id',
                $reservation->unit_id,
            )
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance source Unit is missing.',
            );
        }

        $evidence = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->lockForUpdate()
            ->first();

        if ($evidence === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence disappeared during Acceptance construction.',
            );
        }

        if (
            $evidence->contract_id !== $contract->id
            || $evidence->reservation_id !== $reservation->id
            || $evidence->unit_id !== $unit->id
            || $evidence->customer_id !== $reservation->customer_id
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance source provenance is inconsistent.',
            );
        }

        if (
            ! in_array(
                $contract->status,
                ['active', 'completed'],
                true,
            )
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance requires an active or completed Contract.',
            );
        }

        if ($contract->currency !== 'SAR') {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance v1 requires a SAR Contract.',
            );
        }

        if ($reservation->status !== 'converted') {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance requires a converted Reservation.',
            );
        }

        if (
            $unit->status !== 'sold'
            || $unit->archived_at !== null
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance requires a non-archived sold Unit.',
            );
        }

        if ($evidence->status !== 'effective') {
            throw new UnitHandoverConflict(
                'Unit Handover Acceptance requires effective Evidence.',
            );
        }

        $facts = [
            'handover_evidence_id' => (string) $evidence->id,
            'contract_id' => (string) $contract->id,
            'reservation_id' => (string) $reservation->id,
            'unit_id' => (string) $unit->id,
            'customer_id' => (string) $reservation->customer_id,
            'handover_acceptance_operation_id' => $operationId,
            'performance_date' => (string) $evidence->effective_date,
            'performance_amount' => (string) $contract->total_amount,
            'currency' => (string) $contract->currency,
        ];

        $canonicalFacts = $facts;

        /*
         * Replay roots are acquired only after the complete source chain.
         */
        $byOperation =
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_acceptance_operation_id',
                    $operationId,
                )
                ->lockForUpdate()
                ->first();

        if ($byOperation !== null) {
            $this->recovery->assertMatches(
                $byOperation,
                $facts,
            );

            return (string) $byOperation->id;
        }

        $byEvidence =
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_id',
                    $evidenceId,
                )
                ->lockForUpdate()
                ->first();

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
                    $contract->id,
                )
                ->where('status', 'effective')
                ->lockForUpdate()
                ->first();

        if ($effectiveForContract !== null) {
            throw new UnitHandoverConflict(
                'Contract already has effective Unit Handover Acceptance.',
            );
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('unit_handover_acceptances')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'handover_evidence_id' => $evidence->id,
            'contract_id' => $contract->id,
            'reservation_id' => $reservation->id,
            'unit_id' => $unit->id,
            'customer_id' => $reservation->customer_id,
            'handover_acceptance_operation_id' => $operationId,
            'performance_date' => $evidence->effective_date,
            'performance_amount' => $contract->total_amount,
            'currency' => $contract->currency,
            'status' => 'effective',
            'created_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
