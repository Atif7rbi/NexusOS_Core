<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Exceptions\UnitHandoverAccessDenied;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverAcceptanceActionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_acceptance_derives_exact_locked_performance_truth_and_replays(): void
    {
        [
            $tenantId,
            $actor,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $action = app(
            CreateUnitHandoverAcceptance::class,
        );

        $input = [
            'handover_evidence_id' => $evidenceId,
            'handover_acceptance_operation_id' => $operationId,
        ];

        $first = $action->execute(
            $tenantId,
            $actor,
            $input,
        );

        $second = $action->execute(
            $tenantId,
            $actor,
            $input,
        );

        self::assertSame($first, $second);

        $row = DB::table('unit_handover_acceptances')
            ->where('id', $first)
            ->first();

        self::assertNotNull($row);
        self::assertSame($tenantId, $row->tenant_id);
        self::assertSame($evidenceId, $row->handover_evidence_id);
        self::assertSame($contractId, $row->contract_id);
        self::assertSame($reservationId, $row->reservation_id);
        self::assertSame($unitId, $row->unit_id);
        self::assertSame($customerId, $row->customer_id);
        self::assertSame(
            $operationId,
            $row->handover_acceptance_operation_id,
        );
        self::assertSame(
            '2026-09-02',
            (string) $row->performance_date,
        );
        self::assertSame(
            '450000.00',
            (string) $row->performance_amount,
        );
        self::assertSame('SAR', $row->currency);
        self::assertSame('effective', $row->status);
        self::assertSame($actor->id, $row->created_by);

        self::assertSame(
            1,
            DB::table('unit_handover_acceptances')
                ->where(
                    'handover_acceptance_operation_id',
                    $operationId,
                )
                ->count(),
        );
    }

    public function test_caller_cannot_override_acceptance_derived_performance_facts(): void
    {
        [
            $tenantId,
            $actor,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        ] = $this->context();

        $acceptanceId = app(
            CreateUnitHandoverAcceptance::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),

                // Deliberately hostile caller-supplied derived facts.
                'contract_id' => (string) Str::ulid(),
                'reservation_id' => (string) Str::ulid(),
                'unit_id' => (string) Str::ulid(),
                'customer_id' => (string) Str::ulid(),
                'performance_date' => '2099-12-31',
                'performance_amount' => '1.00',
                'currency' => 'USD',
            ],
        );

        $row = DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->first();

        self::assertNotNull($row);
        self::assertSame($contractId, $row->contract_id);
        self::assertSame($reservationId, $row->reservation_id);
        self::assertSame($unitId, $row->unit_id);
        self::assertSame($customerId, $row->customer_id);
        self::assertSame(
            '2026-09-02',
            (string) $row->performance_date,
        );
        self::assertSame(
            '450000.00',
            (string) $row->performance_amount,
        );
        self::assertSame('SAR', $row->currency);
    }

    public function test_historical_evidence_consumption_rejects_second_operation(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            ,
            $evidenceId,
        ] = $this->context();

        $action = app(
            CreateUnitHandoverAcceptance::class,
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(
            UnitHandoverConflict::class,
        );

        $this->expectExceptionMessage(
            'Unit Handover Evidence already has historical Acceptance truth.',
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_operation_identity_reuse_for_different_evidence_conflicts(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            $firstEvidenceId,
        ] = $this->context();

        $secondEvidenceId = $this->recordEvidence(
            $tenantId,
            $actor,
            $contractId,
            'HANDOVER/SECOND-EVIDENCE-001',
            '2026-09-03',
        );

        $operationId = (string) Str::ulid();

        $action = app(
            CreateUnitHandoverAcceptance::class,
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $firstEvidenceId,
                'handover_acceptance_operation_id' => $operationId,
            ],
        );

        $this->expectException(
            UnitHandoverConflict::class,
        );

        $this->expectExceptionMessage(
            'Unit Handover Acceptance operation identity was reused with different canonical facts.',
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $secondEvidenceId,
                'handover_acceptance_operation_id' => $operationId,
            ],
        );
    }

    public function test_second_effective_acceptance_for_same_contract_is_rejected(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
            $firstEvidenceId,
        ] = $this->context();

        $secondEvidenceId = $this->recordEvidence(
            $tenantId,
            $actor,
            $contractId,
            'HANDOVER/SECOND-CONTRACT-001',
            '2026-09-03',
        );

        $action = app(
            CreateUnitHandoverAcceptance::class,
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $firstEvidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(
            UnitHandoverConflict::class,
        );

        $this->expectExceptionMessage(
            'Contract already has effective Unit Handover Acceptance.',
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $secondEvidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_inactive_tenant_cannot_create_acceptance(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            ,
            $evidenceId,
        ] = $this->context();

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'status' => 'paused',
                'updated_at' => now(),
            ]);

        $this->expectException(
            UnitHandoverAccessDenied::class,
        );

        app(CreateUnitHandoverAcceptance::class)
            ->execute(
                $tenantId,
                $actor,
                [
                    'handover_evidence_id' => $evidenceId,
                    'handover_acceptance_operation_id' => (string) Str::ulid(),
                ],
            );
    }

    public function test_accountant_cannot_create_unit_handover_acceptance(): void
    {
        [
            $tenantId,
            ,
            ,
            ,
            ,
            ,
            $evidenceId,
        ] = $this->context();

        $accountant = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_ACCOUNTANT,
        ]);

        TenantUser::factory()
            ->forTenant(
                Tenant::query()->findOrFail($tenantId),
            )
            ->forUser($accountant)
            ->active()
            ->create();

        $this->expectException(
            UnitHandoverAccessDenied::class,
        );

        app(CreateUnitHandoverAcceptance::class)
            ->execute(
                $tenantId,
                $accountant,
                [
                    'handover_evidence_id' => $evidenceId,
                    'handover_acceptance_operation_id' => (string) Str::ulid(),
                ],
            );
    }

    /**
     * @return array{
     *   string,
     *   User,
     *   string,
     *   string,
     *   string,
     *   string,
     *   string
     * }
     */
    private function context(): array
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

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actor,
            (string) $contract->id,
            'HANDOVER/ACCEPTANCE-001',
            '2026-09-02',
        );

        return [
            $tenantId,
            $actor,
            (string) $customer->id,
            (string) $reservation->id,
            (string) $unit->id,
            (string) $contract->id,
            $evidenceId,
        ];
    }

    private function recordEvidence(
        string $tenantId,
        User $actor,
        string $contractId,
        string $reference,
        string $acceptanceDate,
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
                'readiness_reference' => 'READINESS/'.$reference,
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/'.$reference,
                'customer_acceptance_effective_date' => $acceptanceDate,
            ],
        );
    }
}
