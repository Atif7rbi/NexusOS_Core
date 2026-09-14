<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationAccessDenied;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationConcurrencyTest extends TestCase
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
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_same_operation_waits_then_returns_original_ids(): void
    {
        $c = $this->considerationContext();
        [$holder, $directory] = $this->hold($c, 'adopt_hold');
        $second = $this->start($c, 'adopt', 'cc_second');
        $this->blocked('cc_second', 'cc_holder');
        touch($directory.'/release');
        $a = $this->finish($holder);
        $b = $this->finish($second);
        self::assertTrue($a['ok'], json_encode($a));
        self::assertTrue($b['ok'], json_encode($b));
        self::assertSame($a['result'], $b['result']);
        self::assertSame(1, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
        self::assertSame(1, DB::table('contract_consideration_lots')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_competing_operations_have_one_historical_winner(): void
    {
        $c = $this->considerationContext();
        [$holder, $directory] = $this->hold($c, 'adopt_hold');
        $second = $this->start(array_replace($c, ['operation_id' => (string) Str::ulid()]), 'adopt', 'cc_second');
        $this->blocked('cc_second', 'cc_holder');
        touch($directory.'/release');
        self::assertTrue($this->finish($holder)['ok']);
        $loser = $this->finish($second);
        self::assertFalse($loser['ok']);
        self::assertSame(ContractConsiderationConflict::class, $loser['class']);
        self::assertSame($c['operation_id'], DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->value('coordination_adoption_operation_id'));
    }

    public function test_contract_lock_serializes_capacity_snapshot(): void
    {
        $c = $this->considerationContext();
        [$holder, $directory] = $this->hold($c, 'change_capacity');
        $adoption = $this->start($c, 'adopt', 'cc_adoption');
        $this->blocked('cc_adoption', 'cc_holder');
        touch($directory.'/release');
        self::assertTrue($this->finish($holder)['ok']);
        self::assertTrue($this->finish($adoption)['ok']);
        self::assertSame('1200.00', DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->value('consideration_amount'));
        self::assertSame('1200.00', DB::table('contract_consideration_lots')->where('tenant_id', $c['tenant_id'])->value('amount'));
    }

    public function test_membership_change_wins_before_authorization_lock(): void
    {
        $c = $this->considerationContext();
        [$holder, $directory] = $this->hold($c, 'suspend_membership');
        $adoption = $this->start($c, 'adopt', 'cc_adoption');
        $this->blocked('cc_adoption', 'cc_holder');
        touch($directory.'/release');
        self::assertTrue($this->finish($holder)['ok']);
        $result = $this->finish($adoption);
        self::assertFalse($result['ok']);
        self::assertSame(ContractConsiderationAccessDenied::class, $result['class']);
        self::assertSame(0, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_source_activation_wins_and_clean_adoption_fails(): void
    {
        $c = $this->considerationContext();
        $c['obligation_id'] = $this->billingObligations($c)[0];
        [$holder, $directory] = $this->hold($c, 'billing_hold');
        $adoption = $this->start($c, 'adopt', 'cc_adoption');
        $this->blocked('cc_adoption', 'cc_holder');
        touch($directory.'/release');
        self::assertTrue($this->finish($holder)['ok']);
        $result = $this->finish($adoption);
        self::assertFalse($result['ok']);
        self::assertSame(ContractConsiderationConflict::class, $result['class']);
        self::assertSame(0, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_adoption_wins_and_uncoordinated_source_cannot_commit(): void
    {
        $c = $this->considerationContext();
        $c['obligation_id'] = $this->billingObligations($c)[0];
        [$holder, $directory] = $this->hold($c, 'adopt_hold');
        $source = $this->start($c, 'billing', 'cc_source');
        $this->blocked('cc_source', 'cc_holder');
        touch($directory.'/release');
        self::assertTrue($this->finish($holder)['ok']);
        self::assertFalse($this->finish($source)['ok']);
        self::assertSame(0, DB::table('contractual_billing_entitlements')->where('tenant_id', $c['tenant_id'])->count());
        self::assertSame(1, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
    }

    private function hold(array $context, string $action): array
    {
        $directory = sys_get_temp_dir().'/nexusos_cc_'.Str::ulid();
        mkdir($directory, 0700);
        $this->barriers[] = $directory;
        $worker = $this->start($context, $action, 'cc_holder', ['ready' => $directory.'/ready', 'release' => $directory.'/release']);
        $deadline = microtime(true) + 5;
        while (! is_file($directory.'/ready')) {
            if (microtime(true) > $deadline) {
                self::fail('Holder did not reach the database barrier.');
            }
            usleep(10_000);
        }

        return [$worker, $directory];
    }

    private function start(array $context, string $action, string $name, array $extra = []): int
    {
        $payload = ['action' => $action, 'name' => $name, 'actor_id' => $context['actor']->id,
            'tenant_id' => $context['tenant_id'], 'contract_id' => $context['contract_id'],
            'operation_id' => $context['operation_id'], 'obligation_id' => $context['obligation_id'] ?? null] + $extra;
        $process = proc_open([PHP_BINARY, base_path('tests/Support/contract_consideration_worker.php'),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    private function blocked(string $waiter, string $holder): void
    {
        $deadline = microtime(true) + 4;
        do {
            $row = DB::selectOne(<<<'SQL'
                SELECT EXISTS (
                  SELECT 1 FROM pg_stat_activity w, pg_stat_activity h
                  WHERE w.application_name = ? AND h.application_name = ?
                    AND w.wait_event_type = 'Lock' AND h.pid = ANY(pg_blocking_pids(w.pid))
                ) AS blocked
                SQL, [$waiter, $holder]);
            if ((bool) $row->blocked) {
                self::assertTrue(true);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('Expected PostgreSQL lock wait was not observed.');
    }
}
