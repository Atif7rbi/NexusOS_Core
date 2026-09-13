<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Exceptions\UnitHandoverAccessDenied;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use App\Modules\UnitHandover\Exceptions\UnitHandoverValidationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverEvidenceActionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_authorized_administrator_records_canonical_evidence_from_locked_source_chain(): void
    {
        [
            $tenantId,
            $actor,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => $operationId,
                'handover_evidence_reference' => '  handover/action-001  ',
                'readiness_reference' => '  READINESS/ACTION-001  ',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => '  ACCEPTANCE/ACTION-001  ',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        $row = DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->first();

        self::assertNotNull($row);
        self::assertSame($tenantId, $row->tenant_id);
        self::assertSame($contractId, $row->contract_id);
        self::assertSame($reservationId, $row->reservation_id);
        self::assertSame($unitId, $row->unit_id);
        self::assertSame($customerId, $row->customer_id);

        self::assertSame(
            $operationId,
            $row->handover_evidence_operation_id,
        );

        self::assertSame(
            'HANDOVER/ACTION-001',
            $row->handover_evidence_reference,
        );

        self::assertSame(
            'READINESS/ACTION-001',
            $row->readiness_reference,
        );

        self::assertSame(
            '2026-09-01',
            (string) $row->readiness_effective_date,
        );

        self::assertSame(
            'explicit_customer_acceptance',
            $row->acceptance_basis,
        );

        self::assertSame(
            'ACCEPTANCE/ACTION-001',
            $row->customer_acceptance_reference,
        );

        self::assertSame(
            '2026-09-02',
            (string) $row->customer_acceptance_effective_date,
        );

        self::assertSame(
            '2026-09-02',
            (string) $row->effective_date,
        );

        self::assertSame('effective', $row->status);
        self::assertSame($actor->id, $row->recorded_by);
        self::assertNotNull($row->recorded_at);
    }

    public function test_same_operation_and_same_canonical_facts_replays_exact_evidence(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $input = [
            'contract_id' => $contractId,
            'handover_evidence_operation_id' => $operationId,
            'handover_evidence_reference' => 'handover/replay-001',
            'readiness_reference' => 'READINESS/REPLAY-001',
            'readiness_effective_date' => '2026-09-03',
            'customer_acceptance_reference' => 'ACCEPTANCE/REPLAY-001',
            'customer_acceptance_effective_date' => '2026-09-04',
        ];

        $action = app(RecordUnitHandoverEvidence::class);

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

        self::assertSame(
            1,
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_operation_id',
                    $operationId,
                )
                ->count(),
        );
    }

    public function test_same_operation_with_different_canonical_facts_conflicts(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $action = app(RecordUnitHandoverEvidence::class);

        $action->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => $operationId,
                'handover_evidence_reference' => 'HANDOVER/OP-CONFLICT-001',
                'readiness_reference' => 'READINESS/OP-CONFLICT-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/ORIGINAL-001',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        $this->expectException(UnitHandoverConflict::class);
        $this->expectExceptionMessage(
            'Unit Handover Evidence operation identity was reused with different canonical facts.',
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => $operationId,
                'handover_evidence_reference' => 'HANDOVER/OP-CONFLICT-001',
                'readiness_reference' => 'READINESS/OP-CONFLICT-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/DIFFERENT-001',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );
    }

    public function test_same_external_reference_with_different_operation_conflicts(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        $action = app(RecordUnitHandoverEvidence::class);

        $base = [
            'contract_id' => $contractId,
            'handover_evidence_reference' => 'HANDOVER/IDENTITY-001',
            'readiness_reference' => 'READINESS/IDENTITY-001',
            'readiness_effective_date' => '2026-09-01',
            'customer_acceptance_reference' => 'ACCEPTANCE/IDENTITY-001',
            'customer_acceptance_effective_date' => '2026-09-02',
        ];

        $action->execute(
            $tenantId,
            $actor,
            $base + [
                'handover_evidence_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(UnitHandoverConflict::class);
        $this->expectExceptionMessage(
            'Unit Handover external Evidence identity is already recorded by another operation.',
        );

        $action->execute(
            $tenantId,
            $actor,
            $base + [
                'handover_evidence_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_customer_acceptance_date_cannot_precede_readiness_date(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        $this->expectException(
            UnitHandoverValidationFailed::class,
        );

        $this->expectExceptionMessage(
            'customer_acceptance_effective_date must not precede readiness_effective_date.',
        );

        app(RecordUnitHandoverEvidence::class)->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/DATE-001',
                'readiness_reference' => 'READINESS/DATE-001',
                'readiness_effective_date' => '2026-09-10',
                'customer_acceptance_reference' => 'ACCEPTANCE/DATE-001',
                'customer_acceptance_effective_date' => '2026-09-09',
            ],
        );
    }

    public function test_evidence_reference_is_canonicalized_before_persistence(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => '  abc_def/2026-001  ',
                'readiness_reference' => 'READINESS/CANONICAL-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/CANONICAL-001',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        self::assertSame(
            'ABC_DEF/2026-001',
            DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->value('handover_evidence_reference'),
        );
    }

    public function test_caller_cannot_override_evidence_derived_source_facts(): void
    {
        [
            $tenantId,
            $actor,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        ] = $this->context();

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/DERIVED-001',
                'readiness_reference' => 'READINESS/DERIVED-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/DERIVED-001',
                'customer_acceptance_effective_date' => '2026-09-02',

                // Deliberately hostile caller-supplied derived facts.
                'reservation_id' => (string) Str::ulid(),
                'unit_id' => (string) Str::ulid(),
                'customer_id' => (string) Str::ulid(),
                'acceptance_basis' => 'caller_override',
                'effective_date' => '2099-12-31',
            ],
        );

        $row = DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->first();

        self::assertNotNull($row);
        self::assertSame($reservationId, $row->reservation_id);
        self::assertSame($unitId, $row->unit_id);
        self::assertSame($customerId, $row->customer_id);
        self::assertSame(
            'explicit_customer_acceptance',
            $row->acceptance_basis,
        );
        self::assertSame(
            '2026-09-02',
            (string) $row->effective_date,
        );
    }

    public function test_paused_tenant_can_record_evidence_but_not_create_acceptance(): void
    {
        [
            $tenantId,
            $actor,
            ,
            ,
            ,
            $contractId,
        ] = $this->context();

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'status' => 'paused',
                'updated_at' => now(),
            ]);

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/PAUSED-001',
                'readiness_reference' => 'READINESS/PAUSED-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/PAUSED-001',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        self::assertNotNull(
            DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->first(),
        );
    }

    public function test_accountant_cannot_record_unit_handover_evidence(): void
    {
        [
            $tenantId,
            ,
            ,
            ,
            ,
            $contractId,
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

        app(RecordUnitHandoverEvidence::class)->execute(
            $tenantId,
            $accountant,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/AUTH-001',
                'readiness_reference' => 'READINESS/AUTH-001',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/AUTH-001',
                'customer_acceptance_effective_date' => '2026-09-02',
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

        return [
            $tenantId,
            $actor,
            (string) $customer->id,
            (string) $reservation->id,
            (string) $unit->id,
            (string) $contract->id,
        ];
    }
}
