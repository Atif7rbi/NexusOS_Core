<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
use App\Modules\UnitHandover\Exceptions\UnitHandoverAccessDenied;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverReversalActionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_reversal_atomically_reverses_acceptance_then_evidence_with_same_operation(): void
    {
        [
            $tenantId,
            $actor,
            $evidenceId,
            $acceptanceId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $result = app(
            ReverseUnitHandoverPerformanceSource::class,
        )->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            [
                'reversal_operation_id' => $operationId,
                'reversal_reason' => 'Customer acceptance rescinded',
                'reversal_reference' => 'HANDOVER-REV-001',
            ],
        );

        self::assertSame($acceptanceId, $result);

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->first();

        $evidence = DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->first();

        self::assertNotNull($acceptance);
        self::assertNotNull($evidence);

        self::assertSame('reversed', $acceptance->status);
        self::assertSame('reversed', $evidence->status);

        self::assertSame(
            $operationId,
            $acceptance->reversal_operation_id,
        );

        self::assertSame(
            $operationId,
            $evidence->reversal_operation_id,
        );

        self::assertSame(
            'Customer acceptance rescinded',
            $acceptance->reversal_reason,
        );

        self::assertSame(
            $acceptance->reversal_reason,
            $evidence->reversal_reason,
        );

        self::assertSame(
            'HANDOVER-REV-001',
            $acceptance->reversal_reference,
        );

        self::assertSame(
            $acceptance->reversal_reference,
            $evidence->reversal_reference,
        );

        self::assertSame(
            $acceptance->reversed_by,
            $evidence->reversed_by,
        );

        self::assertSame(
            (string) $acceptance->reversed_at,
            (string) $evidence->reversed_at,
        );
    }

    public function test_same_reversal_operation_and_same_facts_replays(): void
    {
        [
            $tenantId,
            $actor,
            ,
            $acceptanceId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $input = [
            'reversal_operation_id' => $operationId,
            'reversal_reason' => 'Correction replay fixture',
            'reversal_reference' => 'HANDOVER-REV-REPLAY-001',
        ];

        $action = app(
            ReverseUnitHandoverPerformanceSource::class,
        );

        $first = $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            $input,
        );

        $second = $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            $input,
        );

        self::assertSame($first, $second);
        self::assertSame($acceptanceId, $second);
    }

    public function test_same_reversal_operation_with_different_facts_conflicts(): void
    {
        [
            $tenantId,
            $actor,
            ,
            $acceptanceId,
        ] = $this->context();

        $operationId = (string) Str::ulid();

        $action = app(
            ReverseUnitHandoverPerformanceSource::class,
        );

        $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            [
                'reversal_operation_id' => $operationId,
                'reversal_reason' => 'Original reason',
                'reversal_reference' => 'HANDOVER-REV-CONFLICT-001',
            ],
        );

        $this->expectException(
            UnitHandoverConflict::class,
        );

        $this->expectExceptionMessage(
            'Unit Handover reversal operation was replayed with different or inconsistent canonical truth.',
        );

        $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            [
                'reversal_operation_id' => $operationId,
                'reversal_reason' => 'Different reason',
                'reversal_reference' => 'HANDOVER-REV-CONFLICT-001',
            ],
        );
    }

    public function test_same_reversal_operation_cannot_be_reused_for_different_source_pair(): void
    {
        [
            $tenantId,
            $actor,
            ,
            $acceptanceA,
        ] = $this->context();

        [
            $evidenceB,
            $acceptanceB,
        ] = $this->secondSourcePair(
            $tenantId,
            $actor,
        );

        $operationId = (string) Str::ulid();

        $action = app(
            ReverseUnitHandoverPerformanceSource::class,
        );

        $action->execute(
            $tenantId,
            $acceptanceA,
            $actor,
            [
                'reversal_operation_id' => $operationId,
                'reversal_reason' => 'Source pair A correction',
                'reversal_reference' => 'HANDOVER-REV-PAIR-A',
            ],
        );

        try {
            $action->execute(
                $tenantId,
                $acceptanceB,
                $actor,
                [
                    'reversal_operation_id' => $operationId,
                    'reversal_reason' => 'Source pair B correction',
                    'reversal_reference' => 'HANDOVER-REV-PAIR-B',
                ],
            );

            self::fail(
                'Expected reversal operation reuse across different source pairs to conflict.',
            );
        } catch (UnitHandoverConflict) {
            // Expected deterministic conflict.
        }

        $acceptanceBRow = DB::table(
            'unit_handover_acceptances',
        )
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceB)
            ->first();

        $evidenceBRow = DB::table(
            'unit_handover_evidence',
        )
            ->where('tenant_id', $tenantId)
            ->where('id', $evidenceB)
            ->first();

        self::assertNotNull($acceptanceBRow);
        self::assertNotNull($evidenceBRow);

        self::assertSame(
            'effective',
            $acceptanceBRow->status,
        );

        self::assertSame(
            'effective',
            $evidenceBRow->status,
        );

        self::assertNull(
            $acceptanceBRow->reversal_operation_id,
        );

        self::assertNull(
            $evidenceBRow->reversal_operation_id,
        );

        self::assertNull(
            $acceptanceBRow->reversal_reason,
        );

        self::assertNull(
            $evidenceBRow->reversal_reason,
        );

        self::assertNull(
            $acceptanceBRow->reversal_reference,
        );

        self::assertNull(
            $evidenceBRow->reversal_reference,
        );

        self::assertNull(
            $acceptanceBRow->reversed_by,
        );

        self::assertNull(
            $evidenceBRow->reversed_by,
        );

        self::assertNull(
            $acceptanceBRow->reversed_at,
        );

        self::assertNull(
            $evidenceBRow->reversed_at,
        );
    }

    public function test_second_unrelated_reversal_operation_conflicts(): void
    {
        [
            $tenantId,
            $actor,
            ,
            $acceptanceId,
        ] = $this->context();

        $action = app(
            ReverseUnitHandoverPerformanceSource::class,
        );

        $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            [
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_reason' => 'First correction',
                'reversal_reference' => 'HANDOVER-REV-FIRST-001',
            ],
        );

        $this->expectException(
            UnitHandoverConflict::class,
        );

        $action->execute(
            $tenantId,
            $acceptanceId,
            $actor,
            [
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_reason' => 'Second correction',
                'reversal_reference' => 'HANDOVER-REV-SECOND-001',
            ],
        );
    }

    public function test_paused_tenant_can_reverse_historical_performance_source(): void
    {
        [
            $tenantId,
            $actor,
            $evidenceId,
            $acceptanceId,
        ] = $this->context();

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'status' => 'paused',
                'updated_at' => now(),
            ]);

        $operationId = (string) Str::ulid();

        app(ReverseUnitHandoverPerformanceSource::class)
            ->execute(
                $tenantId,
                $acceptanceId,
                $actor,
                [
                    'reversal_operation_id' => $operationId,
                    'reversal_reason' => 'Historical correction',
                    'reversal_reference' => 'HANDOVER-REV-PAUSED-001',
                ],
            );

        self::assertSame(
            'reversed',
            DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->value('status'),
        );

        self::assertSame(
            'reversed',
            DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->value('status'),
        );
    }

    public function test_accountant_cannot_reverse_unit_handover_performance_source(): void
    {
        [
            $tenantId,
            ,
            ,
            $acceptanceId,
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

        app(ReverseUnitHandoverPerformanceSource::class)
            ->execute(
                $tenantId,
                $acceptanceId,
                $accountant,
                [
                    'reversal_operation_id' => (string) Str::ulid(),
                    'reversal_reason' => 'Unauthorized correction',
                    'reversal_reference' => 'HANDOVER-REV-AUTH-001',
                ],
            );
    }

    /**
     * @return array{string,string}
     */
    private function secondSourcePair(
        string $tenantId,
        User $actor,
    ): array {
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
                'total_amount' => '325000.00',
                'currency' => 'SAR',
            ],
        );

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => (string) $contract->id,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/REVERSAL-PAIR-B-'.Str::ulid(),
                'readiness_reference' => 'READINESS/REVERSAL-PAIR-B',
                'readiness_effective_date' => '2026-09-03',
                'customer_acceptance_reference' => 'ACCEPTANCE/REVERSAL-PAIR-B',
                'customer_acceptance_effective_date' => '2026-09-04',
            ],
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

        return [
            $evidenceId,
            $acceptanceId,
        ];
    }

    /**
     * @return array{string,User,string,string}
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

        $evidenceId = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => (string) $contract->id,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'HANDOVER/REVERSAL-'.Str::ulid(),
                'readiness_reference' => 'READINESS/REVERSAL',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/REVERSAL',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
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

        return [
            $tenantId,
            $actor,
            $evidenceId,
            $acceptanceId,
        ];
    }
}
