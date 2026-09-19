<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\CorrectPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
use Illuminate\Contracts\Console\Kernel;
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

if (
    ! app()->environment('testing')
    || ! str_ends_with(
        (string) config('database.connections.pgsql.database'),
        '_testing',
    )
) {
    throw new RuntimeException(
        'Performance Accounting workers require an isolated testing database.',
    );
}

$applicationName = (string) ($payload['application_name'] ?? '');

if (
    $applicationName === ''
    || preg_match('/^[a-z0-9_]{1,63}$/', $applicationName) !== 1
) {
    throw new InvalidArgumentException(
        'Performance Accounting worker requires a valid application_name.',
    );
}

DB::selectOne(
    "SELECT set_config('application_name', ?, false)",
    [$applicationName],
);

function performanceAccountingSignal(?string $path): void
{
    if ($path !== null && $path !== '') {
        file_put_contents($path, "ready\n", LOCK_EX);
    }
}

function performanceAccountingAwait(
    ?string $path,
    int $timeoutMs = 15000,
): void {
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException(
                "Timed out waiting for Performance Accounting barrier: {$path}",
            );
        }
    }
}

try {
    $result = DB::transaction(function () use ($payload): array {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '30s'");

        $actor = User::query()->findOrFail($payload['actor_id']);
        $action = (string) $payload['action'];

        $result = match ($action) {
            'recognize',
            'recognize_hold' => [
                'recognition_id' => app(
                    RecognizePerformanceAccounting::class,
                )->execute(
                    $payload['tenant_id'],
                    $actor,
                    [
                        'unit_handover_acceptance_id' =>
                            $payload['acceptance_id'],
                        'performance_accounting_operation_id' =>
                            $payload['operation_id'],
                    ],
                ),
            ],
            'correct',
            'correct_hold' => [
                'recognition_id' => app(
                    CorrectPerformanceAccounting::class,
                )->execute(
                    $payload['tenant_id'],
                    $actor,
                    [
                        'unit_handover_acceptance_id' =>
                            $payload['acceptance_id'],
                        'performance_accounting_correction_operation_id' =>
                            $payload['operation_id'],
                        'correction_reason' => $payload['reason'],
                        'correction_reference' => $payload['reference'],
                    ],
                ),
            ],
            'performance_policy',
            'performance_policy_hold' => app(
                ConfigureAccountingRecognitionPolicies::class,
            )->performance(
                $payload['tenant_id'],
                $actor,
                $payload['effective_from'],
                $payload['revenue_account_id'],
                $payload['contract_asset_account_id'],
                $payload['contract_liability_account_id'],
            ),
            'source_reverse',
            'source_reverse_hold' => [
                'evidence_id' => app(
                    ReverseUnitHandoverPerformanceSource::class,
                )->execute(
                    $payload['tenant_id'],
                    $payload['acceptance_id'],
                    $actor,
                    [
                        'reversal_operation_id' => $payload['operation_id'],
                        'reversal_reason' => $payload['reason'],
                        'reversal_reference' => $payload['reference'],
                    ],
                ),
            ],
            'period_close',
            'period_close_hold' => (function () use ($payload, $actor): array {
                app(ManageAccountingPeriodAction::class)->close(
                    $payload['tenant_id'],
                    $payload['period_id'],
                    $actor,
                );

                return ['period_id' => $payload['period_id']];
            })(),
            default => throw new InvalidArgumentException(
                'Unsupported Performance Accounting concurrency action.',
            ),
        };

        if (str_ends_with($action, '_hold')) {
            performanceAccountingSignal($payload['ready_file'] ?? null);
            performanceAccountingAwait(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 15000),
            );
        }

        return $result;
    });

    echo json_encode(
        ['ok' => true, 'result' => $result],
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
