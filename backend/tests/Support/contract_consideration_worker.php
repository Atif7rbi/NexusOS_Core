<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
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

$applicationName = $payload['application_name'] ?? null;

if (is_string($applicationName) && $applicationName !== '') {
    if (preg_match('/^[a-z0-9_]{1,63}$/', $applicationName) !== 1) {
        throw new InvalidArgumentException(
            'Invalid Contract Consideration worker application_name.',
        );
    }

    DB::statement("SET application_name = '{$applicationName}'");
}

function considerationSignal(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    file_put_contents($path, "ready\n", LOCK_EX);
}

function considerationAwait(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException(
                "Timed out waiting for Contract Consideration barrier: {$path}",
            );
        }
    }
}

try {
    considerationAwait(
        $payload['start_barrier'] ?? null,
        (int) ($payload['barrier_timeout_ms'] ?? 15000),
    );

    $action = (string) ($payload['action'] ?? '');

    if ($action === 'hold_contract') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('contracts')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['contract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            considerationSignal($payload['ready_file'] ?? null);
            considerationAwait(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 15000),
            );
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'adopt') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        $id = app(AdoptContractConsideration::class)->execute(
            $payload['tenant_id'],
            $payload['contract_id'],
            $actor,
            ['coordination_adoption_operation_id' => $payload['operation_id']],
        );

        echo json_encode([
            'ok' => true,
            'adoption_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'activate_billing_entitlement') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        $id = app(ActivateContractualBillingEntitlement::class)->execute(
            $payload['tenant_id'],
            $payload['obligation_id'],
            $actor,
            ['billing_entitlement_operation_id' => $payload['operation_id']],
        );

        echo json_encode([
            'ok' => true,
            'entitlement_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'create_acceptance') {
        $actor = User::query()->findOrFail($payload['actor_id']);

        $id = app(CreateUnitHandoverAcceptance::class)->execute(
            $payload['tenant_id'],
            $actor,
            [
                'handover_evidence_id' => $payload['evidence_id'],
                'handover_acceptance_operation_id' => $payload['operation_id'],
            ],
        );

        echo json_encode([
            'ok' => true,
            'acceptance_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'hold_contract_then_update_total') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('contracts')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['contract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            considerationSignal($payload['ready_file'] ?? null);
            considerationAwait(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 15000),
            );

            DB::table('contracts')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['contract_id'])
                ->update([
                    'total_amount' => $payload['total_amount'],
                    'updated_at' => now(),
                ]);
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'hold_membership_then_pause') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            $membership = DB::table('tenant_users')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('user_id', $payload['actor_id'])
                ->lockForUpdate()
                ->firstOrFail();

            considerationSignal($payload['ready_file'] ?? null);
            considerationAwait(
                $payload['release_file'] ?? null,
                (int) ($payload['barrier_timeout_ms'] ?? 15000),
            );

            DB::table('tenant_users')
                ->where('id', $membership->id)
                ->update([
                    'status' => 'paused',
                    'updated_at' => now(),
                ]);
        });

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

        return;
    }

    throw new InvalidArgumentException(
        'Unsupported Contract Consideration concurrency worker action.',
    );
} catch (Throwable $exception) {
    $sqlstate = null;

    if ($exception instanceof QueryException) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? '');
    } elseif ($exception instanceof PDOException) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode() ?? '');
    }

    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'sqlstate' => $sqlstate,
    ], JSON_THROW_ON_ERROR);
}
