<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Actions;

use App\Models\User;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use App\Modules\UnitHandover\Exceptions\UnitHandoverValidationFailed;
use App\Modules\UnitHandover\Support\UnitHandoverAuthorization;
use App\Modules\UnitHandover\Support\UnitHandoverEvidenceRecoveryResolver;
use App\Modules\UnitHandover\Support\UnitHandoverFacts;
use App\Modules\UnitHandover\Support\UnitHandoverTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordUnitHandoverEvidence
{
    public function __construct(
        private readonly UnitHandoverTransaction $tx,
        private readonly UnitHandoverAuthorization $auth,
        private readonly UnitHandoverEvidenceRecoveryResolver $recovery,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        array $input,
    ): string {
        $contractId = UnitHandoverFacts::ulid(
            $input,
            'contract_id',
        );

        $operationId = UnitHandoverFacts::operation(
            $input,
            'handover_evidence_operation_id',
        );

        $reference =
            UnitHandoverFacts::canonicalEvidenceReference($input);

        $readinessReference = UnitHandoverFacts::text(
            $input,
            'readiness_reference',
        );

        $readinessDate = UnitHandoverFacts::date(
            $input,
            'readiness_effective_date',
        );

        $acceptanceReference = UnitHandoverFacts::text(
            $input,
            'customer_acceptance_reference',
        );

        $acceptanceDate = UnitHandoverFacts::date(
            $input,
            'customer_acceptance_effective_date',
        );

        if ($acceptanceDate < $readinessDate) {
            throw new UnitHandoverValidationFailed(
                'customer_acceptance_effective_date must not precede readiness_effective_date.',
            );
        }

        $this->auth->authorizeEvidence($tenantId, $actor);

        try {
            return $this->tx->run(
                fn (): string => $this->record(
                    $tenantId,
                    $actor,
                    $contractId,
                    $operationId,
                    $reference,
                    $readinessReference,
                    $readinessDate,
                    $acceptanceReference,
                    $acceptanceDate,
                ),
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            $facts = $this->deriveRecoveryFacts(
                $tenantId,
                $contractId,
                $operationId,
                $reference,
                $readinessReference,
                $readinessDate,
                $acceptanceReference,
                $acceptanceDate,
            );

            return $this->recovery->resolve(
                $tenantId,
                $facts,
            );
        }
    }

    private function record(
        string $tenantId,
        User $actor,
        string $contractId,
        string $operationId,
        string $reference,
        string $readinessReference,
        string $readinessDate,
        string $acceptanceReference,
        string $acceptanceDate,
    ): string {
        $this->auth->authorizeEvidenceTransactional(
            $tenantId,
            $actor,
        );

        /*
         * Frozen source order after authorization:
         *
         * Contract -> Reservation -> Unit -> Evidence/replay root.
         */
        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw (new ModelNotFoundException)
                ->setModel('Contract');
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $contract->reservation_id)
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            throw (new ModelNotFoundException)
                ->setModel('Reservation');
        }

        $unit = DB::table('units')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservation->unit_id)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw (new ModelNotFoundException)
                ->setModel('Unit');
        }

        if (
            $contract->reservation_id !== $reservation->id
            || $reservation->unit_id !== $unit->id
            || $contract->currency !== 'SAR'
        ) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence source provenance is inconsistent.',
            );
        }

        $facts = [
            'contract_id' => (string) $contract->id,
            'reservation_id' => (string) $reservation->id,
            'unit_id' => (string) $unit->id,
            'customer_id' => (string) $reservation->customer_id,
            'handover_evidence_operation_id' => $operationId,
            'handover_evidence_reference' => $reference,
            'readiness_reference' => $readinessReference,
            'readiness_effective_date' => $readinessDate,
            'customer_acceptance_reference' => $acceptanceReference,
            'customer_acceptance_effective_date' => $acceptanceDate,
        ];

        $byOperation = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where(
                'handover_evidence_operation_id',
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

        $byReference = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where(
                'handover_evidence_reference',
                $reference,
            )
            ->lockForUpdate()
            ->first();

        if ($byReference !== null) {
            throw new UnitHandoverConflict(
                'Unit Handover external Evidence identity is already recorded by another operation.',
            );
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('unit_handover_evidence')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_id' => $facts['contract_id'],
            'reservation_id' => $facts['reservation_id'],
            'unit_id' => $facts['unit_id'],
            'customer_id' => $facts['customer_id'],
            'handover_evidence_operation_id' => $operationId,
            'handover_evidence_reference' => $reference,
            'readiness_reference' => $readinessReference,
            'readiness_effective_date' => $readinessDate,
            'acceptance_basis' => 'explicit_customer_acceptance',
            'customer_acceptance_reference' => $acceptanceReference,
            'customer_acceptance_effective_date' => $acceptanceDate,
            'effective_date' => $acceptanceDate,
            'recorded_by' => $actor->id,
            'recorded_at' => $now,
            'status' => 'effective',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function deriveRecoveryFacts(
        string $tenantId,
        string $contractId,
        string $operationId,
        string $reference,
        string $readinessReference,
        string $readinessDate,
        string $acceptanceReference,
        string $acceptanceDate,
    ): array {
        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->first();

        if ($contract === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence recovery source Contract disappeared.',
            );
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $contract->reservation_id)
            ->first();

        if ($reservation === null) {
            throw new UnitHandoverConflict(
                'Unit Handover Evidence recovery source Reservation disappeared.',
            );
        }

        return [
            'contract_id' => $contractId,
            'reservation_id' => (string) $reservation->id,
            'unit_id' => (string) $reservation->unit_id,
            'customer_id' => (string) $reservation->customer_id,
            'handover_evidence_operation_id' => $operationId,
            'handover_evidence_reference' => $reference,
            'readiness_reference' => $readinessReference,
            'readiness_effective_date' => $readinessDate,
            'customer_acceptance_reference' => $acceptanceReference,
            'customer_acceptance_effective_date' => $acceptanceDate,
        ];
    }
}
