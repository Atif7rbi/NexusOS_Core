<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionValidationFailed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class AccountingRecognitionFoundationsTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_policy_families_are_explicit_versioned_and_superseded_without_rewriting_history(): void
    {
        $context = $this->considerationContext();
        $accounts = $this->recognitionAccounts($context);
        $manager = app(ConfigureAccountingRecognitionPolicies::class);

        $ar1 = $manager->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['ar'],
        );
        $counterpart = $manager->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['contract_asset'],
            $accounts['contract_liability'],
        );
        $performance = $manager->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['revenue'],
            $accounts['contract_asset'],
            $accounts['contract_liability'],
        );

        self::assertSame(1, $ar1['policy_version']);
        self::assertSame(1, $counterpart['policy_version']);
        self::assertSame(1, $performance['policy_version']);

        $ar2 = $manager->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-07-01',
            $accounts['ar_successor'],
        );

        self::assertSame(2, $ar2['policy_version']);
        $history = DB::table('receivable_ar_policies')
            ->where('tenant_id', $context['tenant_id'])
            ->orderBy('policy_version')
            ->get();

        self::assertCount(2, $history);
        self::assertSame('superseded', $history[0]->status);
        self::assertSame('2026-06-30', (string) $history[0]->effective_to);
        self::assertSame($accounts['ar'], $history[0]->ar_control_account_id);
        self::assertSame('active', $history[1]->status);
        self::assertNull($history[1]->effective_to);
        self::assertSame($accounts['ar_successor'], $history[1]->ar_control_account_id);

        $this->assertSqlRejected(
            fn () => DB::table('receivable_ar_policies')
                ->where('id', $history[0]->id)
                ->update(['ar_control_account_id' => $accounts['ar_successor']]),
            ['55000', '23514'],
        );
    }

    public function test_policy_role_account_types_and_version_history_are_db_backed(): void
    {
        $context = $this->considerationContext();
        $accounts = $this->recognitionAccounts($context);
        $manager = app(ConfigureAccountingRecognitionPolicies::class);

        $this->expectException(AccountingRecognitionValidationFailed::class);
        $manager->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['revenue'],
        );
    }

    public function test_direct_sql_policy_version_jump_cannot_commit(): void
    {
        $context = $this->considerationContext();
        $accounts = $this->recognitionAccounts($context);
        $manager = app(ConfigureAccountingRecognitionPolicies::class);

        $manager->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['ar'],
        );

        $active = DB::table('receivable_ar_policies')
            ->where('tenant_id', $context['tenant_id'])
            ->first();

        $this->assertSqlRejected(function () use ($context, $accounts, $active): void {
            DB::table('receivable_ar_policies')
                ->where('id', $active->id)
                ->update([
                    'status' => 'superseded',
                    'effective_to' => '2026-06-30',
                    'superseded_by' => $context['actor']->id,
                    'superseded_at' => now(),
                ]);

            DB::table('receivable_ar_policies')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'policy_version' => 3,
                'status' => 'active',
                'ar_control_account_id' => $accounts['ar_successor'],
                'effective_from' => '2026-07-01',
                'effective_to' => null,
                'created_by' => $context['actor']->id,
                'created_at' => now(),
                'superseded_by' => null,
                'superseded_at' => null,
            ]);
        }, ['23514']);
    }

    public function test_clean_performance_accounting_adoption_replays_exact_committed_identity(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $operationId = (string) Str::ulid();
        $input = [
            'contract_id' => $context['contract_id'],
            'performance_accounting_adoption_operation_id' => $operationId,
        ];

        $first = app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            $input,
        );
        $replay = app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            $input,
        );

        self::assertSame($first, $replay);
        self::assertSame($consideration['position_id'], $first['consideration_position_id']);

        $row = DB::table('performance_accounting_adoptions')
            ->where('id', $first['adoption_id'])
            ->first();

        self::assertSame('ADOPTED', $row->status);
        self::assertSame('CLEAN_PROTOCOL_READY', $row->adoption_basis);
        self::assertSame('PERFORMANCE_ACCOUNTING_V1', $row->accounting_scope_version);
        self::assertSame('1000.00', $row->consideration_amount);

        $this->expectException(AccountingRecognitionConflict::class);
        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_adoption_rejects_caller_canonical_facts_and_prior_effective_performance(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);

        try {
            app(AdoptPerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'contract_id' => $context['contract_id'],
                    'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
                    'consideration_amount' => '1.00',
                ],
            );
            self::fail('Caller supplied adoption capacity was accepted.');
        } catch (AccountingRecognitionValidationFailed) {
            self::assertTrue(true);
        }

        DB::transaction(function () use ($context, $consideration): void {
            $source = $this->handoverSource($context);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );
        });

        $this->expectException(AccountingRecognitionConflict::class);
        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_adoption_rejects_billed_unearned_without_exact_protocol_liability_provenance(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-19',
        )[0];
        $consideration = $this->adopt($context);

        DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): void {
            $source = $this->billingSource($context, $obligationId);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );
        });

        $this->expectException(AccountingRecognitionConflict::class);
        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_runtime_role_can_configure_foundations_but_cannot_forge_provenance(): void
    {
        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        self::assertNotEmpty($role);

        foreach ([
            'receivable_ar_policies',
            'contract_consideration_accounting_policies',
            'performance_accounting_policies',
            'performance_accounting_adoptions',
        ] as $table) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'SELECT'],
            )->allowed);
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'INSERT'],
            )->allowed);
        }

        foreach ([
            'accounting_position_origins',
            'accounting_position_consumptions',
            'accounting_position_origin_journal_line_allocations',
            'accounting_position_consumption_journal_line_allocations',
        ] as $table) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'SELECT'],
            )->allowed);
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'INSERT'],
            )->allowed);
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'UPDATE'],
            )->allowed);
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$table, 'DELETE'],
            )->allowed);
        }

        foreach ([
            'validate_accounting_recognition_policy_history(text,character)',
            'validate_accounting_position_origin(character,character)',
            'validate_accounting_position_consumption(character,character)',
            'validate_performance_accounting_adoption(character,character)',
        ] as $function) {
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_function_privilege(?, ?, ?) AS allowed',
                [$role, 'public.'.$function, 'EXECUTE'],
            )->allowed);
        }
    }

    private function recognitionAccounts(array $context): array
    {
        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        $create = fn (
            string $code,
            string $type,
            string $classification,
        ): string => app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => 'Recognition '.$code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );

        return [
            'ar' => $create('AR100', 'asset', 'current_asset'),
            'ar_successor' => $create('AR101', 'asset', 'current_asset'),
            'contract_asset' => $create('CA100', 'asset', 'current_asset'),
            'contract_liability' => $create(
                'CL100',
                'liability',
                'current_liability',
            ),
            'revenue' => $create('REV100', 'revenue', 'operating_revenue'),
        ];
    }

    private function assertSqlRejected(
        callable $operation,
        array $states,
    ): void {
        DB::beginTransaction();

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted inconsistent Accounting Recognition foundation truth.');
        } catch (QueryException $exception) {
            self::assertContains(
                (string) ($exception->errorInfo[0] ?? ''),
                $states,
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }
    }
}
