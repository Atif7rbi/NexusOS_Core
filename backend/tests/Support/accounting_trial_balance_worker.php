<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace(config('database.connections.pgsql'), $payload['database'])]);
DB::purge('pgsql');

DB::transaction(function () use ($payload): void {
    $actor = User::query()->findOrFail($payload['actor_id']);
    $journal = app(ManageManualJournalAction::class)->create($payload['tenant_id'], $actor, '2026-06-01', 'Trial Balance concurrency fixture', [
        new JournalLineData($payload['debit_account_id'], '100.00', '0'),
        new JournalLineData($payload['credit_account_id'], '0', '100.00'),
    ]);
    app(ManageManualJournalAction::class)->post($payload['tenant_id'], $journal, $actor);
    file_put_contents($payload['ready_file'], "ready\n", LOCK_EX);
    $started = hrtime(true);
    while (! is_file($payload['release_file'])) {
        usleep(10_000);
        if ((hrtime(true) - $started) / 1_000_000 > 10_000) {
            throw new RuntimeException('Timed out waiting for commit barrier.');
        }
    }
});

echo '{"ok":true}';
