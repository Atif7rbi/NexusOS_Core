<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Settlement\Actions\SettlePaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesReceivableSettlementFixtures;
use Tests\TestCase;

final class ReceivableSettlementSecurityTest extends TestCase
{
    use CreatesReceivableSettlementFixtures;
    use RefreshDatabase;

    public function test_runtime_role_has_narrow_settlement_privileges(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, 'public.receivable_settlements', $privilege],
            )->allowed);
        }
        self::assertFalse((bool) DB::selectOne(
            'SELECT has_table_privilege(?, ?, ?) allowed',
            [$role, 'public.receivable_settlements', 'DELETE'],
        )->allowed);
        foreach ([
            'public.receivable_settlement_history_guard()',
            'public.validate_receivable_settlement(character,character)',
            'public.receivable_settlement_final_state()',
            'public.receivable_settlement_parent_lifecycle_guard()',
        ] as $function) {
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_function_privilege(?, ?, ?) allowed',
                [$role, $function, 'EXECUTE'],
            )->allowed, "Runtime role can execute protected function {$function}");
        }
        $owner = DB::selectOne(
            "SELECT pg_catalog.pg_get_userbyid(relowner) name FROM pg_catalog.pg_class WHERE oid='public.receivable_settlements'::regclass",
        )->name;
        self::assertNotSame($role, $owner);
    }

    public function test_runtime_role_cannot_mutate_or_delete_historical_settlement(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        $caught = null;
        try {
            $this->asRuntimeRole(fn () => DB::table('receivable_settlements')
                ->where('id', $posted['settlement_id'])->update(['amount' => '1.00']));
        } catch (QueryException $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame('55000', (string) ($caught->errorInfo[0] ?? ''));

        $caught = null;
        try {
            $this->asRuntimeRole(fn () => DB::table('receivable_settlements')
                ->where('id', $posted['settlement_id'])->delete());
        } catch (QueryException $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame('42501', (string) ($caught->errorInfo[0] ?? ''));
    }

    public function test_unauthorized_role_cannot_post_settlement(): void
    {
        $fixture = $this->settlementContext();
        $sales = User::factory()->create([
            'role' => User::ROLE_SALES,
            'status' => User::STATUS_ACTIVE,
        ]);
        TenantUser::factory()->create([
            'tenant_id' => $fixture['context']['tenant_id'],
            'user_id' => $sales->id,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);
        $this->expectException(ReceivablesAccessDenied::class);
        app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $sales,
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
    }

    private function asRuntimeRole(callable $callback): mixed
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new \RuntimeException('Invalid ACCOUNTING_RUNTIME_DB_ROLE.');
        }
        if (! (bool) DB::selectOne(
            "SELECT pg_has_role(session_user, ?, 'MEMBER') allowed",
            [$role],
        )->allowed) {
            throw new \RuntimeException(
                'The test database login must be a member of ACCOUNTING_RUNTIME_DB_ROLE.',
            );
        }
        $identifier = '"'.str_replace('"', '""', $role).'"';

        return DB::transaction(function () use ($identifier, $callback): mixed {
            DB::statement("SET LOCAL ROLE {$identifier}");
            $result = $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }
}
