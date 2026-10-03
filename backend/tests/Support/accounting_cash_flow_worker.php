<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageCashFlowSemanticsAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$p = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace(config('database.connections.pgsql'), $p['database'])]);
DB::purge('pgsql');
try {
    DB::transaction(function () use ($p): void {
        if (($p['mode'] ?? 'journal') === 'assign_role') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            DB::table('account_cash_roles')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $p['tenantId'],
                'account_id' => $p['accountId'],
                'role' => 'cash',
                'assignment_operation_id' => (string) Str::ulid(),
                'assigned_by' => $p['actorId'],
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (($p['mode'] ?? 'journal') === 'assign_role_action') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            $actor = User::query()->findOrFail($p['actorId']);
            app(ManageCashFlowSemanticsAction::class)->assignCashRole($p['tenantId'], $p['accountId'], 'cash', $p['operationId'], $actor);
        } elseif (($p['mode'] ?? 'journal') === 'mutate_account') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            DB::table('accounts')
                ->where('tenant_id', $p['tenantId'])
                ->where('id', $p['accountId'])
                ->update(['classification' => 'non_current_asset', 'updated_at' => now(), 'updated_by' => $p['actorId']]);
        } elseif (($p['mode'] ?? 'journal') === 'update_semantic') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            $actor = User::query()->findOrFail($p['actorId']);
            app(ManageCashFlowSemanticsAction::class)->setDraftSemantic($p['tenantId'], $p['journalId'], 'financing', (string) Str::ulid(), $actor);
        } elseif (($p['mode'] ?? 'journal') === 'post_journal') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            $actor = User::query()->findOrFail($p['actorId']);
            app(ManageManualJournalAction::class)->post($p['tenantId'], $p['journalId'], $actor);
        } elseif (($p['mode'] ?? 'journal') === 'adopt_historical') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            $actor = User::query()->findOrFail($p['actorId']);
            app(ManageCashFlowSemanticsAction::class)->adoptHistoricalSemantic($p['tenantId'], $p['journalId'], 'operating', $p['operationId'], $actor);
        } elseif (($p['mode'] ?? 'journal') === 'reverse_journal') {
            file_put_contents($p['started'], "started\n", LOCK_EX);
            $actor = User::query()->findOrFail($p['actorId']);
            app(ReverseJournalAction::class)->execute($p['tenantId'], $p['journalId'], $actor, '2026-06-02', 'Cash flow reversal race', true);
        } else {
            $actor = User::query()->findOrFail($p['actor_id']);
            $id = app(ManageManualJournalAction::class)->create($p['tenant_id'], $actor, '2026-06-01', 'Cash flow concurrency', [new JournalLineData($p['cash'], '100.00', '0'), new JournalLineData($p['revenue'], '0', '100.00')], 'operating');
            app(ManageManualJournalAction::class)->post($p['tenant_id'], $id, $actor);
        }
        file_put_contents($p['ready'], "ready\n", LOCK_EX);
        $start = hrtime(true);
        while (! is_file($p['release'])) {
            usleep(10000);
            if ((hrtime(true) - $start) / 1000000 > 10000) {
                throw new RuntimeException('Timed out');
            }
        }
    });
    echo '{"ok":true}';
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
