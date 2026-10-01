<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Queries\TrialBalanceQuery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingTrialBalanceConcurrencyTest extends TestCase
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

    public function test_tb_c1_report_observes_complete_pre_or_post_commit_ledger_snapshots(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = $this->account($tenant, $actor, '1000', 'asset', 'current_asset');
        $equity = $this->account($tenant, $actor, '3000', 'equity', 'equity');

        $ready = $this->file('trial-balance-worker-ready-');
        $release = $this->file('trial-balance-worker-release-');
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $database = array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));

        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/accounting_trial_balance_worker.php'),
            base64_encode(json_encode([
                'database' => $database,
                'tenant_id' => (string) $tenant->id,
                'actor_id' => $actor->id,
                'debit_account_id' => $cash,
                'credit_account_id' => $equity,
                'ready_file' => $ready,
                'release_file' => $release,
            ], JSON_THROW_ON_ERROR)),
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->waitFor($ready);

        $before = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-12-31');
        self::assertSame('0.00', $before['debit_total']);
        self::assertSame('0.00', $before['credit_total']);
        self::assertTrue($before['is_balanced']);

        touch($release);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('{"ok":true}', trim($stdout));

        $after = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-12-31');
        self::assertSame('100.00', $after['debit_total']);
        self::assertSame('100.00', $after['credit_total']);
        self::assertTrue($after['is_balanced']);
    }

    private function account(Tenant $tenant, User $actor, string $code, string $type, string $classification): string
    {
        return app(ManageAccountAction::class)->create((string) $tenant->id, $actor, [
            'code' => $code, 'name' => 'Concurrency '.$code, 'description' => null, 'kind' => 'posting',
            'account_type' => $type, 'classification' => $classification, 'parent_id' => null,
        ]);
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
        $started = hrtime(true);
        while (! is_file($file)) {
            usleep(10_000);
            self::assertLessThan(10_000, (hrtime(true) - $started) / 1_000_000, 'Timed out waiting for worker barrier.');
        }
    }
}
