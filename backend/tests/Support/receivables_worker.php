<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\ReceivableAccounting\Services\ReceivableAccountingIntegration;
use App\Modules\ReceivableAccounting\Services\ReceivableAccountingRaceResolver;
use App\Modules\ReceivableAccounting\Services\ReceivableCancellationOrchestrator;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => $payload['database']['host'],
    'database.connections.pgsql.port' => $payload['database']['port'],
    'database.connections.pgsql.database' => $payload['database']['database'],
    'database.connections.pgsql.username' => $payload['database']['username'],
    'database.connections.pgsql.password' => $payload['database']['password'],
]);
DB::purge('pgsql');

try {
    if (($payload['pre_delay_ms'] ?? 0) > 0) {
        usleep((int) $payload['pre_delay_ms'] * 1000);
    }

    if (in_array(($payload['action'] ?? null), ['deactivate_membership', 'hold_membership'], true)) {
        DB::transaction(function () use ($payload): void {
            $membership = DB::table('tenant_users')->where('tenant_id', $payload['tenant_id'])->where('user_id', $payload['actor_id'])->lockForUpdate()->firstOrFail();
            if ($payload['action'] === 'deactivate_membership') {
                DB::table('tenant_users')->where('id', $membership->id)->update(['status' => 'paused', 'updated_at' => now()]);
            }
            usleep((int) $payload['hold_ms'] * 1000);
        });
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }
    if (($payload['action'] ?? null) === 'probe_receivable_lock') {
        DB::transaction(fn () => DB::table('receivables')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['receivable_id'])->lock('FOR UPDATE NOWAIT')->firstOrFail());
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }
    $actor = User::query()->findOrFail($payload['actor_id']);
    if (($payload['action'] ?? null) === 'integrated_recognize') {
        $lines = array_map(
            fn (array $line): JournalLineData => new JournalLineData($line['account_id'], $line['debit'], $line['credit'], $line['memo'] ?? null),
            $payload['lines'],
        );
        try {
            $result = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
                $payload['tenant_id'], $actor, $payload['recognition'], $payload['entry_date'], $payload['description'], $lines,
            ));
            $result = ['status' => 'committed', 'receivable_id' => $result['receivable_id'], 'journal_entry_id' => $result['journal_entry_id']];
        } catch (QueryException $exception) {
            $result = app(ReceivableAccountingRaceResolver::class)->resolveAfterRollback(
                $exception, $payload['tenant_id'], $payload['recognition'], (int) $actor->id,
                $payload['entry_date'], $payload['description'], $lines,
            );
        }
        echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);

        return;
    }
    if (($payload['action'] ?? null) === 'integrated_cancel') {
        app(ReceivableCancellationOrchestrator::class)->execute(
            $payload['tenant_id'], $payload['receivable_id'], $actor, $payload['cancelled_at'], $payload['reason'], $payload['reversal_date'] ?? null,
        );
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }
    if (($payload['action'] ?? 'cancel') === 'recognize') {
        $id = app(RecognizeReceivableAction::class)->execute(
            $payload['tenant_id'],
            $actor,
            $payload['recognition'],
        );
        echo json_encode(['ok' => true, 'receivable_id' => $id], JSON_THROW_ON_ERROR);

        return;
    }
    app(CancelReceivableAction::class)->execute(
        $payload['tenant_id'],
        $payload['receivable_id'],
        $actor,
        $payload['cancelled_at'],
        $payload['reason'],
    );
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
