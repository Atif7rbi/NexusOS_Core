<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class GlArCrossSliceIntegrationConcurrencyTest extends TestCase
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

    public function test_billing_first_ar_commit_serializes_performance_into_exact_liability_origin(): void
    {
        $fixture = $this->billingFirstContext();

        [$arWorker, $directory] = $this->hold(
            'receivable_ar_worker.php',
            $this->arRecognitionPayload(
                $fixture,
                'xsi_billing_ar_holder',
                'recognize_hold',
            ),
        );

        $performanceWorker = $this->start(
            'performance_accounting_worker.php',
            $this->performanceRecognitionPayload(
                $fixture,
                'xsi_billing_pa_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'xsi_billing_pa_waiter',
            'xsi_billing_ar_holder',
        );
        touch($directory.'/release');

        $arResult = $this->finish($arWorker);
        $performanceResult = $this->finish($performanceWorker);

        self::assertTrue($arResult['ok'], json_encode($arResult));
        self::assertTrue(
            $performanceResult['ok'],
            json_encode($performanceResult),
        );

        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $fixture['context']['tenant_id'])
            ->where('origin_recognition_type', 'RECEIVABLE_AR_RECOGNITION')
            ->where(
                'origin_recognition_id',
                $arResult['result']['recognition_id'],
            )
            ->where('position_type', 'CONTRACT_LIABILITY')
            ->first();

        self::assertNotNull($origin);

        self::assertDatabaseHas('accounting_position_consumptions', [
            'tenant_id' => $fixture['context']['tenant_id'],
            'origin_id' => $origin->id,
            'consuming_recognition_type' =>
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            'consuming_recognition_id' =>
                $performanceResult['result']['recognition_id'],
            'amount' => '1000.00',
            'status' => 'effective',
        ]);
    }

    public function test_performance_first_commit_serializes_ar_into_exact_asset_origin(): void
    {
        $fixture = $this->performanceFirstContext();

        [$performanceWorker, $directory] = $this->hold(
            'performance_accounting_worker.php',
            $this->performanceRecognitionPayload(
                $fixture,
                'xsi_perf_pa_holder',
                'recognize_hold',
            ),
        );

        $arWorker = $this->start(
            'receivable_ar_worker.php',
            $this->arRecognitionPayload(
                $fixture,
                'xsi_perf_ar_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'xsi_perf_ar_waiter',
            'xsi_perf_pa_holder',
        );
        touch($directory.'/release');

        $performanceResult = $this->finish($performanceWorker);
        $arResult = $this->finish($arWorker);

        self::assertTrue(
            $performanceResult['ok'],
            json_encode($performanceResult),
        );
        self::assertTrue($arResult['ok'], json_encode($arResult));

        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $fixture['context']['tenant_id'])
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where(
                'origin_recognition_id',
                $performanceResult['result']['recognition_id'],
            )
            ->where('position_type', 'CONTRACT_ASSET')
            ->first();

        self::assertNotNull($origin);

        self::assertDatabaseHas('accounting_position_consumptions', [
            'tenant_id' => $fixture['context']['tenant_id'],
            'origin_id' => $origin->id,
            'consuming_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
            'consuming_recognition_id' =>
                $arResult['result']['recognition_id'],
            'amount' => '1000.00',
            'status' => 'effective',
        ]);
    }

    public function test_performance_consumption_commit_blocks_billing_source_correction(): void
    {
        $fixture = $this->billingFirstContext();

        $arId = app(RecognizeReceivableAr::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'contractual_billing_entitlement_id' =>
                    $fixture['billing']['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        [$performanceWorker, $directory] = $this->hold(
            'performance_accounting_worker.php',
            $this->performanceRecognitionPayload(
                $fixture,
                'xsi_ar_corr_pa_holder',
                'recognize_hold',
            ),
        );

        $correctionWorker = $this->start(
            'receivable_ar_worker.php',
            $this->billingCorrectionPayload(
                $fixture,
                'xsi_ar_corr_waiter',
            ),
        );

        $this->blocked(
            'xsi_ar_corr_waiter',
            'xsi_ar_corr_pa_holder',
        );
        touch($directory.'/release');

        $performanceResult = $this->finish($performanceWorker);
        $correctionResult = $this->finish($correctionWorker);

        self::assertTrue(
            $performanceResult['ok'],
            json_encode($performanceResult),
        );
        self::assertFalse(
            $correctionResult['ok'],
            json_encode($correctionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $correctionResult['class'],
        );

        self::assertSame(
            'posted',
            DB::table('receivable_ar_recognitions')
                ->where('id', $arId)
                ->value('status'),
        );
        self::assertSame(
            'posted',
            DB::table('performance_accounting_recognitions')
                ->where(
                    'id',
                    $performanceResult['result']['recognition_id'],
                )
                ->value('status'),
        );
        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where('id', $fixture['billing']['id'])
                ->value('status'),
        );
    }

    public function test_ar_consumption_commit_blocks_performance_source_reversal(): void
    {
        $fixture = $this->performanceFirstContext();

        $performanceId = app(RecognizePerformanceAccounting::class)
            ->execute(
                $fixture['context']['tenant_id'],
                $fixture['context']['actor'],
                [
                    'unit_handover_acceptance_id' =>
                        $fixture['handover']['id'],
                    'performance_accounting_operation_id' =>
                        (string) Str::ulid(),
                ],
            );

        [$arWorker, $directory] = $this->hold(
            'receivable_ar_worker.php',
            $this->arRecognitionPayload(
                $fixture,
                'xsi_pa_rev_ar_holder',
                'recognize_hold',
            ),
        );

        $reverseWorker = $this->start(
            'performance_accounting_worker.php',
            $this->performanceSourceReversePayload(
                $fixture,
                'xsi_pa_rev_waiter',
            ),
        );

        $this->blocked(
            'xsi_pa_rev_waiter',
            'xsi_pa_rev_ar_holder',
        );
        touch($directory.'/release');

        $arResult = $this->finish($arWorker);
        $reverseResult = $this->finish($reverseWorker);

        self::assertTrue($arResult['ok'], json_encode($arResult));
        self::assertFalse(
            $reverseResult['ok'],
            json_encode($reverseResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $reverseResult['class'],
        );

        self::assertSame(
            'posted',
            DB::table('performance_accounting_recognitions')
                ->where('id', $performanceId)
                ->value('status'),
        );
        self::assertSame(
            'posted',
            DB::table('receivable_ar_recognitions')
                ->where('id', $arResult['result']['recognition_id'])
                ->value('status'),
        );
        self::assertSame(
            'effective',
            DB::table('unit_handover_acceptances')
                ->where('id', $fixture['handover']['id'])
                ->value('status'),
        );
    }

    public function test_ar_downstream_commit_blocks_performance_accounting_correction(): void
    {
        $fixture = $this->performanceFirstContext();

        $rootId = app(RecognizePerformanceAccounting::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'unit_handover_acceptance_id' =>
                    $fixture['handover']['id'],
                'performance_accounting_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $this->supersedePerformancePolicy($fixture);

        [$arWorker, $directory] = $this->hold(
            'receivable_ar_worker.php',
            $this->arRecognitionPayload(
                $fixture,
                'xsi_pa_corr_ar_holder',
                'recognize_hold',
            ),
        );

        $correctionWorker = $this->start(
            'performance_accounting_worker.php',
            $this->performanceCorrectionPayload(
                $fixture,
                'xsi_pa_corr_waiter',
                'correct',
            ),
        );

        $this->blocked(
            'xsi_pa_corr_waiter',
            'xsi_pa_corr_ar_holder',
        );
        touch($directory.'/release');

        $arResult = $this->finish($arWorker);
        $correctionResult = $this->finish($correctionWorker);

        self::assertTrue($arResult['ok'], json_encode($arResult));
        self::assertFalse(
            $correctionResult['ok'],
            json_encode($correctionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $correctionResult['class'],
        );

        self::assertSame(
            'posted',
            DB::table('performance_accounting_recognitions')
                ->where('id', $rootId)
                ->value('status'),
        );
        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('root_recognition_id', $rootId)
                ->count(),
        );
    }

    public function test_performance_correction_commit_then_ar_consumes_successor_asset_origin(): void
    {
        $fixture = $this->performanceFirstContext();

        $rootId = app(RecognizePerformanceAccounting::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'unit_handover_acceptance_id' =>
                    $fixture['handover']['id'],
                'performance_accounting_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $this->supersedePerformancePolicy($fixture);

        [$correctionWorker, $directory] = $this->hold(
            'performance_accounting_worker.php',
            $this->performanceCorrectionPayload(
                $fixture,
                'xsi_corr_first_holder',
                'correct_hold',
            ),
        );

        $arWorker = $this->start(
            'receivable_ar_worker.php',
            $this->arRecognitionPayload(
                $fixture,
                'xsi_corr_first_ar_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'xsi_corr_first_ar_waiter',
            'xsi_corr_first_holder',
        );
        touch($directory.'/release');

        $correctionResult = $this->finish($correctionWorker);
        $arResult = $this->finish($arWorker);

        self::assertTrue(
            $correctionResult['ok'],
            json_encode($correctionResult),
        );
        self::assertTrue($arResult['ok'], json_encode($arResult));

        $successorId = $correctionResult['result']['recognition_id'];

        self::assertNotSame($rootId, $successorId);
        self::assertSame(
            'reversed',
            DB::table('performance_accounting_recognitions')
                ->where('id', $rootId)
                ->value('status'),
        );
        self::assertSame(
            'posted',
            DB::table('performance_accounting_recognitions')
                ->where('id', $successorId)
                ->value('status'),
        );

        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $fixture['context']['tenant_id'])
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('origin_recognition_id', $successorId)
            ->where('position_type', 'CONTRACT_ASSET')
            ->first();

        self::assertNotNull($origin);

        self::assertDatabaseHas('accounting_position_consumptions', [
            'tenant_id' => $fixture['context']['tenant_id'],
            'origin_id' => $origin->id,
            'consuming_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
            'consuming_recognition_id' =>
                $arResult['result']['recognition_id'],
            'status' => 'effective',
            'amount' => '1000.00',
        ]);
    }

    private function billingFirstContext(): array
    {
        $fixture = $this->baseContext();

        [$billing, $billingGraph] = DB::transaction(function () use (
            $fixture,
        ): array {
            $billing = $this->billingSource(
                $fixture['context'],
                $fixture['obligation_id'],
            );
            $graph = $this->transition(
                $fixture['context'],
                $fixture['consideration'],
                $billing,
                $fixture['consideration']['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$billing, $graph];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $fixture['context']['tenant_id'],
            $billing['id'],
            $fixture['context']['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        [$handover, $handoverGraph] = DB::transaction(function () use (
            $fixture,
            $billingGraph,
        ): array {
            $handover = $this->handoverSource($fixture['context']);
            $graph = $this->transition(
                $fixture['context'],
                $fixture['consideration'],
                $handover,
                $billingGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$handover, $graph];
        });

        $scheduleId = (string) DB::table(
            'contractual_billing_entitlements',
        )
            ->where('id', $billing['id'])
            ->value('schedule_id');

        return $fixture + [
            'billing' => $billing,
            'billing_graph' => $billingGraph,
            'handover' => $handover,
            'handover_graph' => $handoverGraph,
            'schedule_id' => $scheduleId,
        ];
    }

    private function performanceFirstContext(): array
    {
        $fixture = $this->baseContext();

        [$handover, $handoverGraph] = DB::transaction(function () use (
            $fixture,
        ): array {
            $handover = $this->handoverSource($fixture['context']);
            $graph = $this->transition(
                $fixture['context'],
                $fixture['consideration'],
                $handover,
                $fixture['consideration']['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$handover, $graph];
        });

        [$billing, $billingGraph] = DB::transaction(function () use (
            $fixture,
            $handoverGraph,
        ): array {
            $billing = $this->billingSource(
                $fixture['context'],
                $fixture['obligation_id'],
            );
            $graph = $this->transition(
                $fixture['context'],
                $fixture['consideration'],
                $billing,
                $handoverGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$billing, $graph];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $fixture['context']['tenant_id'],
            $billing['id'],
            $fixture['context']['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        return $fixture + [
            'billing' => $billing,
            'billing_graph' => $billingGraph,
            'handover' => $handover,
            'handover_graph' => $handoverGraph,
        ];
    }

    private function baseContext(): array
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);

        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $accounts = [
            'ar' => $this->account(
                $context,
                'XSI-AR',
                'asset',
                'current_asset',
            ),
            'contract_asset' => $this->account(
                $context,
                'XSI-CA',
                'asset',
                'current_asset',
            ),
            'contract_liability' => $this->account(
                $context,
                'XSI-CL',
                'liability',
                'current_liability',
            ),
            'revenue' => $this->account(
                $context,
                'XSI-REV',
                'revenue',
                'operating_revenue',
            ),
        ];

        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['ar'],
        );

        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['contract_asset'],
            $accounts['contract_liability'],
        );

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
                'performance_accounting_adoption_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return [
            'context' => $context,
            'consideration' => $consideration,
            'obligation_id' => $obligationId,
            'accounts' => $accounts,
        ];
    }

    private function supersedePerformancePolicy(array &$fixture): void
    {
        $fixture['corrected_asset'] = $this->account(
            $fixture['context'],
            'XSI-CA-2',
            'asset',
            'current_asset',
        );
        $fixture['corrected_revenue'] = $this->account(
            $fixture['context'],
            'XSI-REV-2',
            'revenue',
            'operating_revenue',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            '2026-08-01',
            $fixture['corrected_revenue'],
            $fixture['corrected_asset'],
            $fixture['accounts']['contract_liability'],
        );
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

    private function arRecognitionPayload(
        array $fixture,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'entitlement_id' => $fixture['billing']['id'],
            'operation_id' => (string) Str::ulid(),
        ];
    }

    private function performanceRecognitionPayload(
        array $fixture,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'acceptance_id' => $fixture['handover']['id'],
            'operation_id' => (string) Str::ulid(),
        ];
    }

    private function billingCorrectionPayload(
        array $fixture,
        string $applicationName,
    ): array {
        $sourceCorrectionOperationId = (string) Str::ulid();

        return [
            'action' => 'source_correct',
            'application_name' => $applicationName,
            'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'schedule_id' => $fixture['schedule_id'],
            'entitlement_id' => $fixture['billing']['id'],
            'source_correction_operation_id' =>
                $sourceCorrectionOperationId,
            'entitlement_reversal_operation_id' =>
                (string) Str::ulid(),
            'reason' => 'Cross-slice billing source correction',
            'reference' => 'XSI/BILLING/'.$sourceCorrectionOperationId,
        ];
    }

    private function performanceSourceReversePayload(
        array $fixture,
        string $applicationName,
    ): array {
        $operationId = (string) Str::ulid();

        return [
            'action' => 'source_reverse',
            'application_name' => $applicationName,
            'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'acceptance_id' => $fixture['handover']['id'],
            'operation_id' => $operationId,
            'reason' => 'Cross-slice performance source reversal',
            'reference' => 'XSI/PERFORMANCE/'.$operationId,
        ];
    }

    private function performanceCorrectionPayload(
        array $fixture,
        string $applicationName,
        string $action,
    ): array {
        $operationId = (string) Str::ulid();

        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'acceptance_id' => $fixture['handover']['id'],
            'operation_id' => $operationId,
            'reason' => 'Cross-slice Performance Accounting correction',
            'reference' => 'XSI/PA-CORR/'.$operationId,
        ];
    }

    private function hold(
        string $workerScript,
        array $payload,
    ): array {
        $directory = sys_get_temp_dir().'/nexusos_xsi_'.Str::ulid();
        mkdir($directory, 0700);
        $this->barriers[] = $directory;

        $payload['ready_file'] = $directory.'/ready';
        $payload['release_file'] = $directory.'/release';

        $worker = $this->start($workerScript, $payload);
        $deadline = microtime(true) + 8;

        while (! is_file($directory.'/ready')) {
            if (microtime(true) > $deadline) {
                self::fail(
                    'Cross-slice holder did not reach its barrier.',
                );
            }

            usleep(10_000);
        }

        return [$worker, $directory];
    }

    private function start(
        string $workerScript,
        array $payload,
    ): int {
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
                base_path('tests/Support/'.$workerScript),
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
