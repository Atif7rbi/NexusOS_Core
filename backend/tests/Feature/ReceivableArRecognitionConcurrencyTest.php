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

        app(ManageAccountingPeriodAction::class)->create(
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

        return [$context, $source];
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
