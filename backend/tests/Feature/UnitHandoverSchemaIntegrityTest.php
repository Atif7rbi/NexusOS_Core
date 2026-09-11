<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverSchemaIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_physical_schema_has_required_uniqueness_and_deferred_guards(): void
    {
        foreach ([
            'unit_handover_evidence_reference_unique',
            'unit_handover_evidence_operation_unique',
            'unit_handover_evidence_reversal_operation_unique',
            'unit_handover_acceptances_operation_unique',
            'unit_handover_acceptances_evidence_unique',
            'unit_handover_acceptances_reversal_operation_unique',
            'unit_handover_acceptances_effective_contract_unique',
        ] as $indexOrConstraint) {
            $exists = DB::selectOne(
                <<<'SQL'
                    SELECT EXISTS(
                      SELECT 1
                      FROM pg_catalog.pg_constraint
                      WHERE conname = ?
                      UNION ALL
                      SELECT 1
                      FROM pg_catalog.pg_indexes
                      WHERE schemaname = 'public'
                        AND indexname = ?
                    ) AS exists
                    SQL,
                [$indexOrConstraint, $indexOrConstraint],
            );

            self::assertTrue(
                (bool) $exists->exists,
                "Missing PostgreSQL constraint/index [{$indexOrConstraint}].",
            );
        }

        foreach ([
            'reservations_tenant_id_id_unique',
            'units_tenant_id_id_unique',
        ] as $constraint) {
            self::assertNotNull(
                DB::selectOne(
                    'SELECT 1 FROM pg_catalog.pg_constraint WHERE conname = ?',
                    [$constraint],
                ),
                "Missing PostgreSQL constraint [{$constraint}].",
            );
        }

        $guards = DB::select(<<<'SQL'
            SELECT conname, condeferrable, condeferred
            FROM pg_catalog.pg_constraint
            WHERE conname IN (
              'unit_handover_evidence_final_state_guard',
              'unit_handover_acceptance_final_state_guard'
            )
            ORDER BY conname
            SQL);

        self::assertCount(2, $guards);

        foreach ($guards as $guard) {
            self::assertTrue($guard->condeferrable);
            self::assertTrue($guard->condeferred);
        }
    }

    public function test_valid_evidence_and_acceptance_persist_exact_economic_truth(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $acceptanceId = $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $evidence = DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->first();

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->first();

        self::assertSame('explicit_customer_acceptance', $evidence->acceptance_basis);
        self::assertSame('HANDOVER/TEST-001', $evidence->handover_evidence_reference);
        self::assertSame('effective', $evidence->status);

        self::assertSame('effective', $acceptance->status);
        self::assertSame('450000.00', $acceptance->performance_amount);
        self::assertSame('SAR', $acceptance->currency);
        self::assertSame('2026-08-20', (string) $acceptance->performance_date);
    }

    public function test_evidence_rejects_noncanonical_reference_and_wrong_source_chain(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $this->assertRejected(fn () => $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            [
                'handover_evidence_reference' => ' handover/test-001 ',
            ],
        ));

        $otherProject = $this->createIntegrityProject($tenantId, $actorId);
        $otherUnit = $this->createIntegrityUnit(
            $tenantId,
            (string) $otherProject->id,
            $actorId,
            'sold',
        );

        $this->assertRejected(fn () => $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            (string) $otherUnit->id,
            $contractId,
            [
                'handover_evidence_reference' => 'HANDOVER/TEST-002',
            ],
        ));
    }

    public function test_acceptance_rejects_wrong_amount_date_and_ineligible_parent_state(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $this->assertRejected(fn () => $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
            [
                'performance_amount' => '449999.99',
            ],
        ));

        $this->assertRejected(fn () => $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
            [
                'performance_date' => '2026-08-21',
            ],
        ));

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update(['status' => 'paused']);

        $this->assertRejected(fn () => $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        ));
    }

    public function test_effective_acceptance_blocks_parent_lifecycle_regression_but_allows_completion(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        );

        DB::table('contracts')
            ->where('id', $contractId)
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

        self::assertSame(
            'completed',
            DB::table('contracts')->where('id', $contractId)->value('status'),
        );

        $this->assertRejected(
            fn () => DB::table('contracts')
                ->where('id', $contractId)
                ->update(['status' => 'cancelled']),
        );

        $this->assertRejected(
            fn () => DB::table('reservations')
                ->where('id', $reservationId)
                ->update(['status' => 'cancelled']),
        );

        $this->assertRejected(
            fn () => DB::table('units')
                ->where('id', $unitId)
                ->update(['status' => 'available']),
        );

        $this->assertRejected(
            fn () => DB::table('units')
                ->where('id', $unitId)
                ->update([
                    'archived_at' => now(),
                    'archived_by' => $actorId,
                ]),
        );
    }

    public function test_evidence_cannot_reverse_before_effective_acceptance(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        );

        $this->assertRejected(
            fn () => DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => (string) Str::ulid(),
                    'reversal_reason' => 'Source correction',
                    'reversal_reference' => 'REV/TEST-001',
                    'reversed_by' => $actorId,
                    'reversed_at' => now(),
                    'updated_at' => now(),
                ]),
        );
    }

    public function test_handover_business_truth_is_immutable_and_delete_is_forbidden(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $acceptanceId = $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        );

        $this->assertRejected(
            fn () => DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->update([
                    'readiness_reference' => 'CHANGED',
                    'updated_at' => now(),
                ]),
        );

        $this->assertRejected(
            fn () => DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->update([
                    'performance_amount' => '1.00',
                    'updated_at' => now(),
                ]),
        );

        $this->assertRejected(
            fn () => DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->delete(),
        );

        $this->assertRejected(
            fn () => DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->delete(),
        );
    }

    public function test_runtime_role_has_only_required_handover_table_privileges_and_no_function_execution(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        self::assertNotSame('', $role);

        foreach ([
            'public.unit_handover_evidence',
            'public.unit_handover_acceptances',
        ] as $table) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, $table, 'SELECT'],
            )->allowed);

            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, $table, 'INSERT'],
            )->allowed);

            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, $table, 'UPDATE'],
            )->allowed);

            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, $table, 'DELETE'],
            )->allowed);

            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, $table, 'TRUNCATE'],
            )->allowed);
        }

        foreach ([
            'enforce_unit_handover_evidence_history',
            'validate_unit_handover_evidence_source',
            'enforce_unit_handover_acceptance_history',
            'validate_unit_handover_acceptance_source',
            'validate_unit_handover_evidence_final_state',
            'validate_unit_handover_acceptance_final_state',
            'prevent_contract_handover_provenance_mutation',
            'prevent_reservation_handover_provenance_mutation',
            'lock_unit_handover_evidence_tenant',
            'validate_unit_handover_acceptance_tenant',
            'prevent_contract_status_with_effective_handover',
            'prevent_reservation_status_with_effective_handover',
            'prevent_unit_mutation_with_effective_handover',
        ] as $function) {
            self::assertFalse((bool) DB::selectOne(
                "SELECT has_function_privilege(
                    ?,
                    'public.' || ? || '()',
                    'EXECUTE'
                ) AS allowed",
                [$role, $function],
            )->allowed, "Runtime role can execute protected function [{$function}].");
        }
    }

    public function test_valid_reversal_requires_acceptance_first_then_evidence_and_preserves_history(): void
    {
        [$tenantId, $actorId, $customerId, $reservationId, $unitId, $contractId] =
            $this->handoverContext();

        $evidenceId = $this->insertEvidence(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
        );

        $acceptanceId = $this->insertAcceptance(
            $tenantId,
            $actorId,
            $customerId,
            $reservationId,
            $unitId,
            $contractId,
            $evidenceId,
        );

        $reversalOperationId = (string) Str::ulid();

        DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->update([
                'status' => 'reversed',
                'reversal_operation_id' => $reversalOperationId,
                'reversal_reason' => 'Correct accepted handover source',
                'reversal_reference' => 'REV/ACCEPTANCE-001',
                'reversed_by' => $actorId,
                'reversed_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->update([
                'status' => 'reversed',
                'reversal_operation_id' => $reversalOperationId,
                'reversal_reason' => 'Correct handover evidence',
                'reversal_reference' => 'REV/EVIDENCE-001',
                'reversed_by' => $actorId,
                'reversed_at' => now(),
                'updated_at' => now(),
            ]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('id', $acceptanceId)
            ->first();

        $evidence = DB::table('unit_handover_evidence')
            ->where('id', $evidenceId)
            ->first();

        self::assertSame('reversed', $acceptance->status);
        self::assertSame(
            $reversalOperationId,
            $acceptance->reversal_operation_id,
        );
        self::assertSame('450000.00', $acceptance->performance_amount);
        self::assertSame('2026-08-20', (string) $acceptance->performance_date);

        self::assertSame('reversed', $evidence->status);
        self::assertSame(
            $reversalOperationId,
            $evidence->reversal_operation_id,
        );
        self::assertSame(
            'HANDOVER/TEST-001',
            $evidence->handover_evidence_reference,
        );
        self::assertSame(
            'explicit_customer_acceptance',
            $evidence->acceptance_basis,
        );
    }

    private function handoverContext(): array
    {
        $user = $this->createActiveUser();
        $tenantId = $this->integrityTenantId($user);
        $actorId = (int) $user->id;

        $project = $this->createIntegrityProject($tenantId, $actorId);

        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actorId,
            'sold',
        );

        $customer = $this->createIntegrityCustomer($tenantId, $actorId);

        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actorId,
            'converted',
        );

        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actorId,
            'active',
        );

        return [
            $tenantId,
            $actorId,
            (string) $customer->id,
            (string) $reservation->id,
            (string) $unit->id,
            (string) $contract->id,
        ];
    }

    private function insertEvidence(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $reservationId,
        string $unitId,
        string $contractId,
        array $overrides = [],
    ): string {
        $id = (string) Str::ulid();
        $now = now();

        DB::table('unit_handover_evidence')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'reservation_id' => $reservationId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'handover_evidence_operation_id' => (string) Str::ulid(),
            'handover_evidence_reference' => 'HANDOVER/TEST-001',
            'readiness_reference' => 'READINESS/TEST-001',
            'readiness_effective_date' => '2026-08-19',
            'acceptance_basis' => 'explicit_customer_acceptance',
            'customer_acceptance_reference' => 'ACCEPTANCE/TEST-001',
            'customer_acceptance_effective_date' => '2026-08-20',
            'effective_date' => '2026-08-20',
            'recorded_by' => $actorId,
            'recorded_at' => $now,
            'status' => 'effective',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }

    private function insertAcceptance(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $reservationId,
        string $unitId,
        string $contractId,
        string $evidenceId,
        array $overrides = [],
    ): string {
        $id = (string) Str::ulid();
        $now = now();

        DB::table('unit_handover_acceptances')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'handover_evidence_id' => $evidenceId,
            'contract_id' => $contractId,
            'reservation_id' => $reservationId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'handover_acceptance_operation_id' => (string) Str::ulid(),
            'performance_date' => '2026-08-20',
            'performance_amount' => '450000.00',
            'currency' => 'SAR',
            'status' => 'effective',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }

    private function assertRejected(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $this->fail(
                'PostgreSQL accepted invalid Unit Handover state.',
            );
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
