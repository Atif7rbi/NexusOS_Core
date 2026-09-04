<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingSchemaIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_physical_schema_has_required_money_uniqueness_and_deferred_guards(): void
    {
        $amount = DB::selectOne(<<<'SQL'
            SELECT numeric_precision, numeric_scale, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'contractual_billing_obligations'
              AND column_name = 'amount'
            SQL);

        self::assertSame(19, $amount->numeric_precision);
        self::assertSame(2, $amount->numeric_scale);
        self::assertSame('NO', $amount->is_nullable);

        $entitlementAmount = DB::selectOne(<<<'SQL'
            SELECT numeric_precision, numeric_scale, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'contractual_billing_entitlements'
              AND column_name = 'amount'
            SQL);

        self::assertSame(19, $entitlementAmount->numeric_precision);
        self::assertSame(2, $entitlementAmount->numeric_scale);
        self::assertSame('NO', $entitlementAmount->is_nullable);

        foreach ([
            'contractual_billing_schedules_current_finalized_unique',
            'cbs_draft_cancel_operation_unique',
            'cbs_source_correction_operation_unique',
            'contractual_billing_obligations_removal_operation_unique',
            'contractual_billing_entitlements_obligation_history_unique',
            'contractual_billing_entitlements_reversal_operation_unique',
        ] as $index) {
            self::assertNotNull(DB::selectOne(
                'SELECT 1 FROM pg_catalog.pg_indexes WHERE schemaname = ? AND indexname = ?',
                ['public', $index],
            ), "Missing PostgreSQL index [{$index}].");
        }

        $guards = DB::select(<<<'SQL'
            SELECT conname, condeferrable, condeferred
            FROM pg_catalog.pg_constraint
            WHERE conname IN (
                'contractual_billing_schedules_final_state_guard',
                'contractual_billing_entitlements_final_state_guard'
            )
            ORDER BY conname
            SQL);

        self::assertCount(2, $guards);

        foreach ($guards as $guard) {
            self::assertTrue($guard->condeferrable);
            self::assertTrue($guard->condeferred);
        }
    }

    public function test_schedule_and_obligation_history_guards_reject_invalid_direct_sql(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        $this->assertRejected(fn () => DB::table('contractual_billing_schedules')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'schedule_operation_id' => (string) Str::ulid(),
            'billing_model' => 'fixed_date_unconditional_full_schedule',
            'status' => 'finalized',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $scheduleId = $this->draftSchedule($tenantId, $actorId, $contractId);

        $this->assertRejected(
            fn () => DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->delete(),
        );

        $this->assertRejected(fn () => $this->insertObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            overrides: [
                'draft_membership_status' => 'removed',
                'removal_operation_id' => (string) Str::ulid(),
                'removed_by' => $actorId,
                'removed_at' => now(),
                'removal_reason' => 'Invalid initial state',
            ],
        ));

        $otherCustomer = $this->createIntegrityCustomer($tenantId, $actorId);

        $this->assertRejected(fn () => $this->insertObligation(
            $tenantId,
            $actorId,
            (string) $otherCustomer->id,
            $contractId,
            $scheduleId,
        ));
    }

    public function test_finalization_requires_full_amount_and_exact_tenant_timezone_snapshot(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        $withoutObligations = $this->draftSchedule($tenantId, $actorId, $contractId);

        $this->assertRejected(
            fn () => $this->finalizeSchedule(
                $withoutObligations,
                $actorId,
                'Asia/Riyadh',
            ),
        );

        $wrongTotal = $this->draftSchedule($tenantId, $actorId, $contractId);

        $this->insertObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $wrongTotal,
            amount: '900.00',
        );

        $this->assertRejected(
            fn () => $this->finalizeSchedule(
                $wrongTotal,
                $actorId,
                'Asia/Riyadh',
            ),
        );

        $wrongTimezone = $this->draftSchedule($tenantId, $actorId, $contractId);

        $this->insertObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $wrongTimezone,
        );

        $this->assertRejected(
            fn () => $this->finalizeSchedule(
                $wrongTimezone,
                $actorId,
                'UTC',
            ),
        );
    }

    public function test_valid_finalization_persists_exact_schedule_and_obligation_truth(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->finalizedSchedule(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertSame('finalized', $schedule->status);
        self::assertSame('Asia/Riyadh', $schedule->contractual_timezone);

        $obligation = DB::table('contractual_billing_obligations')
            ->where('id', $obligationId)
            ->first();

        self::assertSame('1000.00', $obligation->amount);
        self::assertSame('SAR', $obligation->currency);
        self::assertSame('included', $obligation->draft_membership_status);
    }

    public function test_contract_total_is_immutable_after_finalized_billing_history(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        $this->finalizedSchedule(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $this->assertRejected(
            fn () => DB::table('contracts')
                ->where('id', $contractId)
                ->update([
                    'total_amount' => '1100.00',
                    'updated_at' => now(),
                ]),
        );
    }

    public function test_entitlement_requires_due_date_current_schedule_and_canonical_obligation_facts(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$futureScheduleId, $futureObligationId] = $this->finalizedSchedule(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            dueDate: '2099-01-01',
        );

        $this->assertRejected(fn () => $this->insertEntitlement(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $futureScheduleId,
            $futureObligationId,
            economicDate: '2099-01-01',
        ));

        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->finalizedSchedule(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $this->assertRejected(fn () => $this->insertEntitlement(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            $obligationId,
            amount: '999.00',
        ));

        $entitlementId = $this->insertEntitlement(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            $obligationId,
        );

        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('status'),
        );

        $this->assertRejected(fn () => $this->insertEntitlement(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            $obligationId,
        ));
    }

    public function test_deferred_final_state_guards_block_standalone_reversal_and_incomplete_source_correction(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->finalizedSchedule(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $entitlementId = $this->insertEntitlement(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            $obligationId,
        );

        $this->assertRejectedDeferred(function () use ($entitlementId, $actorId): void {
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => (string) Str::ulid(),
                    'reversed_by' => $actorId,
                    'reversed_at' => now(),
                    'reversal_reason' => 'Standalone reversal',
                    'source_correction_operation_id' => (string) Str::ulid(),
                    'source_rescission_reference' => 'CBS-TEST',
                    'updated_at' => now(),
                ]);
        });

        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('status'),
        );

        $this->assertRejectedDeferred(function () use ($scheduleId, $actorId): void {
            DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->update([
                    'status' => 'cancelled',
                    'source_correction_operation_id' => (string) Str::ulid(),
                    'source_corrected_by' => $actorId,
                    'source_corrected_at' => now(),
                    'source_correction_reason' => 'Incomplete correction',
                    'updated_at' => now(),
                ]);
        });

        self::assertSame(
            'finalized',
            DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->value('status'),
        );
    }

    public function test_runtime_role_has_narrow_table_privileges_and_cannot_execute_integrity_functions(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        foreach ([
            'contractual_billing_schedules',
            'contractual_billing_obligations',
            'contractual_billing_entitlements',
        ] as $table) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, "public.{$table}", 'SELECT'],
            )->allowed);

            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, "public.{$table}", 'INSERT'],
            )->allowed);

            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, "public.{$table}", 'UPDATE'],
            )->allowed);

            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, "public.{$table}", 'DELETE'],
            )->allowed);
        }

        $functions = [
            'enforce_contractual_billing_schedule_history',
            'validate_contractual_billing_schedule_finalization',
            'enforce_contractual_billing_obligation_history',
            'enforce_contractual_billing_entitlement_history',
            'validate_contractual_billing_schedule_final_state',
            'validate_contractual_billing_entitlement_final_state',
            'prevent_contract_total_change_after_billing_history',
        ];

        foreach ($functions as $function) {
            self::assertFalse((bool) DB::selectOne(
                "SELECT has_function_privilege(?, 'public.' || ? || '()', 'EXECUTE') allowed",
                [$role, $function],
            )->allowed);
        }

        DB::statement('SET ROLE "'.str_replace('"', '""', $role).'"');

        try {
            foreach ($functions as $function) {
                $this->assertRejected(
                    fn () => DB::unprepared("SELECT public.{$function}()"),
                );
            }
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /**
     * @return array{string, int, string, string}
     */
    private function context(): array
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        $tenantId = $this->integrityTenantId($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);

        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actor->id,
            'reserved',
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
        );

        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            ['total_amount' => '1000.00'],
        );

        return [
            $tenantId,
            $actor->id,
            (string) $customer->id,
            (string) $contract->id,
        ];
    }

    private function draftSchedule(
        string $tenantId,
        int $actorId,
        string $contractId,
        ?string $replacesScheduleId = null,
    ): string {
        $id = (string) Str::ulid();

        DB::table('contractual_billing_schedules')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'schedule_operation_id' => (string) Str::ulid(),
            'billing_model' => 'fixed_date_unconditional_full_schedule',
            'status' => 'draft',
            'replaces_schedule_id' => $replacesScheduleId,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertObligation(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $contractId,
        string $scheduleId,
        string $amount = '1000.00',
        string $dueDate = '2026-09-01',
        array $overrides = [],
    ): string {
        $id = (string) Str::ulid();

        DB::table('contractual_billing_obligations')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'schedule_id' => $scheduleId,
            'contract_id' => $contractId,
            'obligation_operation_id' => (string) Str::ulid(),
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => 'SAR',
            'contractual_due_date' => $dueDate,
            'trigger_kind' => 'fixed_date_unconditional',
            'contractual_reference' => 'CBS integrity fixture',
            'draft_membership_status' => 'included',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function finalizeSchedule(
        string $scheduleId,
        int $actorId,
        string $timezone,
    ): void {
        DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->update([
                'status' => 'finalized',
                'contractual_timezone' => $timezone,
                'finalization_operation_id' => (string) Str::ulid(),
                'finalized_by' => $actorId,
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{string, string}
     */
    private function finalizedSchedule(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $contractId,
        string $dueDate = '2026-09-01',
    ): array {
        $scheduleId = $this->draftSchedule(
            $tenantId,
            $actorId,
            $contractId,
        );

        $obligationId = $this->insertObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            $scheduleId,
            dueDate: $dueDate,
        );

        $this->finalizeSchedule(
            $scheduleId,
            $actorId,
            'Asia/Riyadh',
        );

        return [$scheduleId, $obligationId];
    }

    private function insertEntitlement(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $contractId,
        string $scheduleId,
        string $obligationId,
        string $amount = '1000.00',
        string $economicDate = '2026-09-01',
    ): string {
        $id = (string) Str::ulid();

        DB::table('contractual_billing_entitlements')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'billing_entitlement_operation_id' => (string) Str::ulid(),
            'schedule_id' => $scheduleId,
            'obligation_id' => $obligationId,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => 'SAR',
            'economic_date' => $economicDate,
            'effective_at' => now(),
            'status' => 'effective',
            'recognized_by' => $actorId,
            'recognized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assertRejected(callable $mutation): void
    {
        DB::beginTransaction();

        try {
            $mutation();

            self::fail(
                'Invalid direct-SQL Contractual Billing mutation was accepted.',
            );
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    private function assertRejectedDeferred(callable $mutation): void
    {
        DB::beginTransaction();

        try {
            $mutation();

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            self::fail(
                'Invalid deferred Contractual Billing final state was accepted.',
            );
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
