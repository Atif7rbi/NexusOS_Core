<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingGeneralLedgerConcurrencyTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        } parent::tearDown();
    }

    public function test_gl_c1_query_observes_complete_pre_commit_then_post_commit_journal(): void
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => TenantUser::STATUS_ACTIVE]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '1000', 'name' => 'Cash', 'description' => null, 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset', 'parent_id' => null]);
        $equity = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, ['code' => '3000', 'name' => 'Equity', 'description' => null, 'kind' => 'posting', 'account_type' => 'equity', 'classification' => 'equity', 'parent_id' => null]);
        $ready = $this->file('gl-ready-');
        $release = $this->file('gl-release-');
        $database = array_intersect_key(config('database.connections.'.DB::getDefaultConnection()), array_flip(['host', 'port', 'database', 'username', 'password', 'search_path']));
        $process = proc_open([PHP_BINARY, base_path('tests/Support/accounting_general_ledger_worker.php'), base64_encode(json_encode(compact('database', 'ready', 'release') + ['tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'cash' => $cash, 'equity' => $equity], JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->waitFor($ready);
        $before = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-01-01', '2026-12-31');
        self::assertSame([], $before['movements']);
        self::assertSame('0.00', $before['opening_balance']);
        self::assertSame('0.00', $before['closing_balance']);
        touch($release);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('{"ok":true}', trim($stdout));
        $after = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-01-01', '2026-12-31');
        self::assertCount(1, $after['movements']);
        self::assertSame('100.00', $after['movements'][0]['debit']);
        self::assertSame('100.00', $after['movements'][0]['running_balance']);
        self::assertSame('100.00', $after['closing_balance']);
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
