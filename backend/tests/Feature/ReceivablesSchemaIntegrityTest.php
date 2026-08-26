<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Collections\Models\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ReceivablesSchemaIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_physical_schema_has_exact_money_lifecycle_and_tenant_constraints(): void
    {
        $amount = DB::selectOne("SELECT numeric_precision,numeric_scale,is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name='receivables' AND column_name='recognized_amount'");
        self::assertSame(19, $amount->numeric_precision);
        self::assertSame(2, $amount->numeric_scale);
        self::assertSame('NO', $amount->is_nullable);
        $constraints = DB::table('pg_catalog.pg_constraint')->whereIn('conname', [
            'receivables_customer_foreign', 'receivables_contract_foreign', 'receivables_collection_foreign',
            'receivables_recognized_actor_foreign', 'receivables_cancelled_actor_foreign', 'receivables_lifecycle_check',
        ])->count();
        self::assertSame(6, $constraints);
    }

    public function test_direct_sql_rejects_zero_negative_unsupported_status_and_missing_due_date(): void
    {
        [$tenantId, $actorId, $customerId] = $this->context();
        foreach ([['0.00', 'recognized', '2026-09-01'], ['-1.00', 'recognized', '2026-09-01'], ['1.00', 'draft', '2026-09-01'], ['1.00', 'recognized', null]] as [$amount, $status, $due]) {
            DB::beginTransaction();
            try {
                $this->insert($tenantId, $actorId, $customerId, $amount, $status, $due);
                self::fail('Invalid direct-SQL Receivable was accepted.');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_direct_sql_rejects_initial_cancellation_and_cancellation_before_recognition(): void
    {
        [$tenantId, $actorId, $customerId] = $this->context();

        $this->assertRejected(fn () => $this->insert($tenantId, $actorId, $customerId, overrides: [
            'status' => 'cancelled',
            'cancelled_at' => '2026-08-26T11:00:00+00:00',
            'cancelled_by' => $actorId,
            'cancellation_reason' => 'Invalid initial state',
        ]));

        $id = $this->insert($tenantId, $actorId, $customerId, overrides: [
            'recognized_at' => '2026-08-26T12:00:00+00:00',
        ]);
        $this->assertRejected(fn () => DB::table('receivables')->where('id', $id)->update([
            'status' => 'cancelled',
            'cancelled_at' => '2026-08-26T11:59:59+00:00',
            'cancelled_by' => $actorId,
            'cancellation_reason' => 'Backdated cancellation',
            'updated_at' => now(),
        ]));
    }

    public function test_direct_sql_enforces_typed_contract_collection_and_customer_provenance(): void
    {
        [$tenantId, $actorId, $customerId] = $this->context();
        $otherCustomer = $this->createIntegrityCustomer($tenantId, $actorId);
        [$contractId, $collectionId] = $this->sources($tenantId, $actorId, $customerId);
        [$otherContractId] = $this->sources($tenantId, $actorId, (string) $otherCustomer->id);

        $this->assertRejected(fn () => $this->insert($tenantId, $actorId, (string) $otherCustomer->id, overrides: [
            'contract_id' => $contractId,
        ]));
        $this->assertRejected(fn () => $this->insert($tenantId, $actorId, (string) $otherCustomer->id, overrides: [
            'collection_id' => $collectionId,
        ]));
        $this->assertRejected(fn () => $this->insert($tenantId, $actorId, $customerId, overrides: [
            'contract_id' => $otherContractId,
            'collection_id' => $collectionId,
        ]));

        $id = $this->insert($tenantId, $actorId, $customerId, overrides: ['collection_id' => $collectionId]);
        self::assertNull(DB::table('receivables')->where('id', $id)->value('contract_id'));
    }

    public function test_recognized_truth_is_immutable_deletion_is_forbidden_and_cancelled_is_terminal(): void
    {
        [$tenantId, $actorId, $customerId] = $this->context();
        $id = $this->insert($tenantId, $actorId, $customerId);

        foreach ([
            fn () => DB::table('receivables')->where('id', $id)->update(['recognized_amount' => '2.00']),
            fn () => DB::table('receivables')->where('id', $id)->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                self::fail('Forbidden Receivable history mutation was accepted.');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }

        DB::table('receivables')->where('id', $id)->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actorId,
            'cancellation_reason' => 'Correction', 'updated_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        DB::table('receivables')->where('id', $id)->update([
            'status' => 'recognized', 'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null,
        ]);
    }

    public function test_direct_sql_enforces_same_tenant_customer_and_actor_membership(): void
    {
        [$tenantId, $actorId] = $this->context();
        [, , $otherCustomer] = $this->context();

        $this->expectException(QueryException::class);
        $this->insert($tenantId, $actorId, $otherCustomer);
    }

    public function test_runtime_role_can_use_table_but_cannot_execute_history_trigger_function_directly(): void
    {
        [$tenantId, $actorId, $customerId] = $this->context();
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        self::assertFalse((bool) DB::selectOne("SELECT has_function_privilege(?, 'public.enforce_receivable_history()', 'EXECUTE') allowed", [$role])->allowed);
        $function = DB::selectOne(<<<'SQL'
            SELECT procedure.prosecdef,
                   pg_catalog.pg_get_userbyid(procedure.proowner) AS owner,
                   pg_catalog.array_to_string(procedure.proconfig, ',') AS config
            FROM pg_catalog.pg_proc procedure
            WHERE procedure.oid='public.enforce_receivable_history()'::regprocedure
            SQL);
        self::assertTrue($function->prosecdef);
        self::assertNotSame($role, $function->owner);
        self::assertSame('search_path=pg_catalog, public', $function->config);

        DB::statement('SET ROLE "'.$role.'"');
        try {
            $id = $this->insert($tenantId, $actorId, $customerId);
            self::assertSame('recognized', DB::table('receivables')->where('id', $id)->value('status'));
            $this->assertRejected(fn () => $this->insert($tenantId, $actorId, $customerId, overrides: [
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => 'Runtime invalid initial state',
            ]));
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        return [$tenantId, $actor->id, (string) $customer->id];
    }

    private function sources(string $tenantId, int $actorId, string $customerId): array
    {
        $project = $this->createIntegrityProject($tenantId, $actorId);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actorId, 'reserved');
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, $customerId, $actorId);
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actorId);
        $collection = Collection::query()->create([
            'tenant_id' => $tenantId,
            'contract_id' => $contract->id,
            'sequence' => 1,
            'title' => 'Integrity installment',
            'amount' => '100.00',
            'due_date' => '2026-09-01',
            'status' => 'draft',
            'created_by' => $actorId,
        ]);

        return [(string) $contract->id, (string) $collection->id];
    }

    private function assertRejected(callable $mutation): void
    {
        DB::beginTransaction();
        try {
            $mutation();
            self::fail('Invalid direct-SQL Receivable mutation was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    private function insert(string $tenantId, int $actorId, string $customerId, string $amount = '1.00', string $status = 'recognized', ?string $dueDate = '2026-09-01', array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('receivables')->insert(array_merge([
            'id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId,
            'currency' => 'SAR', 'recognized_amount' => $amount, 'due_date' => $dueDate,
            'status' => $status, 'recognized_at' => now(), 'recognized_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
