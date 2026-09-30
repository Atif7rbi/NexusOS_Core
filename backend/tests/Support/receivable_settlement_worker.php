<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\Payments\Actions\CancelPaymentAllocationAction;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\ReversePostedBankReceiptCashAndInvalidate;
use App\Modules\Settlement\Actions\ReverseReceivableSettlement;
use App\Modules\Settlement\Actions\SettlePaymentAllocation;
use Illuminate\Contracts\Console\Kernel;
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
if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')) {
    throw new RuntimeException('Settlement workers require an isolated testing database.');
}
$applicationName = (string) ($payload['application_name'] ?? '');
if (preg_match('/^[a-z0-9_]{1,63}$/', $applicationName) !== 1) {
    throw new InvalidArgumentException('Settlement worker requires application_name.');
}
DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
$pid = (int) DB::selectOne('SELECT pg_backend_pid() pid')->pid;
if (! empty($payload['pid_file'])) {
    file_put_contents($payload['pid_file'], (string) $pid, LOCK_EX);
}

function settlementAwait(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }
    $started = hrtime(true);
    while (! is_file($path)) {
        usleep(10_000);
        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException("Timed out waiting for Settlement barrier {$path}");
        }
    }
}

try {
    $result = DB::transaction(function () use ($payload): array {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '30s'");
        $actor = User::query()->findOrFail($payload['actor_id']);
        $requested = (string) $payload['action'];
        $action = str_ends_with($requested, '_hold')
            ? substr($requested, 0, -5)
            : $requested;

        $result = match ($action) {
            'settle' => app(SettlePaymentAllocation::class)->execute(
                $payload['tenant_id'],
                $actor,
                [
                    'payment_allocation_id' => $payload['allocation_id'],
                    'settlement_operation_id' => $payload['operation_id'],
                ],
            ),
            'reverse_settlement' => app(ReverseReceivableSettlement::class)->execute(
                $payload['tenant_id'],
                $payload['settlement_id'],
                $actor,
                [
                    'reversal_operation_id' => $payload['operation_id'],
                    'reversal_date' => $payload['reversal_date'],
                    'reversal_reason' => $payload['reason'],
                ],
            ),
            'cancel_allocation' => (function () use ($payload, $actor): array {
                app(CancelPaymentAllocationAction::class)->execute(
                    $payload['tenant_id'], $payload['allocation_id'], $actor, $payload['reason'],
                );

                return ['allocation_id' => $payload['allocation_id']];
            })(),
            'cancel_association' => (function () use ($payload, $actor): array {
                app(CancelReceiptPaymentAssociation::class)->execute(
                    $payload['tenant_id'], $payload['association_id'], $actor,
                    ['cancellation_operation_id' => $payload['operation_id'], 'cancellation_reason' => $payload['reason']],
                );

                return ['association_id' => $payload['association_id']];
            })(),
            'reverse_cash' => app(ReversePostedBankReceiptCashAndInvalidate::class)->execute(
                $payload['tenant_id'], $payload['receipt_id'], $payload['cash_posting_id'], $actor,
                [
                    'reversal_operation_id' => $payload['operation_id'],
                    'reversal_date' => $payload['reversal_date'],
                    'reversal_reason' => $payload['reason'],
                    'invalidation_operation_id' => $payload['invalidation_operation_id'],
                    'invalidation_reason' => $payload['reason'],
                ],
            ),
            'source_correct' => ['schedule_id' => app(CorrectFinalizedContractualBillingSchedule::class)->execute(
                $payload['tenant_id'], $payload['schedule_id'], $actor,
                [
                    'source_correction_operation_id' => $payload['operation_id'],
                    'source_correction_reason' => $payload['reason'],
                    'source_correction_reference' => $payload['reference'],
                    'entitlement_reversals' => [$payload['entitlement_id'] => $payload['entitlement_reversal_operation_id']],
                ],
            )],
            'archive_account' => (function () use ($payload, $actor): array {
                app(ManageAccountAction::class)->archive($payload['tenant_id'], $payload['account_id'], $actor);

                return ['account_id' => $payload['account_id']];
            })(),
            default => throw new InvalidArgumentException('Unsupported Settlement concurrency action.'),
        };

        if (str_ends_with($requested, '_hold')) {
            if (! empty($payload['ready_file'])) {
                file_put_contents($payload['ready_file'], "ready\n", LOCK_EX);
            }
            settlementAwait($payload['release_file'] ?? null, (int) ($payload['barrier_timeout_ms'] ?? 15000));
        }

        return $result;
    });
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
