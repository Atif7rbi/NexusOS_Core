<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\ReceiptEvidence\Actions\PostVerifiedBankReceiptCash;
use App\Modules\ReceiptEvidence\Actions\ReversePostedBankReceiptCashAndInvalidate;
use App\Modules\ReceiptEvidence\Actions\SupersedeBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Actions\SupersedeReceivingAccountCashMapping;
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

DB::purge('pgsql');

function cashSignal(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    file_put_contents($path, "ready\n", LOCK_EX);
}

function cashAwait(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException(
                "Timed out waiting for Phase 8F barrier: {$path}",
            );
        }
    }
}

if (
    isset($payload['pid_file'])
    && is_string($payload['pid_file'])
    && $payload['pid_file'] !== ''
) {
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')?->pid;

    if (! is_int($pid) && ! (is_string($pid) && ctype_digit($pid))) {
        throw new RuntimeException(
            'Unable to resolve Phase 8F PostgreSQL backend PID.',
        );
    }

    file_put_contents($payload['pid_file'], (string) $pid, LOCK_EX);
}

try {
    cashAwait(
        $payload['start_barrier'] ?? null,
        (int) ($payload['barrier_timeout_ms'] ?? 15000),
    );

    $action = (string) ($payload['action'] ?? '');

    if ($action === 'hold_tenant') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('tenants')
                ->where('id', $payload['tenant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            cashSignal($payload['ready_file'] ?? null);

            cashAwait(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 15000),
            );
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    $actor = isset($payload['actor_id'])
        ? User::query()->findOrFail($payload['actor_id'])
        : null;

    if ($action === 'post') {
        $result = app(PostVerifiedBankReceiptCash::class)->execute(
            $payload['tenant_id'],
            $payload['receipt_id'],
            $actor,
            $payload['facts'],
        );

        echo json_encode(
            ['ok' => true, 'result' => $result],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'reverse') {
        $result = app(
            ReversePostedBankReceiptCashAndInvalidate::class,
        )->execute(
            $payload['tenant_id'],
            $payload['receipt_id'],
            $payload['posting_id'],
            $actor,
            $payload['facts'],
        );

        echo json_encode(
            ['ok' => true, 'result' => $result],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'supersede_mapping') {
        $id = app(SupersedeReceivingAccountCashMapping::class)->execute(
            $payload['tenant_id'],
            $payload['mapping_id'],
            $actor,
            $payload['facts'],
        );

        echo json_encode(
            ['ok' => true, 'mapping_id' => $id],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'supersede_policy') {
        $id = app(
            SupersedeBankReceiptCashClearingPolicy::class,
        )->execute(
            $payload['tenant_id'],
            $payload['policy_id'],
            $actor,
            $payload['facts'],
        );

        echo json_encode(
            ['ok' => true, 'policy_id' => $id],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'close_period') {
        app(ManageAccountingPeriodAction::class)->close(
            $payload['tenant_id'],
            $payload['period_id'],
            $actor,
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'archive_account') {
        app(ManageAccountAction::class)->archive(
            $payload['tenant_id'],
            $payload['account_id'],
            $actor,
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_invalidate_receipt') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('tenants')
                ->where('id', $payload['tenant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            DB::table('bank_receipt_evidence')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['receipt_id'])
                ->update([
                    'status' => 'invalidated',
                    'invalidation_operation_id' => $payload['invalidation_operation_id'],
                    'invalidation_reason' => $payload['invalidation_reason'],
                    'invalidated_by' => $payload['actor_id'],
                    'invalidated_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_insert_mapping') {
        DB::table('approved_receiving_account_cash_mappings')->insert([
            'id' => $payload['id'],
            'tenant_id' => $payload['tenant_id'],
            'mapping_operation_id' => $payload['mapping_operation_id'],
            'receiving_account_id' => $payload['receiving_account_id'],
            'cash_account_id' => $payload['cash_account_id'],
            'status' => 'effective',
            'configured_by' => $payload['actor_id'],
            'configured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_insert_policy') {
        DB::table('bank_receipt_cash_clearing_policies')->insert([
            'id' => $payload['id'],
            'tenant_id' => $payload['tenant_id'],
            'policy_operation_id' => $payload['policy_operation_id'],
            'clearing_account_id' => $payload['clearing_account_id'],
            'status' => 'effective',
            'configured_by' => $payload['actor_id'],
            'configured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_broken_mapping_chain') {
        DB::transaction(function () use ($payload): void {
            $now = now();

            DB::table('approved_receiving_account_cash_mappings')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['mapping_id'])
                ->update([
                    'status' => 'superseded',
                    'supersession_operation_id' => $payload['supersession_operation_id'],
                    'superseded_by' => $payload['actor_id'],
                    'superseded_at' => $now,
                    'supersession_reason' => 'Broken direct SQL chain',
                    'updated_at' => $now,
                ]);

            DB::table(
                'approved_receiving_account_cash_mappings',
            )->insert([
                'id' => $payload['successor_id'],
                'tenant_id' => $payload['tenant_id'],
                'mapping_operation_id' => $payload['different_operation_id'],
                'receiving_account_id' => $payload['receiving_account_id'],
                'cash_account_id' => $payload['cash_account_id'],
                'replaces_mapping_id' => $payload['mapping_id'],
                'status' => 'effective',
                'configured_by' => $payload['actor_id'],
                'configured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_broken_policy_chain') {
        DB::transaction(function () use ($payload): void {
            $now = now();

            DB::table('bank_receipt_cash_clearing_policies')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['policy_id'])
                ->update([
                    'status' => 'superseded',
                    'supersession_operation_id' => $payload['supersession_operation_id'],
                    'superseded_by' => $payload['actor_id'],
                    'superseded_at' => $now,
                    'supersession_reason' => 'Broken direct SQL chain',
                    'updated_at' => $now,
                ]);

            DB::table(
                'bank_receipt_cash_clearing_policies',
            )->insert([
                'id' => $payload['successor_id'],
                'tenant_id' => $payload['tenant_id'],
                'policy_operation_id' => $payload['different_operation_id'],
                'clearing_account_id' => $payload['clearing_account_id'],
                'replaces_policy_id' => $payload['policy_id'],
                'status' => 'effective',
                'configured_by' => $payload['actor_id'],
                'configured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'direct_insert_posting') {
        DB::table('bank_receipt_cash_postings')->insert(
            $payload['row'],
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'runtime_execute') {
        $role = (string) $payload['runtime_role'];

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            throw new InvalidArgumentException(
                'Invalid runtime role identifier.',
            );
        }

        DB::statement('SET ROLE "'.$role.'"');

        DB::select(
            'SELECT public.enforce_bank_receipt_cash_posting_history()',
        );

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    throw new InvalidArgumentException(
        'Unsupported Phase 8F concurrency worker action.',
    );
} catch (Throwable $exception) {
    $sqlstate = null;

    if ($exception instanceof QueryException) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? '');
    } elseif ($exception instanceof PDOException) {
        $sqlstate = (string) (
            $exception->errorInfo[0]
            ?? $exception->getCode()
            ?? ''
        );
    }

    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'sqlstate' => $sqlstate,
    ], JSON_THROW_ON_ERROR);
}
