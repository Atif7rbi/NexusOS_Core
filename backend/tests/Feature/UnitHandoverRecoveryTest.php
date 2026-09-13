<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
use App\Modules\UnitHandover\Support\UnitHandoverRecoveryResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverRecoveryTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_absent_evidence_operation_is_retryable(): void
    {
        [
            $tenantId,
            ,
            ,
            $reservationId,
            $unitId,
            $contractId,
            $customerId,
        ] = $this->sourceContext();

        $facts = $this->evidenceFacts(
            $contractId,
            $reservationId,
            $unitId,
            $customerId,
            (string) Str::ulid(),
            'HANDOVER/RECOVERY-ABSENT-001',
        );

        $result = app(UnitHandoverRecoveryResolver::class)
            ->recoverEvidence(
                $tenantId,
                $facts,
            );

        self::assertSame('retryable', $result['status']);
        self::assertNull($result['evidence_id']);
    }

    public function test_committed_evidence_operation_resolves_exact_truth(): void
    {
        [
            $tenantId,
            $actor,
            ,
            $reservationId,
            $unitId,
            $contractId,
            $customerId,
        ] = $this->sourceContext();

        $operationId = (string) Str::ulid();

        $facts = $this->evidenceFacts(
            $contractId,
            $reservationId,
            $unitId,
            $customerId,
            $operationId,
            'HANDOVER/RECOVERY-COMMITTED-001',
        );

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => $operationId,
                'handover_evidence_reference' => 'HANDOVER/RECOVERY-COMMITTED-001',
                'readiness_reference' => 'READINESS/RECOVERY',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/RECOVERY',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        $result = app(UnitHandoverRecoveryResolver::class)
            ->recoverEvidence(
                $tenantId,
                $facts,
            );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $evidenceId,
            $result['evidence_id'],
        );
    }

    public function test_committed_acceptance_operation_resolves_exact_truth(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            ,
        ] = $this->sourceContext();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actor,
            $contractId,
            'HANDOVER/RECOVERY-ACCEPTANCE-001',
        );

        $operationId = (string) Str::ulid();

        $acceptanceId = app(
            CreateUnitHandoverAcceptance::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => $operationId,
            ],
        );

        $row = DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->first();

        self::assertNotNull($row);

        $facts = [
            'handover_evidence_id' => $evidenceId,
            'contract_id' => (string) $row->contract_id,
            'reservation_id' => (string) $row->reservation_id,
            'unit_id' => (string) $row->unit_id,
            'customer_id' => (string) $row->customer_id,
            'handover_acceptance_operation_id' => $operationId,
            'performance_date' => (string) $row->performance_date,
            'performance_amount' => (string) $row->performance_amount,
            'currency' => (string) $row->currency,
        ];

        $result = app(UnitHandoverRecoveryResolver::class)
            ->recoverAcceptance(
                $tenantId,
                $facts,
            );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $acceptanceId,
            $result['acceptance_id'],
        );
    }

    public function test_unattempted_reversal_is_retryable(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            ,
        ] = $this->sourceContext();

        [$evidenceId, $acceptanceId] =
            $this->acceptedSource(
                $tenantId,
                $actor,
                $contractId,
                'HANDOVER/RECOVERY-REV-RETRY-001',
            );

        $result = app(UnitHandoverRecoveryResolver::class)
            ->recoverReversal(
                $tenantId,
                $acceptanceId,
                $evidenceId,
                (string) Str::ulid(),
                'Recovery retry fixture',
                'HANDOVER-RECOVERY-RETRY-001',
            );

        self::assertSame('retryable', $result['status']);
        self::assertNull($result['acceptance_id']);
    }

    public function test_committed_reversal_resolves_complete_pair(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            ,
        ] = $this->sourceContext();

        [$evidenceId, $acceptanceId] =
            $this->acceptedSource(
                $tenantId,
                $actor,
                $contractId,
                'HANDOVER/RECOVERY-REV-COMMITTED-001',
            );

        $operationId = (string) Str::ulid();
        $reason = 'Recovery committed fixture';
        $reference = 'HANDOVER-RECOVERY-COMMITTED-001';

        app(ReverseUnitHandoverPerformanceSource::class)
            ->execute(
                $tenantId,
                $acceptanceId,
                $actor,
                [
                    'reversal_operation_id' => $operationId,
                    'reversal_reason' => $reason,
                    'reversal_reference' => $reference,
                ],
            );

        $result = app(UnitHandoverRecoveryResolver::class)
            ->recoverReversal(
                $tenantId,
                $acceptanceId,
                $evidenceId,
                $operationId,
                $reason,
                $reference,
            );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $acceptanceId,
            $result['acceptance_id'],
        );
    }

    public function test_partial_reversal_truth_is_rejected_before_becoming_durable(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            ,
        ] = $this->sourceContext();

        [$evidenceId, $acceptanceId] =
            $this->acceptedSource(
                $tenantId,
                $actor,
                $contractId,
                'HANDOVER/RECOVERY-PARTIAL-001',
            );

        $operationId = (string) Str::ulid();
        $now = now();

        DB::beginTransaction();

        try {
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('id', $acceptanceId)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $operationId,
                    'reversal_reason' => 'Partial fixture',
                    'reversal_reference' => 'HANDOVER-RECOVERY-PARTIAL-001',
                    'reversed_by' => $actor->id,
                    'reversed_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->expectException(
                QueryException::class,
            );

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            DB::rollBack();
        }

        self::assertSame(
            'effective',
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('id', $acceptanceId)
                ->value('status'),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->value('status'),
        );
    }

    /**
     * @return array{
     *   string,
     *   User,
     *   int,
     *   string,
     *   string,
     *   string,
     *   string
     * }
     */
    private function sourceContext(): array
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        $tenantId = $this->integrityTenantId($actor);

        $project = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
        );

        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actor->id,
            'sold',
        );

        $customer = $this->createIntegrityCustomer(
            $tenantId,
            $actor->id,
        );

        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actor->id,
            'converted',
        );

        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            [
                'total_amount' => '450000.00',
                'currency' => 'SAR',
            ],
        );

        return [
            $tenantId,
            $actor,
            (int) $actor->id,
            (string) $reservation->id,
            (string) $unit->id,
            (string) $contract->id,
            (string) $customer->id,
        ];
    }

    private function recordEvidence(
        string $tenantId,
        User $actor,
        string $contractId,
        string $reference,
    ): string {
        return app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => $reference,
                'readiness_reference' => 'READINESS/RECOVERY',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/RECOVERY',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );
    }

    /**
     * @return array{string,string}
     */
    private function acceptedSource(
        string $tenantId,
        User $actor,
        string $contractId,
        string $reference,
    ): array {
        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actor,
            $contractId,
            $reference,
        );

        $acceptanceId = app(
            CreateUnitHandoverAcceptance::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );

        return [$evidenceId, $acceptanceId];
    }

    private function evidenceFacts(
        string $contractId,
        string $reservationId,
        string $unitId,
        string $customerId,
        string $operationId,
        string $reference,
    ): array {
        return [
            'contract_id' => $contractId,
            'reservation_id' => $reservationId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'handover_evidence_operation_id' => $operationId,
            'handover_evidence_reference' => $reference,
            'readiness_reference' => 'READINESS/RECOVERY',
            'readiness_effective_date' => '2026-09-01',
            'customer_acceptance_reference' => 'ACCEPTANCE/RECOVERY',
            'customer_acceptance_effective_date' => '2026-09-02',
        ];
    }
}
