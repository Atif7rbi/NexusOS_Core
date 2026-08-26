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

        DB::statement('SET ROLE "'.$role.'"');
        try {
            $id = $this->insert($tenantId, $actorId, $customerId);
            self::assertSame('recognized', DB::table('receivables')->where('id', $id)->value('status'));
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

    private function insert(string $tenantId, int $actorId, string $customerId, string $amount = '1.00', string $status = 'recognized', ?string $dueDate = '2026-09-01'): string
    {
        $id = (string) Str::ulid();
        DB::table('receivables')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId,
            'currency' => 'SAR', 'recognized_amount' => $amount, 'due_date' => $dueDate,
            'status' => $status, 'recognized_at' => now(), 'recognized_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
