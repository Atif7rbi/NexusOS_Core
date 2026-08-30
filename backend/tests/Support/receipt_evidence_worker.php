<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\InvalidateBankReceipt;
use App\Modules\ReceiptEvidence\Actions\RetireReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $payload['database']['host'], 'database.connections.pgsql.port' => $payload['database']['port'], 'database.connections.pgsql.database' => $payload['database']['database'], 'database.connections.pgsql.username' => $payload['database']['username'], 'database.connections.pgsql.password' => $payload['database']['password']]);
DB::purge('pgsql');

if (isset($payload['pid_file']) && is_string($payload['pid_file']) && $payload['pid_file'] !== '') {
    file_put_contents($payload['pid_file'], (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, LOCK_EX);
}

function receiptBarrier(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }
    $started = hrtime(true);
    while (! is_file($path)) {
        usleep(10_000);
        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException("Timed out waiting for barrier: {$path}");
        }
    }
}

try {
    receiptBarrier($payload['start_barrier'] ?? null, (int) ($payload['barrier_timeout_ms'] ?? 15000));
    $action = $payload['action'];
    $actor = $action === 'hold_tenant' ? null : User::query()->findOrFail($payload['actor_id']);
    $result = match ($action) {
        'verify' => app(VerifyBankReceipt::class)->execute($payload['tenant_id'], $actor, $payload['facts']),
        'associate' => app(AssociateReceiptWithPayment::class)->execute($payload['tenant_id'], $actor, $payload['facts']),
        'cancel_payment' => app(CancelPaymentAction::class)->execute($payload['tenant_id'], $payload['payment_id'], $actor, $payload['reason']),
        'invalidate' => app(InvalidateBankReceipt::class)->execute($payload['tenant_id'], $payload['receipt_id'], $actor, $payload['facts']),
        'retire' => app(RetireReceivingAccount::class)->execute($payload['tenant_id'], $payload['account_id'], $actor, $payload['facts']),
        'cancel_association' => app(CancelReceiptPaymentAssociation::class)->execute($payload['tenant_id'], $payload['association_id'], $actor, $payload['facts']),
        'hold_tenant' => DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::table('tenants')->where('id', $payload['tenant_id'])->lockForUpdate()->firstOrFail();
            file_put_contents($payload['ready_file'], "ready\n", LOCK_EX);
            receiptBarrier($payload['release_file'], (int) ($payload['barrier_timeout_ms'] ?? 15000));
        }),
        default => throw new InvalidArgumentException('Unsupported receipt evidence concurrency worker action.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage(), 'sqlstate' => $exception instanceof QueryException ? (string) ($exception->errorInfo[0] ?? '') : null], JSON_THROW_ON_ERROR);
}
