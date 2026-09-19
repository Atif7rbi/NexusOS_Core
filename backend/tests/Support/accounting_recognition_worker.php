<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
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
    app()->environment('testing') === false
    || str_ends_with(
        (string) config('database.connections.pgsql.database'),
        '_testing',
    ) === false
) {
    throw new RuntimeException(
        'Accounting Recognition concurrency workers require an isolated testing database.',
    );
}

$applicationName = (string) ($payload['application_name'] ?? '');

if (
    $applicationName === ''
    || preg_match('/^[a-z0-9_]{1,63}$/', $applicationName) !== 1
) {
    throw new InvalidArgumentException(
        'Accounting Recognition worker requires a valid application_name.',
    );
}

DB::selectOne(
    "SELECT set_config('application_name', ?, false)",
    [$applicationName],
);

function recognitionSignal(?string $path): void
{
    if ($path !== null && $path !== '') {
        file_put_contents($path, "ready\n", LOCK_EX);
    }
}

function recognitionAwait(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException(
                "Timed out waiting for Accounting Recognition barrier: {$path}",
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
            'adopt',
            'adopt_hold' => app(AdoptPerformanceAccounting::class)->execute(
                $payload['tenant_id'],
                $actor,
                [
                    'contract_id' => $payload['contract_id'],
                    'performance_accounting_adoption_operation_id' => $payload['operation_id'],
                ],
            ),
            'acceptance',
            'acceptance_hold' => [
                'acceptance_id' => app(CreateUnitHandoverAcceptance::class)
                    ->execute(
                        $payload['tenant_id'],
                        $actor,
                        [
                            'handover_evidence_id' => $payload['evidence_id'],
                            'handover_acceptance_operation_id' => $payload['operation_id'],
                            'contract_consideration_transition_operation_id' => $payload['transition_operation_id'],
                        ],
                    ),
            ],
            'ar_policy',
            'ar_policy_hold' => app(
                ConfigureAccountingRecognitionPolicies::class,
            )->receivableAr(
                $payload['tenant_id'],
                $actor,
                $payload['effective_from'],
                $payload['account_id'],
            ),
            default => throw new InvalidArgumentException(
                'Unsupported Accounting Recognition concurrency worker action.',
            ),
        };

        if (str_ends_with($action, '_hold')) {
            recognitionSignal($payload['ready_file'] ?? null);
            recognitionAwait(
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
