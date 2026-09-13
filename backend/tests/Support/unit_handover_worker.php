<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Contracts\Actions\CancelContractAction;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
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
    if (
        preg_match(
            '/^[a-z0-9_]{1,63}$/',
            $applicationName,
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid Unit Handover worker application_name.',
        );
    }

    DB::statement(
        "SET application_name = '{$applicationName}'",
    );
}

function handoverSignal(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    file_put_contents(
        $path,
        "ready\n",
        LOCK_EX,
    );
}

function handoverAwait(
    ?string $path,
    int $timeoutMs = 15000,
): void {
    if ($path === null || $path === '') {
        return;
    }

    $started = hrtime(true);

    while (! is_file($path)) {
        usleep(10_000);

        if (
            (hrtime(true) - $started) / 1_000_000
            > $timeoutMs
        ) {
            throw new RuntimeException(
                "Timed out waiting for Unit Handover barrier: {$path}",
            );
        }
    }
}

try {
    handoverAwait(
        $payload['start_barrier'] ?? null,
        (int) ($payload['barrier_timeout_ms'] ?? 15000),
    );

    $action = (string) ($payload['action'] ?? '');

    if ($action === 'hold_contract') {
        DB::transaction(function () use ($payload): void {
            DB::statement(
                "SET LOCAL lock_timeout = '5s'",
            );
            DB::statement(
                "SET LOCAL statement_timeout = '30s'",
            );

            DB::table('contracts')
                ->where(
                    'tenant_id',
                    $payload['tenant_id'],
                )
                ->where(
                    'id',
                    $payload['contract_id'],
                )
                ->lockForUpdate()
                ->firstOrFail();

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'record_evidence') {
        $actor = User::query()
            ->findOrFail($payload['actor_id']);

        $id = app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $payload['tenant_id'],
            $actor,
            [
                'contract_id' => $payload['contract_id'],
                'handover_evidence_operation_id' => $payload['operation_id'],
                'handover_evidence_reference' => $payload['evidence_reference'],
                'readiness_reference' => $payload['readiness_reference'],
                'readiness_effective_date' => $payload['readiness_effective_date'],
                'customer_acceptance_reference' => $payload[
                        'customer_acceptance_reference'
                    ],
                'customer_acceptance_effective_date' => $payload[
                        'customer_acceptance_effective_date'
                    ],
            ],
        );

        echo json_encode([
            'ok' => true,
            'evidence_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'create_acceptance') {
        $actor = User::query()
            ->findOrFail($payload['actor_id']);

        $id = app(
            CreateUnitHandoverAcceptance::class,
        )->execute(
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

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );

            DB::table('contracts')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['contract_id'])
                ->update([
                    'total_amount' => $payload['total_amount'],
                    'updated_at' => now(),
                ]);
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'hold_contract_then_cancel') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('contracts')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['contract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );

            app(CancelContractAction::class)->execute(
                $payload['tenant_id'],
                $payload['contract_id'],
                $payload['actor_id'],
            );
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'hold_unit_then_archive') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('units')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['unit_id'])
                ->lockForUpdate()
                ->firstOrFail();

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );

            $now = now();

            DB::table('units')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['unit_id'])
                ->update([
                    'archived_at' => $now,
                    'archived_by' => $payload['actor_id'],
                    'updated_by' => $payload['actor_id'],
                    'updated_at' => $now,
                ]);
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    if ($action === 'activate_billing_entitlement') {
        $actor = User::query()
            ->findOrFail($payload['actor_id']);

        $id = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $payload['tenant_id'],
            $payload['obligation_id'],
            $actor,
            [
                'billing_entitlement_operation_id' => $payload['operation_id'],
            ],
        );

        echo json_encode([
            'ok' => true,
            'entitlement_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'reverse_performance_source') {
        $actor = User::query()
            ->findOrFail($payload['actor_id']);

        $id = app(
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
        );

        echo json_encode([
            'ok' => true,
            'acceptance_id' => $id,
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ($action === 'hold_evidence_then_reverse_direct') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            DB::table('unit_handover_evidence')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['evidence_id'])
                ->lockForUpdate()
                ->firstOrFail();

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );

            $now = now();

            DB::table('unit_handover_evidence')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('id', $payload['evidence_id'])
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $payload['operation_id'],
                    'reversal_reason' => $payload['reason'],
                    'reversal_reference' => $payload['reference'],
                    'reversed_by' => $payload['actor_id'],
                    'reversed_at' => $now,
                    'updated_at' => $now,
                ]);
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

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

            handoverSignal(
                $payload['ready_file'] ?? null,
            );

            handoverAwait(
                $payload['release_file'] ?? null,
                (int) (
                    $payload['barrier_timeout_ms']
                    ?? 15000
                ),
            );

            DB::table('tenant_users')
                ->where('id', $membership->id)
                ->update([
                    'status' => 'paused',
                    'updated_at' => now(),
                ]);
        });

        echo json_encode(
            ['ok' => true],
            JSON_THROW_ON_ERROR,
        );

        return;
    }

    throw new InvalidArgumentException(
        'Unsupported Unit Handover concurrency worker action.',
    );
} catch (Throwable $exception) {
    $sqlstate = null;

    if ($exception instanceof QueryException) {
        $sqlstate = (string) (
            $exception->errorInfo[0]
            ?? ''
        );
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
