<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class AccountingRecognitionConcurrencyTest extends TestCase
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

    public function test_same_contract_competing_adoption_operations_have_one_historical_winner(): void
    {
        $context = $this->considerationContext();
        $this->adopt($context);

        $operationA = (string) Str::ulid();
        $operationB = (string) Str::ulid();

        [$holder, $directory] = $this->hold(
            $this->adoptionPayload(
                $context,
                $operationA,
                'ar_adopt_holder',
                'adopt_hold',
            ),
        );

        $competitor = $this->start(
            $this->adoptionPayload(
                $context,
                $operationB,
                'ar_adopt_competitor',
                'adopt',
            ),
        );

        $this->blocked(
            'ar_adopt_competitor',
            'ar_adopt_holder',
        );

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
            DB::table('performance_accounting_adoptions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('contract_id', $context['contract_id'])
                ->count(),
        );

        self::assertSame(
            $operationA,
            DB::table('performance_accounting_adoptions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('contract_id', $context['contract_id'])
                ->value('performance_accounting_adoption_operation_id'),
        );
    }

    public function test_adoption_first_serializes_then_allows_first_post_adoption_handover(): void
    {
        $context = $this->considerationContext();
        $this->adopt($context);
        $evidenceId = $this->evidence($context);

        [$holder, $directory] = $this->hold(
            $this->adoptionPayload(
                $context,
                (string) Str::ulid(),
                'ar_adoption_first',
                'adopt_hold',
            ),
        );

        $acceptance = $this->start(
            $this->acceptancePayload(
                $context,
                $evidenceId,
                'ar_handover_waiter',
                'acceptance',
            ),
        );

        $this->blocked(
            'ar_handover_waiter',
            'ar_adoption_first',
        );

        touch($directory.'/release');

        $adoption = $this->finish($holder);
        $handover = $this->finish($acceptance);

        self::assertTrue($adoption['ok'], json_encode($adoption));
        self::assertTrue($handover['ok'], json_encode($handover));

        $acceptanceId = $handover['result']['acceptance_id'];

        self::assertSame(
            'effective',
            DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->value('status'),
        );

        self::assertSame(
            1,
            DB::table('contract_consideration_transitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('source_type', 'UNIT_HANDOVER_ACCEPTANCE')
                ->where('source_id', $acceptanceId)
                ->where('status', 'effective')
                ->count(),
        );
    }

    public function test_handover_first_serializes_then_makes_clean_adoption_ineligible(): void
    {
        $context = $this->considerationContext();
        $this->adopt($context);
        $evidenceId = $this->evidence($context);

        [$handover, $directory] = $this->hold(
            $this->acceptancePayload(
                $context,
                $evidenceId,
                'ar_handover_first',
                'acceptance_hold',
            ),
        );

        $adoption = $this->start(
            $this->adoptionPayload(
                $context,
                (string) Str::ulid(),
                'ar_adoption_waiter',
                'adopt',
            ),
        );

        $this->blocked(
            'ar_adoption_waiter',
            'ar_handover_first',
        );

        touch($directory.'/release');

        $handoverResult = $this->finish($handover);
        $adoptionResult = $this->finish($adoption);

        self::assertTrue(
            $handoverResult['ok'],
            json_encode($handoverResult),
        );
        self::assertFalse(
            $adoptionResult['ok'],
            json_encode($adoptionResult),
        );
        self::assertSame(
            AccountingRecognitionConflict::class,
            $adoptionResult['class'],
        );

        self::assertSame(
            0,
            DB::table('performance_accounting_adoptions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('contract_id', $context['contract_id'])
                ->count(),
        );

        self::assertSame(
            1,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $context['tenant_id'])
                ->where('contract_id', $context['contract_id'])
                ->where('status', 'effective')
                ->count(),
        );
    }

    public function test_concurrent_policy_successors_serialize_into_contiguous_history(): void
    {
        $context = $this->considerationContext();
        $accounts = $this->policyAccounts($context);

        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts[0],
        );

        [$holder, $directory] = $this->hold([
            'action' => 'ar_policy_hold',
            'application_name' => 'ar_policy_v2',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-07-01',
            'account_id' => $accounts[1],
        ]);

        $successor = $this->start([
            'action' => 'ar_policy',
            'application_name' => 'ar_policy_v3',
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'effective_from' => '2026-08-01',
            'account_id' => $accounts[2],
        ]);

        $this->blocked(
            'ar_policy_v3',
            'ar_policy_v2',
        );

        touch($directory.'/release');

        $v2 = $this->finish($holder);
        $v3 = $this->finish($successor);

        self::assertTrue($v2['ok'], json_encode($v2));
        self::assertTrue($v3['ok'], json_encode($v3));

        $history = DB::table('receivable_ar_policies')
            ->where('tenant_id', $context['tenant_id'])
            ->orderBy('policy_version')
            ->get();

        self::assertSame(
            [1, 2, 3],
            $history->pluck('policy_version')
                ->map(static fn ($version): int => (int) $version)
                ->all(),
        );
        self::assertSame(
            ['2026-06-30', '2026-07-31', null],
            $history->pluck('effective_to')
                ->map(
                    static fn ($date): ?string => $date === null
                        ? null
                        : (string) $date,
                )
                ->all(),
        );
        self::assertSame(
            ['superseded', 'superseded', 'active'],
            $history->pluck('status')->all(),
        );
    }

    private function evidence(array $context): string
    {
        $id = (string) Str::ulid();
        $now = now();

        DB::table('unit_handover_evidence')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'reservation_id' => $context['reservation_id'],
            'unit_id' => $context['unit_id'],
            'customer_id' => $context['customer_id'],
            'handover_evidence_operation_id' => (string) Str::ulid(),
            'handover_evidence_reference' => 'AR/C1/'.$id,
            'readiness_reference' => 'AR/READY/'.$id,
            'readiness_effective_date' => '2026-08-19',
            'acceptance_basis' => 'explicit_customer_acceptance',
            'customer_acceptance_reference' => 'AR/ACCEPT/'.$id,
            'customer_acceptance_effective_date' => '2026-08-20',
            'effective_date' => '2026-08-20',
            'recorded_by' => $context['actor']->id,
            'recorded_at' => $now,
            'status' => 'effective',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function policyAccounts(array $context): array
    {
        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        $accounts = [];

        foreach (['ARC1', 'ARC2', 'ARC3'] as $code) {
            $accounts[] = app(ManageAccountAction::class)->create(
                $context['tenant_id'],
                $context['actor'],
                [
                    'code' => $code,
                    'name' => 'AR concurrency '.$code,
                    'description' => null,
                    'kind' => 'posting',
                    'account_type' => 'asset',
                    'classification' => 'current_asset',
                    'parent_id' => null,
                ],
            );
        }

        return $accounts;
    }

    private function adoptionPayload(
        array $context,
        string $operationId,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'contract_id' => $context['contract_id'],
            'operation_id' => $operationId,
        ];
    }

    private function acceptancePayload(
        array $context,
        string $evidenceId,
        string $applicationName,
        string $action,
    ): array {
        return [
            'action' => $action,
            'application_name' => $applicationName,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => $context['actor']->id,
            'contract_id' => $context['contract_id'],
            'evidence_id' => $evidenceId,
            'operation_id' => (string) Str::ulid(),
            'transition_operation_id' => (string) Str::ulid(),
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
                    'Accounting Recognition holder did not reach its barrier.',
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
                    'tests/Support/accounting_recognition_worker.php',
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
            "Expected {$waiter} to wait on a PostgreSQL lock held by {$holder}.",
        );
    }
}
