<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
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
        'Receivable AR workers require an isolated testing database.',
    );
}

$applicationName = (string) ($payload['application_name'] ?? '');

if (
    $applicationName === ''
    || preg_match('/^[a-z0-9_]{1,63}$/', $applicationName) !== 1
) {
    throw new InvalidArgumentException(
        'Receivable AR worker requires a valid application_name.',
    );
}

DB::selectOne(
    "SELECT set_config('application_name', ?, false)",
    [$applicationName],
);

function receivableArSignal(?string $path): void
{
    if ($path !== null && $path !== '') {
        file_put_contents($path, "ready\n", LOCK_EX);
    }
}

function receivableArAwait(
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
                "Timed out waiting for Receivable AR barrier: {$path}",
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
                    RecognizeReceivableAr::class,
                )->execute(
                    $payload['tenant_id'],
                    $actor,
                    [
                        'contractual_billing_entitlement_id' => $payload['entitlement_id'],
                        'receivable_ar_operation_id' => $payload['operation_id'],
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
                $payload['ar_control_account_id'],
            ),
            'counterpart_policy',
            'counterpart_policy_hold' => app(
                ConfigureAccountingRecognitionPolicies::class,
            )->counterpart(
                $payload['tenant_id'],
                $actor,
                $payload['effective_from'],
                $payload['contract_asset_account_id'],
                $payload['contract_liability_account_id'],
            ),
            'period_close',
            'period_close_hold' => (function () use ($payload, $actor): array {
                app(ManageAccountingPeriodAction::class)->close(
                    $payload['tenant_id'],
                    $payload['period_id'],
                    $actor,
                );

                return ['period_id' => $payload['period_id']];
            })(),
            'source_correct',
            'source_correct_hold' => [
                'schedule_id' => app(
                    CorrectFinalizedContractualBillingSchedule::class,
                )->execute(
                    $payload['tenant_id'],
                    $payload['schedule_id'],
                    $actor,
                    [
                        'source_correction_operation_id' => $payload['source_correction_operation_id'],
                        'source_correction_reason' => $payload['reason'],
                        'source_correction_reference' => $payload['reference'],
                        'entitlement_reversals' => [
                            $payload['entitlement_id'] => $payload['entitlement_reversal_operation_id'],
                        ],
                    ],
                ),
            ],
            default => throw new InvalidArgumentException(
                'Unsupported Receivable AR concurrency action.',
            ),
        };

        if (str_ends_with($action, '_hold')) {
            receivableArSignal($payload['ready_file'] ?? null);
            receivableArAwait(
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
