<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class PerformanceAccountingRecognitionConcurrencyTest extends TestCase
{
    use CreatesContractConsiderationFixtures;

    private array $workers = [];

    private array $barriers = [];

    protected function tearDown(): void
    {
        foreach ($this->barriers as $directory) {
            touch($directory.'/release');
        }

        foreach ($this->workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);

                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($process);
            }
        }

        foreach ($this->barriers as $directory) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }

            rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_same_operation_concurrent_replay_commits_one_exact_recognition(): void
    {
        [$context, $source] = $this->performanceContext();
        $operationId = (string) Str::ulid();

        [$holder, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                $operationId,
                'pa_same_holder',
                'recognize_hold',
            ),
        );

        $replay = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                $operationId,
                'pa_same_waiter',
                'recognize',
            ),
        );

        $this->blocked('pa_same_waiter', 'pa_same_holder');
        touch($directory.'/release');

        $first = $this->finish($holder);
        $second = $this->finish($replay);

        self::assertTrue($first['ok'], json_encode($first));
        self::assertTrue($second['ok'], json_encode($second));
        self::assertSame(
            $first['result']['recognition_id'],
            $second['result']['recognition_id'],
        );

        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->where('recognition_kind', 'original')
                ->count(),
        );
    }

    public function test_different_operations_against_same_handover_have_one_root(): void
    {
        [$context, $source] = $this->performanceContext();

        [$holder, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_root_holder',
                'recognize_hold',
            ),
        );

        $competitor = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_root_waiter',
                'recognize',
            ),
        );

        $this->blocked('pa_root_waiter', 'pa_root_holder');
        touch($directory.'/release');

        $winner = $this->finish($holder);
        $loser = $this->finish($competitor);

        self::assertTrue($winner['ok'], json_encode($winner));
        self::assertFalse($loser['ok'], json_encode($loser));
        self::assertSame(
            AccountingRecognitionConflict::class,
            $loser['class'],
        );

        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->where('recognition_kind', 'original')
                ->count(),
        );
    }

    public function test_same_correction_operation_concurrent_replay_has_one_successor(): void
    {
        [$context, $source, $accounts] = $this->performanceContext();
        $rootId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $correctedAsset = $this->account(
            $context,
            'PA-CXC-A',
            'asset',
            'current_asset',
        );
        $correctedRevenue = $this->account(
            $context,
            'PA-CXC-R',
            'revenue',
            'operating_revenue',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-08-01',
            $correctedRevenue,
            $correctedAsset,
            $accounts['contract_liability'],
        );

        $operationId = (string) Str::ulid();
        $payload = [
            'action' => 'correct_hold',
            'application_name' => 'pa_corr_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'acceptance_id' => $source['id'],
            'operation_id' => $operationId,
            'reason' => 'Concurrent accounting correction',
            'reference' => 'PA-CORR-CONCURRENT',
        ];

        [$holder, $directory] = $this->hold($payload);

        $payload['action'] = 'correct';
        $payload['application_name'] = 'pa_corr_waiter';
        $replay = $this->start($payload);

        $this->blocked('pa_corr_waiter', 'pa_corr_holder');
        touch($directory.'/release');

        $first = $this->finish($holder);
        $second = $this->finish($replay);

        self::assertTrue($first['ok'], json_encode($first));
        self::assertTrue($second['ok'], json_encode($second));
        self::assertSame(
            $first['result']['recognition_id'],
            $second['result']['recognition_id'],
        );

        self::assertSame(
            2,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('root_recognition_id', $rootId)
                ->count(),
        );
        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('root_recognition_id', $rootId)
                ->where('status', 'posted')
                ->count(),
        );
    }

    public function test_recognition_serializes_against_policy_supersession_without_reinterpreting_history(): void
    {
        [$context, $source, $accounts] = $this->performanceContext();
        $operationId = (string) Str::ulid();

        $correctedAsset = $this->account(
            $context,
            'PA-POL-A',
            'asset',
            'current_asset',
        );
        $correctedRevenue = $this->account(
            $context,
            'PA-POL-R',
            'revenue',
            'operating_revenue',
        );

        [$holder, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                $operationId,
                'pa_policy_holder',
                'recognize_hold',
            ),
        );

        $policy = $this->start([
            'action' => 'performance_policy',
            'application_name' => 'pa_policy_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-01',
            'revenue_account_id' => $correctedRevenue,
            'contract_asset_account_id' => $correctedAsset,
            'contract_liability_account_id' => $accounts['contract_liability'],
        ]);

        $this->blocked('pa_policy_waiter', 'pa_policy_holder');
        touch($directory.'/release');

        $recognition = $this->finish($holder);
        $supersession = $this->finish($policy);

        self::assertTrue($recognition['ok'], json_encode($recognition));
        self::assertTrue($supersession['ok'], json_encode($supersession));

        $row = DB::table('performance_accounting_recognitions')
            ->where(
                'id',
                $recognition['result']['recognition_id'],
            )
            ->first();

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->performance_accounting_policy_version);
        self::assertSame($accounts['contract_asset'], $row->contract_asset_account_id);
        self::assertSame($accounts['revenue'], $row->revenue_account_id);

        self::assertSame(
            2,
            (int) DB::table('performance_accounting_policies')
                ->where('tenant_id', $context['tenant_id'])
                ->where('status', 'active')
                ->value('policy_version'),
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_recognition_first_serializes_then_source_correction_reverses_accounting_before_source(): void
    {
        [$context, $source] = $this->performanceContext();

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_source_recognition_holder',
                'recognize_hold',
            ),
        );

        $sourceCorrection = $this->start([
            'action' => 'source_reverse',
            'application_name' => 'pa_source_correction_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'acceptance_id' => $source['id'],
            'operation_id' => (string) Str::ulid(),
            'reason' => 'Concurrent source correction',
            'reference' => 'PA-SOURCE-CORR-001',
        ]);

        $this->blocked(
            'pa_source_correction_waiter',
            'pa_source_recognition_holder',
        );
        touch($directory.'/release');

        $recognitionResult = $this->finish($recognition);
        $correctionResult = $this->finish($sourceCorrection);

        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertTrue(
            $correctionResult['ok'],
            json_encode($correctionResult),
        );

        self::assertSame(
            'reversed',
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $source['id'])
                ->value('status'),
        );
        self::assertSame(
            0,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->where('status', 'posted')
                ->count(),
        );
        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->where('status', 'reversed')
                ->count(),
        );
    }

    public function test_source_correction_first_serializes_then_blocks_new_performance_recognition(): void
    {
        [$context, $source] = $this->performanceContext();

        [$sourceCorrection, $directory] = $this->hold([
            'action' => 'source_reverse_hold',
            'application_name' => 'pa_source_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'acceptance_id' => $source['id'],
            'operation_id' => (string) Str::ulid(),
            'reason' => 'Source correction wins',
            'reference' => 'PA-SOURCE-FIRST-001',
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_recognition_after_source',
                'recognize',
            ),
        );

        $this->blocked(
            'pa_recognition_after_source',
            'pa_source_first_holder',
        );
        touch($directory.'/release');

        $correctionResult = $this->finish($sourceCorrection);
        $recognitionResult = $this->finish($recognition);

        self::assertTrue(
            $correctionResult['ok'],
            json_encode($correctionResult),
        );
        self::assertFalse(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $recognitionResult['class'],
        );

        self::assertSame(
            0,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->count(),
        );
    }

    public function test_recognition_first_serializes_then_period_close_preserves_posted_history(): void
    {
        [$context, $source, , $periodId] = $this->performanceContext();

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_period_recognition_holder',
                'recognize_hold',
            ),
        );

        $close = $this->start([
            'action' => 'period_close',
            'application_name' => 'pa_period_close_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'period_id' => $periodId,
        ]);

        $this->blocked(
            'pa_period_close_waiter',
            'pa_period_recognition_holder',
        );
        touch($directory.'/release');

        $recognitionResult = $this->finish($recognition);
        $closeResult = $this->finish($close);

        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertTrue($closeResult['ok'], json_encode($closeResult));

        self::assertSame(
            'closed',
            DB::table('accounting_periods')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $periodId)
                ->value('status'),
        );
        self::assertSame(
            'posted',
            DB::table('performance_accounting_recognitions')
                ->where(
                    'id',
                    $recognitionResult['result']['recognition_id'],
                )
                ->value('status'),
        );
    }

    public function test_period_close_first_serializes_then_blocks_new_recognition_without_date_shift(): void
    {
        [$context, $source, , $periodId] = $this->performanceContext();

        [$close, $directory] = $this->hold([
            'action' => 'period_close_hold',
            'application_name' => 'pa_period_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'period_id' => $periodId,
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_recognition_after_period',
                'recognize',
            ),
        );

        $this->blocked(
            'pa_recognition_after_period',
            'pa_period_first_holder',
        );
        touch($directory.'/release');

        $closeResult = $this->finish($close);
        $recognitionResult = $this->finish($recognition);

        self::assertTrue($closeResult['ok'], json_encode($closeResult));
        self::assertFalse(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $recognitionResult['class'],
        );
        self::assertSame(
            0,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->count(),
        );
    }

    public function test_recognition_first_serializes_then_account_archive_preserves_historical_snapshot(): void
    {
        [$context, $source, $accounts] = $this->performanceContext();

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_account_recognition_holder',
                'recognize_hold',
            ),
        );

        $archive = $this->start([
            'action' => 'account_archive',
            'application_name' => 'pa_account_archive_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'account_id' => $accounts['revenue'],
        ]);

        $this->blocked(
            'pa_account_archive_waiter',
            'pa_account_recognition_holder',
        );
        touch($directory.'/release');

        $recognitionResult = $this->finish($recognition);
        $archiveResult = $this->finish($archive);

        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertTrue($archiveResult['ok'], json_encode($archiveResult));

        self::assertSame(
            'archived',
            DB::table('accounts')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $accounts['revenue'])
                ->value('status'),
        );

        $row = DB::table('performance_accounting_recognitions')
            ->where(
                'id',
                $recognitionResult['result']['recognition_id'],
            )
            ->first();

        self::assertNotNull($row);
        self::assertSame($accounts['revenue'], $row->revenue_account_id);
        self::assertSame('posted', $row->status);
    }

    public function test_account_archive_first_serializes_then_blocks_new_recognition(): void
    {
        [$context, $source, $accounts] = $this->performanceContext();

        [$archive, $directory] = $this->hold([
            'action' => 'account_archive_hold',
            'application_name' => 'pa_account_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'account_id' => $accounts['revenue'],
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'pa_recognition_after_account',
                'recognize',
            ),
        );

        $this->blocked(
            'pa_recognition_after_account',
            'pa_account_first_holder',
        );
        touch($directory.'/release');

        $archiveResult = $this->finish($archive);
        $recognitionResult = $this->finish($recognition);

        self::assertTrue($archiveResult['ok'], json_encode($archiveResult));
        self::assertFalse(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $recognitionResult['class'],
        );
        self::assertSame(
            0,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->count(),
        );
    }

    private function performanceContext(): array
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);

        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        $periodId = app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $accounts = [
            'contract_asset' => $this->account(
                $context,
                'PA-CX-A',
                'asset',
                'current_asset',
            ),
            'contract_liability' => $this->account(
                $context,
                'PA-CX-L',
                'liability',
                'current_liability',
            ),
            'revenue' => $this->account(
                $context,
                'PA-CX-R',
                'revenue',
                'operating_revenue',
            ),
        ];

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['revenue'],
            $accounts['contract_asset'],
            $accounts['contract_liability'],
        );

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
        ): array {
            $source = $this->handoverSource($context);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source];
        });

        return [$context, $source, $accounts, $periodId];
    }

    private function account(
        array $context,
        string $code,
        string $type,
        string $classification,
    ): string {
        return app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => $code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );
    }

    private function recognitionPayload(
        array $context,
        string $acceptanceId,
        string $operationId,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'acceptance_id' => $acceptanceId,
            'operation_id' => $operationId,
        ];
    }

    private function hold(array $payload): array
    {
        $directory = sys_get_temp_dir().'/nexusos_pa_'.Str::ulid();
        mkdir($directory, 0700);
        $this->barriers[] = $directory;

        $payload['ready_file'] = $directory.'/ready';
        $payload['release_file'] = $directory.'/release';

        $worker = $this->start($payload);
        $deadline = microtime(true) + 8;

        while (! is_file($directory.'/ready')) {
            if (microtime(true) > $deadline) {
                self::fail(
                    'Performance Accounting holder did not reach its barrier.',
                );
            }

            usleep(10_000);
        }

        return [$worker, $directory];
    }

    private function start(array $payload): int
    {
        $connection = config(
            'database.connections.'.DB::getDefaultConnection(),
        );

        $payload['database'] = array_intersect_key(
            $connection,
            array_flip([
                'host',
                'port',
                'database',
                'username',
                'password',
            ]),
        );

        $process = proc_open(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/performance_accounting_worker.php',
                ),
                base64_encode(
                    json_encode($payload, JSON_THROW_ON_ERROR),
                ),
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);

        $this->workers[] = [$process, $pipes];

        return array_key_last($this->workers);
    }

    private function finish(int $index): array
    {
        [$process, $pipes] = $this->workers[$index];

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        unset($this->workers[$index]);

        self::assertSame(0, $status, $stderr);

        return json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function blocked(
        string $waiter,
        string $holder,
    ): void {
        $deadline = microtime(true) + 6;

        do {
            $row = DB::selectOne(
                <<<'SQL'
                    SELECT EXISTS (
                      SELECT 1
                      FROM pg_stat_activity waiter
                      CROSS JOIN pg_stat_activity holder
                      WHERE waiter.application_name=?
                        AND holder.application_name=?
                        AND waiter.wait_event_type='Lock'
                        AND holder.pid=ANY(pg_blocking_pids(waiter.pid))
                    ) AS blocked
                    SQL,
                [$waiter, $holder],
            );

            if ((bool) $row->blocked) {
                self::assertTrue(true);

                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail(
            "Expected {$waiter} to wait on PostgreSQL lock held by {$holder}.",
        );
    }
}
