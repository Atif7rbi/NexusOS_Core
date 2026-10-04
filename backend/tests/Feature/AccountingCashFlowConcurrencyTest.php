<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ManageCashFlowSemanticsAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Queries\CashFlowStatementQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingCashFlowConcurrencyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_cfs_c1_reader_observes_complete_pre_commit_then_post_commit_cash_flow(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '4000', 'name' => 'Revenue', 'description' => null, 'kind' => 'posting', 'account_type' => 'revenue', 'classification' => 'operating_revenue', 'parent_id' => null]);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $ready = $this->file('cash-flow-ready-');
        $release = $this->file('cash-flow-release-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $payload = compact('database', 'ready', 'release') + ['tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'cash' => $cash, 'revenue' => $revenue];
        $process = proc_open([PHP_BINARY, base_path('tests/Support/accounting_cash_flow_worker.php'), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->waitFor($ready);
        $before = app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-12-31');
        self::assertSame('0.00', $before['operating_activities']);
        self::assertSame('0.00', $before['ending_cash']);
        touch($release);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('{"ok":true}', trim($stdout));
        $after = app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-12-31');
        self::assertSame('100.00', $after['operating_activities']);
        self::assertSame('100.00', $after['net_change_in_cash']);
        self::assertSame('100.00', $after['ending_cash']);
    }

    public function test_cfs_c2_cash_role_and_structural_account_mutation_cannot_commit_an_incompatible_state(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        $account = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);

        $roleWins = $this->runRoleMutationRace((string) $tenant->id, (int) $actor->id, $account, 'role');
        self::assertSame('ok', $roleWins['assign']);
        self::assertSame('error', $roleWins['mutate']);
        self::assertSame('current_asset', DB::table('accounts')->where('tenant_id', $tenant->id)->where('id', $account)->value('classification'));
        self::assertTrue(DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $account)->exists());

        $otherAccount = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1010', 'name' => 'Other Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $mutationWins = $this->runRoleMutationRace((string) $tenant->id, (int) $actor->id, $otherAccount, 'mutation');
        self::assertSame('error', $mutationWins['assign']);
        self::assertSame('ok', $mutationWins['mutate']);
        self::assertSame('non_current_asset', DB::table('accounts')->where('tenant_id', $tenant->id)->where('id', $otherAccount)->value('classification'));
        self::assertFalse(DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $otherAccount)->exists());
    }

    public function test_cfs_c3_draft_semantic_update_and_posting_serialize_without_missing_or_stale_semantics(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '4000', 'name' => 'Revenue', 'description' => null, 'kind' => 'posting', 'account_type' => 'revenue', 'classification' => 'operating_revenue', 'parent_id' => null]);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);

        $semanticWinsJournal = $this->draftJournal((string) $tenant->id, $actor, $cash, $revenue);
        $semanticWins = $this->runSemanticPostRace((string) $tenant->id, (int) $actor->id, $semanticWinsJournal, 'semantic');
        self::assertSame('ok', $semanticWins['semantic']);
        self::assertSame('ok', $semanticWins['post']);
        self::assertSame('posted', DB::table('journal_entries')->where('id', $semanticWinsJournal)->value('status'));
        self::assertSame('financing', DB::table('journal_cash_flow_semantics')->where('journal_entry_id', $semanticWinsJournal)->value('activity'));

        $postWinsJournal = $this->draftJournal((string) $tenant->id, $actor, $cash, $revenue);
        $postWins = $this->runSemanticPostRace((string) $tenant->id, (int) $actor->id, $postWinsJournal, 'post');
        self::assertSame('error', $postWins['semantic']);
        self::assertSame('ok', $postWins['post']);
        self::assertSame('posted', DB::table('journal_entries')->where('id', $postWinsJournal)->value('status'));
        self::assertSame('operating', DB::table('journal_cash_flow_semantics')->where('journal_entry_id', $postWinsJournal)->value('activity'));
    }

    private function draftJournal(string $tenantId, User $actor, string $cash, string $revenue): string
    {
        return app(ManageManualJournalAction::class)->create(
            $tenantId,
            $actor,
            '2026-06-01',
            'Cash flow semantic race',
            [new JournalLineData($cash, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')],
            'operating',
        );
    }

    public function test_cfs_c4_same_cash_role_operation_replays_to_one_canonical_assignment(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        $account = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $operationId = (string) Str::ulid();
        $firstReady = $this->file('cash-role-first-ready-');
        $firstRelease = $this->file('cash-role-first-release-');
        $firstStarted = $this->file('cash-role-first-started-');
        $secondReady = $this->file('cash-role-second-ready-');
        $secondRelease = $this->file('cash-role-second-release-');
        $secondStarted = $this->file('cash-role-second-started-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $common = compact('database', 'operationId') + ['tenantId' => (string) $tenant->id, 'actorId' => (int) $actor->id, 'accountId' => $account, 'mode' => 'assign_role_action'];
        $first = $this->spawnWorker($common + ['ready' => $firstReady, 'release' => $firstRelease, 'started' => $firstStarted]);
        $this->waitFor($firstReady);
        $second = $this->spawnWorker($common + ['ready' => $secondReady, 'release' => $secondRelease, 'started' => $secondStarted]);
        $this->waitFor($secondStarted);
        touch($firstRelease);
        self::assertSame('ok', $this->closeWorker($first));
        $this->waitFor($secondReady);
        touch($secondRelease);
        self::assertSame('ok', $this->closeWorker($second));
        self::assertSame(1, DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('assignment_operation_id', $operationId)->count());
        $row = DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('assignment_operation_id', $operationId)->first();
        self::assertNotNull($row);
        self::assertSame($account, $row->account_id);
        self::assertSame('cash', $row->role);
    }

    public function test_cfs_c5_same_historical_semantic_operation_replays_once_and_rejects_conflicting_facts(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '4000', 'name' => 'Revenue', 'description' => null, 'kind' => 'posting', 'account_type' => 'revenue', 'classification' => 'operating_revenue', 'parent_id' => null]);
        $journalId = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-06-01', 'Historical cash flow', [new JournalLineData($cash, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journalId, $actor);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $operationId = (string) Str::ulid();
        $firstReady = $this->file('cash-adoption-first-ready-');
        $firstRelease = $this->file('cash-adoption-first-release-');
        $firstStarted = $this->file('cash-adoption-first-started-');
        $secondReady = $this->file('cash-adoption-second-ready-');
        $secondRelease = $this->file('cash-adoption-second-release-');
        $secondStarted = $this->file('cash-adoption-second-started-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $common = compact('database', 'operationId', 'journalId') + ['tenantId' => (string) $tenant->id, 'actorId' => (int) $actor->id, 'mode' => 'adopt_historical'];
        $first = $this->spawnWorker($common + ['ready' => $firstReady, 'release' => $firstRelease, 'started' => $firstStarted]);
        $this->waitFor($firstReady);
        $second = $this->spawnWorker($common + ['ready' => $secondReady, 'release' => $secondRelease, 'started' => $secondStarted]);
        $this->waitFor($secondStarted);
        touch($firstRelease);
        self::assertSame('ok', $this->closeWorker($first));
        $this->waitFor($secondReady);
        touch($secondRelease);
        self::assertSame('ok', $this->closeWorker($second));
        self::assertSame(1, DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('semantic_operation_id', $operationId)->count());
        self::assertSame('operating', DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $journalId)->value('activity'));
        self::assertSame(1, DB::table('accounting_audits')->where('tenant_id', $tenant->id)->where('event', 'cash_flow.historical_semantic_adopted')->where('subject_id', $journalId)->count());

        try {
            app(ManageCashFlowSemanticsAction::class)->adoptHistoricalSemantic((string) $tenant->id, $journalId, 'financing', $operationId, $actor);
            self::fail('Conflicting historical adoption facts were accepted.');
        } catch (AccountingConflict) {
            self::assertTrue(true);
        }
    }

    public function test_cfs_c6_reversal_race_and_direct_sql_preserve_cash_flow_semantic_integrity(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '4000', 'name' => 'Revenue', 'description' => null, 'kind' => 'posting', 'account_type' => 'revenue', 'classification' => 'operating_revenue', 'parent_id' => null]);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $original = $this->postedJournal((string) $tenant->id, $actor, $cash, $revenue, 'operating');
        $race = $this->runReversalRace((string) $tenant->id, (int) $actor->id, $original);
        self::assertSame('ok', $race['first']);
        self::assertSame('error', $race['second']);
        $reversal = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('reverses_journal_entry_id', $original)->sole();
        self::assertSame('operating', DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $reversal->id)->value('activity'));

        foreach ([
            static fn () => DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $reversal->id)->update(['activity' => 'financing']),
            static fn () => DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $reversal->id)->delete(),
        ] as $invalidMutation) {
            try {
                $invalidMutation();
                self::fail('Direct SQL changed a posted reversal semantic.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        $nonCash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1100', 'name' => 'Non-cash asset', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $nonCashOriginal = $this->postedJournal((string) $tenant->id, $actor, $nonCash, $revenue, null);
        $nonCashReversal = app(ReverseJournalAction::class)->execute((string) $tenant->id, $nonCashOriginal, $actor, '2026-06-02', 'Reverse non-cash')->journalEntryId;
        self::assertFalse(DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $nonCashReversal)->exists());
        try {
            DB::transaction(function () use ($tenant, $actor, $nonCashReversal): void {
                $now = now();
                DB::table('journal_cash_flow_semantics')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $nonCashReversal, 'activity' => 'operating', 'semantic_operation_id' => (string) Str::ulid(), 'assigned_by' => $actor->id, 'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            });
            self::fail('Direct SQL added a semantic to a non-cash reversal.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('cash flow semantic final state is incomplete', $e->getMessage());
        }
    }

    private function postedJournal(string $tenantId, User $actor, string $debitAccount, string $creditAccount, ?string $activity): string
    {
        $id = app(ManageManualJournalAction::class)->create($tenantId, $actor, '2026-06-01', 'Cash flow reversal', [new JournalLineData($debitAccount, '100.00', '0'), new JournalLineData($creditAccount, '0', '100.00')], $activity);
        app(ManageManualJournalAction::class)->post($tenantId, $id, $actor);

        return $id;
    }

    /** @return array{first:string,second:string} */
    private function runReversalRace(string $tenantId, int $actorId, string $journalId): array
    {
        $firstReady = $this->file('cash-reversal-first-ready-');
        $firstRelease = $this->file('cash-reversal-first-release-');
        $firstStarted = $this->file('cash-reversal-first-started-');
        $secondReady = $this->file('cash-reversal-second-ready-');
        $secondRelease = $this->file('cash-reversal-second-release-');
        $secondStarted = $this->file('cash-reversal-second-started-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $common = compact('database', 'journalId') + ['tenantId' => $tenantId, 'actorId' => $actorId, 'mode' => 'reverse_journal'];
        $first = $this->spawnWorker($common + ['ready' => $firstReady, 'release' => $firstRelease, 'started' => $firstStarted]);
        $this->waitFor($firstReady);
        $second = $this->spawnWorker($common + ['ready' => $secondReady, 'release' => $secondRelease, 'started' => $secondStarted]);
        $this->waitFor($secondStarted);
        touch($firstRelease);

        return ['first' => $this->closeWorker($first), 'second' => $this->closeWorker($second)];
    }

    /** @return array{semantic:string,post:string} */
    private function runSemanticPostRace(string $tenantId, int $actorId, string $journalId, string $winner): array
    {
        $semanticReady = $this->file('cash-semantic-ready-');
        $semanticRelease = $this->file('cash-semantic-release-');
        $semanticStarted = $this->file('cash-semantic-started-');
        $postReady = $this->file('cash-post-ready-');
        $postRelease = $this->file('cash-post-release-');
        $postStarted = $this->file('cash-post-started-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $common = compact('database', 'tenantId', 'actorId', 'journalId');

        if ($winner === 'semantic') {
            $semantic = $this->spawnWorker($common + ['mode' => 'update_semantic', 'ready' => $semanticReady, 'release' => $semanticRelease, 'started' => $semanticStarted]);
            $this->waitFor($semanticReady);
            $post = $this->spawnWorker($common + ['mode' => 'post_journal', 'ready' => $postReady, 'release' => $postRelease, 'started' => $postStarted]);
            $this->waitFor($postStarted);
            touch($semanticRelease);
        } else {
            $post = $this->spawnWorker($common + ['mode' => 'post_journal', 'ready' => $postReady, 'release' => $postRelease, 'started' => $postStarted]);
            $this->waitFor($postReady);
            $semantic = $this->spawnWorker($common + ['mode' => 'update_semantic', 'ready' => $semanticReady, 'release' => $semanticRelease, 'started' => $semanticStarted]);
            $this->waitFor($semanticStarted);
            touch($postRelease);
        }

        if ($winner === 'semantic') {
            $semanticResult = $this->closeWorker($semantic);
            touch($postRelease);

            return ['semantic' => $semanticResult, 'post' => $this->closeWorker($post)];
        }

        return ['semantic' => $this->closeWorker($semantic), 'post' => $this->closeWorker($post)];
    }

    /** @return array{assign:string,mutate:string} */
    private function runRoleMutationRace(string $tenantId, int $actorId, string $accountId, string $winner): array
    {
        $assignReady = $this->file('cash-role-ready-');
        $assignRelease = $this->file('cash-role-release-');
        $assignStarted = $this->file('cash-role-started-');
        $mutateReady = $this->file('cash-mutation-ready-');
        $mutateRelease = $this->file('cash-mutation-release-');
        $mutateStarted = $this->file('cash-mutation-started-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $common = compact('database', 'tenantId', 'actorId', 'accountId');
        if ($winner === 'role') {
            $assign = $this->spawnWorker($common + ['mode' => 'assign_role', 'ready' => $assignReady, 'release' => $assignRelease, 'started' => $assignStarted]);
            $this->waitFor($assignReady);
            $mutate = $this->spawnWorker($common + ['mode' => 'mutate_account', 'ready' => $mutateReady, 'release' => $mutateRelease, 'started' => $mutateStarted]);
            $this->waitFor($mutateStarted);
            touch($assignRelease);
        } else {
            $mutate = $this->spawnWorker($common + ['mode' => 'mutate_account', 'ready' => $mutateReady, 'release' => $mutateRelease, 'started' => $mutateStarted]);
            $this->waitFor($mutateReady);
            $assign = $this->spawnWorker($common + ['mode' => 'assign_role', 'ready' => $assignReady, 'release' => $assignRelease, 'started' => $assignStarted]);
            $this->waitFor($assignStarted);
            touch($mutateRelease);
        }

        $assignResult = $this->closeWorker($assign);
        $mutateResult = $this->closeWorker($mutate);

        return ['assign' => $assignResult, 'mutate' => $mutateResult];
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function spawnWorker(array $payload): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/accounting_cash_flow_worker.php'), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /** @param array{0:resource,1:array<int,resource>} $worker */
    private function closeWorker(array $worker): string
    {
        [$process, $pipes] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertContains($exit, [0, 1], $stderr);

        return $exit === 0 && trim($stdout) === '{"ok":true}' ? 'ok' : 'error';
    }

    private function file(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        self::assertNotFalse($file);
        unlink($file);
        $this->files[] = $file;

        return $file;
    }

    private function waitFor(string $file): void
    {
        $start = hrtime(true);
        while (! is_file($file)) {
            usleep(10000);
            self::assertLessThan(10000, (hrtime(true) - $start) / 1000000, 'Timed out waiting for barrier.');
        }
    }
}
