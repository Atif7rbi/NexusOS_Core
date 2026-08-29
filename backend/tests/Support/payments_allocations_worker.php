<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAllocationAction;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$payload = json_decode(
    base64_decode($argv[1], true),
    true,
    flags: JSON_THROW_ON_ERROR,
);

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

if (isset($payload['database']['search_path'])) {
    config([
        'database.connections.pgsql.search_path' => $payload['database']['search_path'],
    ]);
}

DB::purge('pgsql');

if (isset($payload['pid_file']) && is_string($payload['pid_file']) && $payload['pid_file'] !== '') {
    $backendPid = DB::selectOne('SELECT pg_backend_pid() AS pid')?->pid;

    if (! is_int($backendPid) && ! (is_string($backendPid) && ctype_digit($backendPid))) {
        throw new RuntimeException('Unable to resolve PostgreSQL backend PID.');
    }

    file_put_contents(
        $payload['pid_file'],
        (string) $backendPid,
        LOCK_EX,
    );
}

function signalFile(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    file_put_contents($path, "ready\n", LOCK_EX);
}

function awaitFile(?string $path, int $timeoutMs = 10000): void
{
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        if ($elapsedMs > $timeoutMs) {
            throw new RuntimeException("Timed out waiting for barrier: {$path}");
        }
    }
}

function allocationRow(array $payload): array
{
    $now = now();

    return [
        'id' => $payload['allocation_id'],
        'tenant_id' => $payload['tenant_id'],
        'payment_id' => $payload['payment_id'],
        'receivable_id' => $payload['receivable_id'],
        'allocation_operation_id' => $payload['allocation_operation_id'],
        'amount' => $payload['amount'],
        'status' => 'effective',
        'allocated_at' => $now,
        'allocated_by' => $payload['actor_id'],
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

try {
    awaitFile($payload['start_barrier'] ?? null);

    $action = $payload['action'] ?? null;

    if ($action === 'allocate') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        $id = app(AllocatePaymentAction::class)->execute(
            $payload['tenant_id'],
            $actor,
            [
                'payment_id' => $payload['payment_id'],
                'receivable_id' => $payload['receivable_id'],
                'allocation_operation_id' => $payload['allocation_operation_id'],
                'amount' => $payload['amount'],
            ],
        );

        echo json_encode([
            'ok' => true,
            'allocation_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'cancel_payment') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        app(CancelPaymentAction::class)->execute(
            $payload['tenant_id'],
            $payload['payment_id'],
            $actor,
            $payload['reason'],
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'cancel_receivable') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        app(CancelReceivableAction::class)->execute(
            $payload['tenant_id'],
            $payload['receivable_id'],
            $actor,
            $payload['cancelled_at'],
            $payload['reason'],
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'cancel_allocation') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        app(CancelPaymentAllocationAction::class)->execute(
            $payload['tenant_id'],
            $payload['allocation_id'],
            $actor,
            $payload['reason'],
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'amend_collection') {
        $lines = [
            new CollectionLineData(
                null,
                1,
                $payload['title'] ?? 'Replacement Collection',
                $payload['amount'],
                CarbonImmutable::parse($payload['due_date']),
                null,
            ),
        ];

        (new AmendCollectionScheduleAction)->execute(
            $payload['tenant_id'],
            $payload['contract_id'],
            (int) $payload['actor_id'],
            [$payload['collection_id']],
            $lines,
            $payload['reason'],
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_insert_allocation') {
        signalFile($payload['ready_file'] ?? null);
        awaitFile(
            $payload['release_file'] ?? null,
            (int) ($payload['barrier_timeout_ms'] ?? 10000),
        );

        DB::table('payment_allocations')->insert(allocationRow($payload));

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_cancel_payment') {
        DB::table('payments')
            ->where('tenant_id', $payload['tenant_id'])
            ->where('id', $payload['payment_id'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $payload['actor_id'],
                'cancellation_reason' => $payload['reason'],
                'updated_at' => now(),
            ]);

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_cancel_receivable') {
        DB::table('receivables')
            ->where('tenant_id', $payload['tenant_id'])
            ->where('id', $payload['receivable_id'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => $payload['cancelled_at'],
                'cancelled_by' => $payload['actor_id'],
                'cancellation_reason' => $payload['reason'],
                'updated_at' => now(),
            ]);

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if (in_array($action, ['hold_tenant', 'hold_payment', 'hold_receivable', 'hold_allocation'], true)) {
        DB::transaction(function () use ($payload, $action): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            [$table, $idColumn, $id] = match ($action) {
                'hold_tenant' => ['tenants', 'id', $payload['tenant_id']],
                'hold_payment' => ['payments', 'id', $payload['payment_id']],
                'hold_receivable' => ['receivables', 'id', $payload['receivable_id']],
                'hold_allocation' => ['payment_allocations', 'id', $payload['allocation_id']],
            };

            $query = DB::table($table);

            if ($action !== 'hold_tenant') {
                $query->where('tenant_id', $payload['tenant_id']);
            }

            $query
                ->where($idColumn, $id)
                ->lockForUpdate()
                ->firstOrFail();

            signalFile($payload['ready_file'] ?? null);
            awaitFile(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 10000),
            );
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    throw new InvalidArgumentException('Unsupported payments concurrency worker action.');
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'sqlstate' => $exception instanceof QueryException
            ? (string) ($exception->errorInfo[0] ?? '')
            : null,
    ], JSON_THROW_ON_ERROR);
}
