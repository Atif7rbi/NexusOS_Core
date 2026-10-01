<?php

declare(strict_types=1);
use App\Models\User;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$p = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_replace(config('database.connections.pgsql'), $p['database'])]);
DB::purge('pgsql');
DB::transaction(function () use ($p): void {
    $actor = User::query()->findOrFail($p['actor_id']);
    $id = app(ManageManualJournalAction::class)->create($p['tenant_id'], $actor, '2026-06-01', 'GL concurrency', [new JournalLineData($p['cash'], '100.00', '0'), new JournalLineData($p['equity'], '0', '100.00')]);
    app(ManageManualJournalAction::class)->post($p['tenant_id'], $id, $actor);
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
