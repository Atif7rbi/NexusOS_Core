<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ReceivableArRecognitionConcurrencyTest extends TestCase
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
        [$context, $source] = $this->receivableArContext();
        $operationId = (string) Str::ulid();

        [$holder, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                $operationId,
                'ar_same_holder',
                'recognize_hold',
            ),
        );

        $replay = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                $operationId,
                'ar_same_waiter',
                'recognize',
            ),
        );

        $this->blocked('ar_same_waiter', 'ar_same_holder');
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
            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where(
                    'contractual_billing_entitlement_id',
                    $source['id'],
                )
                ->count(),
        );
    }

    public function test_different_operations_against_same_entitlement_have_one_root(): void
    {
        [$context, $source] = $this->receivableArContext();

        [$holder, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_root_holder',
                'recognize_hold',
            ),
        );

        $competitor = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_root_waiter',
                'recognize',
            ),
        );

        $this->blocked('ar_root_waiter', 'ar_root_holder');
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
            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where(
                    'contractual_billing_entitlement_id',
                    $source['id'],
                )
                ->count(),
        );
    }


    public function test_recognition_first_then_ar_policy_supersession_preserves_historical_snapshot(): void
    {
        [$context, $source, $accounts] = $this->receivableArContext();

        $replacementAr = $this->account(
            $context,
            'AR-CX-CTRL-2',
            'asset',
            'current_asset',
        );

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_policy_recognition_holder',
                'recognize_hold',
            ),
        );

        $policy = $this->start([
            'action' => 'ar_policy',
            'application_name' => 'ar_policy_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-21',
            'ar_control_account_id' => $replacementAr,
        ]);

        $this->blocked(
            'ar_policy_waiter',
            'ar_policy_recognition_holder',
        );
        touch($directory.'/release');

        $recognitionResult = $this->finish($recognition);
        $policyResult = $this->finish($policy);

        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertTrue($policyResult['ok'], json_encode($policyResult));

        $row = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionResult['result']['recognition_id'])
            ->first();

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->receivable_ar_policy_version);
        self::assertSame($accounts['ar'], $row->ar_control_account_id);
        self::assertSame(
            2,
            (int) DB::table('receivable_ar_policies')
                ->where('tenant_id', $context['tenant_id'])
                ->where('status', 'active')
                ->value('policy_version'),
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_ar_policy_supersession_first_then_recognition_uses_successor_snapshot(): void
    {
        [$context, $source] = $this->receivableArContext();

        $replacementAr = $this->account(
            $context,
            'AR-CX-CTRL-3',
            'asset',
            'current_asset',
        );

        [$policy, $directory] = $this->hold([
            'action' => 'ar_policy_hold',
            'application_name' => 'ar_policy_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-21',
            'ar_control_account_id' => $replacementAr,
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_after_policy_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'ar_after_policy_waiter',
            'ar_policy_first_holder',
        );
        touch($directory.'/release');

        $policyResult = $this->finish($policy);
        $recognitionResult = $this->finish($recognition);

        self::assertTrue($policyResult['ok'], json_encode($policyResult));
        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );

        $row = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionResult['result']['recognition_id'])
            ->first();

        self::assertNotNull($row);
        self::assertSame(2, (int) $row->receivable_ar_policy_version);
        self::assertSame($replacementAr, $row->ar_control_account_id);
    }

    public function test_recognition_first_then_counterpart_policy_supersession_preserves_historical_snapshot(): void
    {
        [$context, $source, $accounts] = $this->receivableArContext();

        $replacementAsset = $this->account(
            $context,
            'AR-CX-ASSET-2',
            'asset',
            'current_asset',
        );
        $replacementLiability = $this->account(
            $context,
            'AR-CX-LIAB-2',
            'liability',
            'current_liability',
        );

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_counterpart_recognition_holder',
                'recognize_hold',
            ),
        );

        $policy = $this->start([
            'action' => 'counterpart_policy',
            'application_name' => 'ar_counterpart_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-21',
            'contract_asset_account_id' => $replacementAsset,
            'contract_liability_account_id' => $replacementLiability,
        ]);

        $this->blocked(
            'ar_counterpart_waiter',
            'ar_counterpart_recognition_holder',
        );
        touch($directory.'/release');

        $recognitionResult = $this->finish($recognition);
        $policyResult = $this->finish($policy);

        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );
        self::assertTrue($policyResult['ok'], json_encode($policyResult));

        $row = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionResult['result']['recognition_id'])
            ->first();

        self::assertNotNull($row);
        self::assertSame(1, (int) $row->counterpart_policy_version);
        self::assertSame(
            $accounts['contract_liability'],
            $row->contract_liability_account_id,
        );
        self::assertSame(
            2,
            (int) DB::table('receivable_ar_counterpart_policies')
                ->where('tenant_id', $context['tenant_id'])
                ->where('status', 'active')
                ->value('policy_version'),
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_counterpart_policy_supersession_first_then_recognition_uses_successor_snapshot(): void
    {
        [$context, $source] = $this->receivableArContext();

        $replacementAsset = $this->account(
            $context,
            'AR-CX-ASSET-3',
            'asset',
            'current_asset',
        );
        $replacementLiability = $this->account(
            $context,
            'AR-CX-LIAB-3',
            'liability',
            'current_liability',
        );

        [$policy, $directory] = $this->hold([
            'action' => 'counterpart_policy_hold',
            'application_name' => 'ar_counterpart_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-21',
            'contract_asset_account_id' => $replacementAsset,
            'contract_liability_account_id' => $replacementLiability,
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_after_counterpart_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'ar_after_counterpart_waiter',
            'ar_counterpart_first_holder',
        );
        touch($directory.'/release');

        $policyResult = $this->finish($policy);
        $recognitionResult = $this->finish($recognition);

        self::assertTrue($policyResult['ok'], json_encode($policyResult));
        self::assertTrue(
            $recognitionResult['ok'],
            json_encode($recognitionResult),
        );

        $row = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionResult['result']['recognition_id'])
            ->first();

        self::assertNotNull($row);
        self::assertSame(2, (int) $row->counterpart_policy_version);
        self::assertSame(
            $replacementLiability,
            $row->contract_liability_account_id,
        );
    }

    public function test_recognition_first_then_period_close_preserves_posted_history(): void
    {
        [$context, $source, , $periodId] = $this->receivableArContext();

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_period_recognition_holder',
                'recognize_hold',
            ),
        );

        $close = $this->start([
            'action' => 'period_close',
            'application_name' => 'ar_period_close_waiter',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'period_id' => $periodId,
        ]);

        $this->blocked(
            'ar_period_close_waiter',
            'ar_period_recognition_holder',
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
                ->where('id', $periodId)
                ->value('status'),
        );
        self::assertSame(
            'posted',
            DB::table('receivable_ar_recognitions')
                ->where('id', $recognitionResult['result']['recognition_id'])
                ->value('status'),
        );
    }

    public function test_period_close_first_then_blocks_new_recognition_without_date_shift(): void
    {
        [$context, $source, , $periodId] = $this->receivableArContext();

        [$close, $directory] = $this->hold([
            'action' => 'period_close_hold',
            'application_name' => 'ar_period_first_holder',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'period_id' => $periodId,
        ]);

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_after_period_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'ar_after_period_waiter',
            'ar_period_first_holder',
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
            0,
            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where(
                    'contractual_billing_entitlement_id',
                    $source['id'],
                )
                ->count(),
        );
    }

    public function test_recognition_first_then_source_correction_reverses_ar_before_source(): void
    {
        [$context, $source, , , $scheduleId] =
            $this->receivableArContext();

        [$recognition, $directory] = $this->hold(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_source_recognition_holder',
                'recognize_hold',
            ),
        );

        $sourceCorrection = $this->start(
            $this->sourceCorrectionPayload(
                $context,
                $source['id'],
                $scheduleId,
                'ar_source_correction_waiter',
                'source_correct',
            ),
        );

        $this->blocked(
            'ar_source_correction_waiter',
            'ar_source_recognition_holder',
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
            DB::table('receivable_ar_recognitions')
                ->where('id', $recognitionResult['result']['recognition_id'])
                ->value('status'),
        );
        self::assertSame(
            'reversed',
            DB::table('contractual_billing_entitlements')
                ->where('id', $source['id'])
                ->value('status'),
        );
    }

    public function test_source_correction_first_then_blocks_new_receivable_ar_recognition(): void
    {
        [$context, $source, , , $scheduleId] =
            $this->receivableArContext();

        [$sourceCorrection, $directory] = $this->hold(
            $this->sourceCorrectionPayload(
                $context,
                $source['id'],
                $scheduleId,
                'ar_source_first_holder',
                'source_correct_hold',
            ),
        );

        $recognition = $this->start(
            $this->recognitionPayload(
                $context,
                $source['id'],
                (string) Str::ulid(),
                'ar_after_source_waiter',
                'recognize',
            ),
        );

        $this->blocked(
            'ar_after_source_waiter',
            'ar_source_first_holder',
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
            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where(
                    'contractual_billing_entitlement_id',
                    $source['id'],
                )
                ->count(),
        );
    }

    private function receivableArContext(): array
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

        $periodId = app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $ar = $this->account(
            $context,
            'AR-CX-CTRL',
            'asset',
            'current_asset',
        );
        $asset = $this->account(
            $context,
            'AR-CX-ASSET',
            'asset',
            'current_asset',
        );
        $liability = $this->account(
            $context,
            'AR-CX-LIAB',
            'liability',
            'current_liability',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $ar,
        );

        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $asset,
            $liability,
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);

            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $scheduleId = (string) DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $source['id'])
            ->value('schedule_id');

        return [$context, $source, [
            'ar' => $ar,
            'contract_asset' => $asset,
            'contract_liability' => $liability,
        ], $periodId, $scheduleId];
    }

    private function sourceCorrectionPayload(
        array $context,
        string $entitlementId,
        string $scheduleId,
        string $applicationName,
        string $action,
    ): array {
        $sourceCorrectionOperationId = (string) Str::ulid();

        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'schedule_id' => $scheduleId,
            'entitlement_id' => $entitlementId,
            'source_correction_operation_id' =>
                $sourceCorrectionOperationId,
            'entitlement_reversal_operation_id' =>
                (string) Str::ulid(),
            'reason' => 'Concurrent Receivable AR source correction',
            'reference' => 'AR-SOURCE-CORR/'.$sourceCorrectionOperationId,
        ];
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
        string $entitlementId,
        string $operationId,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'entitlement_id' => $entitlementId,
            'operation_id' => $operationId,
        ];
    }

    private function hold(array $payload): array
    {
        $directory = sys_get_temp_dir().'/nexusos_ar_'.Str::ulid();
        mkdir($directory, 0700);
        $this->barriers[] = $directory;

        $payload['ready_file'] = $directory.'/ready';
        $payload['release_file'] = $directory.'/release';

        $worker = $this->start($payload);
        $deadline = microtime(true) + 8;

        while (! is_file($directory.'/ready')) {
            if (microtime(true) > $deadline) {
                self::fail(
                    'Receivable AR holder did not reach its barrier.',
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
                base_path('tests/Support/receivable_ar_worker.php'),
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
