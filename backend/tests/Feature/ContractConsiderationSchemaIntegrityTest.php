<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationSchemaIntegrityTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_exactly_four_foundation_tables_and_no_separate_consumption(): void
    {
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'contract_consideration_%' ORDER BY tablename");
        self::assertSame(['contract_consideration_lots', 'contract_consideration_positions',
            'contract_consideration_transition_lots', 'contract_consideration_transitions'], array_column($tables, 'tablename'));
    }

    public function test_position_without_genesis_cannot_commit(): void
    {
        $c = $this->considerationContext();
        $this->assertConsiderationSqlRejected(function () use ($c): void {
            DB::table('contract_consideration_positions')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $c['tenant_id'], 'contract_id' => $c['contract_id'],
                'coordination_adoption_operation_id' => $c['operation_id'], 'status' => 'ADOPTED',
                'consideration_amount' => '1000.00', 'currency' => 'SAR', 'adoption_basis' => 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES',
                'coordination_scope_version' => 'CONTRACT_CONSIDERATION_V1', 'adopted_by' => $c['actor']->id, 'adopted_at' => now(),
            ]);
        }, ['23514']);
        self::assertSame(0, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_adoption_genesis_and_contract_capacity_are_immutable(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        foreach ([['contract_consideration_positions', $a['position_id'], ['consideration_amount' => '2.00']],
            ['contract_consideration_lots', $a['genesis_lot_id'], ['amount' => '2.00']],
            ['contracts', $c['contract_id'], ['total_amount' => '2.00']]] as [$table, $id, $changes]) {
            $this->assertConsiderationSqlRejected(fn () => DB::table($table)->where('id', $id)->update($changes), ['55000']);
        }
        foreach (['contract_consideration_positions' => $a['position_id'], 'contract_consideration_lots' => $a['genesis_lot_id']] as $table => $id) {
            $this->assertConsiderationSqlRejected(fn () => DB::table($table)->where('id', $id)->delete(), ['55000']);
        }
    }

    public function test_genesis_identity_and_tenant_safe_parentage_are_enforced(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        $other = $this->considerationContext();
        $this->adopt($other);
        $row = (array) DB::table('contract_consideration_lots')->where('id', $a['genesis_lot_id'])->first();
        $row['id'] = (string) Str::ulid();
        $this->assertConsiderationSqlRejected(fn () => DB::table('contract_consideration_lots')->insert($row), ['23505']);
        $row['tenant_id'] = $other['tenant_id'];
        $this->assertConsiderationSqlRejected(fn () => DB::table('contract_consideration_lots')->insert($row), ['23503']);
    }

    public function test_effective_source_without_transition_cannot_commit_on_adopted_contract(): void
    {
        $c = $this->considerationContext();
        $this->adopt($c);
        $this->assertConsiderationSqlRejected(fn () => $this->handoverSource($c), ['23514']);
        self::assertSame(0, DB::table('unit_handover_acceptances')->where('tenant_id', $c['tenant_id'])->count());
        $ids = $this->billingObligations($c);
        $this->assertConsiderationSqlRejected(fn () => $this->billingSource($c, $ids[0]), ['23514']);
    }

    public function test_valid_full_handover_commits_exact_consumption_and_output(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        $g = DB::transaction(function () use ($c, $a): array {
            return $this->transition($c, $a, $this->handoverSource($c), $a['genesis_lot_id'], 'EARNED_UNBILLED');
        });
        $edge = DB::table('contract_consideration_transition_lots')->where('id', $g['edge_id'])->first();
        self::assertSame($a['genesis_lot_id'], $edge->lot_id);
        self::assertSame($g['lot_id'], $edge->successor_lot_id);
        self::assertSame('1000.00', $edge->consumed_amount);
        self::assertSame('1000.00', DB::table('contract_consideration_lots')->where('id', $a['genesis_lot_id'])->value('amount'));
        foreach (['contract_consideration_transitions' => $g['transition_id'], 'contract_consideration_lots' => $g['lot_id'],
            'contract_consideration_transition_lots' => $g['edge_id']] as $table => $id) {
            $this->assertConsiderationSqlRejected(fn () => DB::table($table)->where('id', $id)->delete(), ['55000']);
        }
        $this->assertConsiderationSqlRejected(fn () => DB::table('contract_consideration_transition_lots')->where('id', $g['edge_id'])
            ->update(['consumed_amount' => '1.00']), ['55000']);
        $this->assertConsiderationSqlRejected(fn () => DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])
            ->update(['transition_operation_id' => (string) Str::ulid()]), ['55000']);
    }

    public function test_wrong_grammar_and_source_amount_cannot_commit(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        $this->assertConsiderationSqlRejected(fn () => $this->transition($c, $a, $this->handoverSource($c), $a['genesis_lot_id'], 'BILLED_EARNED'), ['23514']);
        $this->assertConsiderationSqlRejected(fn () => $this->transition($c, $a, $this->handoverSource($c), $a['genesis_lot_id'], 'EARNED_UNBILLED', ['transition_amount' => '999.00']), ['23514']);
    }

    public function test_partial_transition_cannot_commit_or_be_hidden_by_reversal(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        $this->assertConsiderationSqlRejected(function () use ($c, $a): void {
            $source = $this->handoverSource($c);
            $source['amount'] = '999.00';
            $g = $this->transition($c, $a, $source, $a['genesis_lot_id'], 'EARNED_UNBILLED');
            $this->reverseHandover($c, $source, $g);
        }, ['23514']);
    }

    public function test_source_and_transition_must_reverse_together(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        [$source, $g] = DB::transaction(function () use ($c, $a): array {
            $source = $this->handoverSource($c);

            return [$source, $this->transition($c, $a, $source, $a['genesis_lot_id'], 'EARNED_UNBILLED')];
        });
        $this->assertConsiderationSqlRejected(fn () => $this->reverseHandover($c, $source, null), ['23514']);
        self::assertSame('effective', DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->value('status'));
        DB::transaction(fn () => $this->reverseHandover($c, $source, $g));
        self::assertSame('reversed', DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->value('status'));
    }

    public function test_handover_reversal_provenance_must_exactly_match_its_source(): void
    {
        $c = $this->considerationContext();
        $a = $this->adopt($c);
        [$source, $g] = DB::transaction(function () use ($c, $a): array {
            $source = $this->handoverSource($c);

            return [$source, $this->transition($c, $a, $source, $a['genesis_lot_id'], 'EARNED_UNBILLED')];
        });
        $otherActor = $this->sameTenantActor($c['tenant_id']);
        foreach ($this->reversalProvenanceMismatches($otherActor->id) as $mismatch) {
            $this->assertConsiderationSqlRejected(
                fn () => $this->reverseHandover($c, $source, $g, $mismatch),
                ['23514'],
            );
        }
        self::assertSame('effective', DB::table('unit_handover_acceptances')->where('id', $source['id'])->value('status'));
        self::assertSame('effective', DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->value('status'));

        DB::transaction(fn () => $this->reverseHandover($c, $source, $g));
        $sourceRow = DB::table('unit_handover_acceptances')->where('id', $source['id'])->first();
        $transition = DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->first();
        self::assertNotNull($sourceRow);
        self::assertNotNull($transition);
        self::assertSame($sourceRow->reversal_operation_id, $transition->reversal_operation_id);
        self::assertSame($sourceRow->reversal_operation_id, $transition->reversal_source_operation_id);
        self::assertSame($sourceRow->reversal_reason, $transition->reversal_reason);
        self::assertSame($sourceRow->reversal_reference, $transition->reversal_reference);
        self::assertSame($sourceRow->reversed_by, $transition->reversed_by);
        self::assertSame((string) $sourceRow->reversed_at, (string) $transition->reversed_at);
    }

    public function test_billing_reversal_provenance_must_exactly_match_its_source(): void
    {
        $c = $this->considerationContext();
        $obligationId = $this->billingObligations($c, ['1000.00'])[0];
        $a = $this->adopt($c);
        [$source, $g] = DB::transaction(function () use ($c, $a, $obligationId): array {
            $source = $this->billingSource($c, $obligationId);

            return [$source, $this->transition($c, $a, $source, $a['genesis_lot_id'], 'BILLED_UNEARNED')];
        });
        $otherActor = $this->sameTenantActor($c['tenant_id']);
        foreach ($this->reversalProvenanceMismatches($otherActor->id) as $mismatch) {
            $this->assertConsiderationSqlRejected(
                fn () => $this->reverseBilling($c, $source, $g, $mismatch),
                ['23514'],
            );
        }
        self::assertSame('effective', DB::table('contractual_billing_entitlements')->where('id', $source['id'])->value('status'));
        self::assertSame('effective', DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->value('status'));

        DB::transaction(fn () => $this->reverseBilling($c, $source, $g));
        $sourceRow = DB::table('contractual_billing_entitlements')->where('id', $source['id'])->first();
        $transition = DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->first();
        self::assertNotNull($sourceRow);
        self::assertNotNull($transition);
        self::assertSame($sourceRow->reversal_operation_id, $transition->reversal_operation_id);
        self::assertSame($sourceRow->source_correction_operation_id, $transition->reversal_source_operation_id);
        self::assertSame($sourceRow->reversal_reason, $transition->reversal_reason);
        self::assertSame($sourceRow->source_rescission_reference, $transition->reversal_reference);
        self::assertSame($sourceRow->reversed_by, $transition->reversed_by);
        self::assertSame((string) $sourceRow->reversed_at, (string) $transition->reversed_at);
    }

    public function test_backdated_transition_cannot_follow_later_reversed_history(): void
    {
        $c = $this->considerationContext();
        $obligationId = $this->billingObligations($c, ['1000.00'], '2026-08-19')[0];
        $a = $this->adopt($c);
        [$source, $g] = DB::transaction(function () use ($c, $a): array {
            $source = $this->handoverSource($c);

            return [$source, $this->transition($c, $a, $source, $a['genesis_lot_id'], 'EARNED_UNBILLED')];
        });
        DB::transaction(fn () => $this->reverseHandover($c, $source, $g));
        self::assertSame('reversed', DB::table('contract_consideration_transitions')->where('id', $g['transition_id'])->value('status'));

        $this->assertConsiderationSqlRejected(function () use ($c, $a, $obligationId): void {
            $backdated = $this->billingSource($c, $obligationId);
            $this->transition($c, $a, $backdated, $a['genesis_lot_id'], 'BILLED_UNEARNED');
        }, ['23514']);
        self::assertSame(1, DB::table('contract_consideration_transitions')->where('position_id', $a['position_id'])->count());
        self::assertSame(0, DB::table('contractual_billing_entitlements')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_handover_then_partial_billing_uses_earned_lot_and_preserves_remainder(): void
    {
        $c = $this->considerationContext();
        $ids = $this->billingObligations($c);
        $a = $this->adopt($c);
        [$source, $handover] = DB::transaction(function () use ($c, $a): array {
            $source = $this->handoverSource($c);

            return [$source, $this->transition($c, $a, $source, $a['genesis_lot_id'], 'EARNED_UNBILLED')];
        });
        $billing = DB::transaction(fn () => $this->transition($c, $a, $this->billingSource($c, $ids[0]), $handover['lot_id'], 'BILLED_EARNED'));
        self::assertSame('400.00', DB::table('contract_consideration_transition_lots')->where('id', $billing['edge_id'])->value('consumed_amount'));
        self::assertSame('1000.00', DB::table('contract_consideration_lots')->where('id', $handover['lot_id'])->value('amount'));
        self::assertSame(3, DB::table('contract_consideration_lots')->where('position_id', $a['position_id'])->count());
        $this->assertConsiderationSqlRejected(fn () => $this->reverseHandover($c, $source, $handover), ['23514']);
    }

    public function test_runtime_privileges_preserve_immutable_history_and_hide_helpers(): void
    {
        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        self::assertNotEmpty($role);
        foreach (['positions', 'transitions', 'lots', 'transition_lots'] as $suffix) {
            $table = 'public.contract_consideration_'.$suffix;
            self::assertTrue((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', [$role, $table, 'SELECT'])->allowed);
            self::assertFalse((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', [$role, $table, 'DELETE'])->allowed);
            self::assertFalse((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', [$role, $table, 'TRUNCATE'])->allowed);
        }
        foreach (['cc_immutable_history()', 'cc_adoption_source()', 'cc_contract_capacity()', 'cc_transition_history()',
            'cc_graph_insert_lock()', 'cc_validate_transition_selection(character,character)', 'cc_validate_graph(character,character)',
            'cc_graph_final_state()'] as $function) {
            self::assertFalse((bool) DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS allowed', [$role, 'public.'.$function, 'EXECUTE'])->allowed);
            $metadata = DB::selectOne('SELECT prosecdef,proconfig FROM pg_proc WHERE oid = ?::regprocedure', ['public.'.$function]);
            self::assertTrue((bool) $metadata->prosecdef);
            self::assertStringContainsString('search_path=pg_catalog, public', $metadata->proconfig);
        }
        $guards = DB::select("SELECT tgdeferrable,tginitdeferred FROM pg_trigger WHERE tgname IN ('cc_position_final','cc_transition_final','cc_lot_final','cc_edge_final','cc_billing_source_final','cc_handover_source_final')");
        self::assertCount(6, $guards);
        foreach ($guards as $guard) {
            self::assertTrue((bool) $guard->tgdeferrable);
            self::assertTrue((bool) $guard->tginitdeferred);
        }
    }

    private function sameTenantActor(string $tenantId): User
    {
        $actor = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        TenantUser::factory()
            ->forTenant(Tenant::query()->findOrFail($tenantId))
            ->forUser($actor)
            ->active()
            ->create();

        return $actor;
    }

    private function reversalProvenanceMismatches(int $otherActorId): array
    {
        return [
            ['reversal_operation_id' => (string) Str::ulid()],
            ['reversal_source_operation_id' => (string) Str::ulid()],
            ['reversal_reason' => 'Different correction reason'],
            ['reversal_reference' => 'CC/CORRECTION/DIFFERENT'],
            ['reversed_by' => $otherActorId],
            ['reversed_at' => now()->addMinute()],
        ];
    }
}
