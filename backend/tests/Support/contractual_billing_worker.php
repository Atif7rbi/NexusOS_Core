<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\SupersedeFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Support\CancelLinkedReceivablePrimitive;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use Carbon\CarbonImmutable;
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

function billingSignal(?string $path): void
{
    if ($path !== null && $path !== '') {
        file_put_contents($path, "ready\n", LOCK_EX);
    }
}

function billingAwait(?string $path, int $timeoutMs = 15000): void
{
    if ($path === null || $path === '') {
        return;
    }
    $started = hrtime(true);
    while (! is_file($path)) {
        usleep(10_000);
        if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
            throw new RuntimeException("Timed out waiting for Contractual Billing barrier: {$path}");
        }
    }
}

try {
    billingAwait($payload['start_barrier'] ?? null, (int) ($payload['barrier_timeout_ms'] ?? 15000));
    $action = (string) ($payload['action'] ?? '');

    if (in_array($action, ['hold_tenant', 'hold_membership', 'hold_receivable'], true)) {
        DB::transaction(function () use ($payload, $action): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");

            if ($action === 'hold_tenant') {
                DB::table('tenants')->where('id', $payload['tenant_id'])->lockForUpdate()->firstOrFail();
            } elseif ($action === 'hold_membership') {
                DB::table('tenant_users')->where('tenant_id', $payload['tenant_id'])->where('user_id', $payload['actor_id'])->lockForUpdate()->firstOrFail();
            } else {
                DB::table('receivables')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['receivable_id'])->lockForUpdate()->firstOrFail();
            }

            billingSignal($payload['ready_file'] ?? null);
            billingAwait($payload['release_file'] ?? null, (int) ($payload['barrier_timeout_ms'] ?? 15000));
        });
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'primitive_cancel_rollback') {
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement("SET LOCAL statement_timeout = '8s'");
            $link = DB::table('entitlement_receivable_links')->where('tenant_id', $payload['tenant_id'])->where('receivable_id', $payload['receivable_id'])->lockForUpdate()->firstOrFail();
            $receivable = DB::table('receivables')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['receivable_id'])->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($payload['actor_id']);
            app(CancelLinkedReceivablePrimitive::class)->cancelLocked(
                $payload['tenant_id'],
                $link,
                $receivable,
                $actor,
                $payload['source_correction_operation_id'],
                CarbonImmutable::now('UTC'),
                'R009 primitive probe',
            );
            DB::rollBack();
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            return;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    if ($action === 'finalize_schedule') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '6s'");
            DB::statement("SET LOCAL statement_timeout = '15s'");
            billingSignal($payload['entered_file'] ?? null);
            DB::table('contractual_billing_schedules')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['schedule_id'])->update([
                'status' => 'finalized',
                'contractual_timezone' => $payload['timezone'],
                'finalization_operation_id' => $payload['finalization_operation_id'],
                'finalized_by' => $payload['actor_id'],
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);
        });
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'probe_schedule_nowait') {
        DB::transaction(fn () => DB::table('contractual_billing_schedules')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['schedule_id'])->lock('FOR UPDATE NOWAIT')->firstOrFail());
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'update_contract_total') {
        DB::transaction(function () use ($payload): void {
            DB::statement("SET LOCAL lock_timeout = '6s'");
            DB::statement("SET LOCAL statement_timeout = '15s'");
            DB::table('contracts')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['contract_id'])->update(['total_amount' => $payload['total_amount'], 'updated_at' => now()]);
        });
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'activate_entitlement_action') {
        $actor = User::query()->findOrFail($payload['actor_id']);
        $id = app(ActivateContractualBillingEntitlement::class)->execute($payload['tenant_id'], $payload['obligation_id'], $actor, ['billing_entitlement_operation_id' => $payload['operation_id']]);
        echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'establish_entitlement_receivable_action') {
        $actor = User::query()->findOrFail($payload['actor_id']);
        $id = app(EstablishEntitlementReceivable::class)->execute($payload['tenant_id'], $payload['entitlement_id'], $actor, ['receivable_establishment_operation_id' => $payload['operation_id']]);
        echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'ordinary_cancel_receivable_action') {
        $actor = User::query()->findOrFail($payload['actor_id']);
        app(CancelReceivableAction::class)->execute($payload['tenant_id'], $payload['receivable_id'], $actor, $payload['cancelled_at'], $payload['reason']);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'cancel_finalized_source_action') {
        $actor = User::query()->findOrFail($payload['actor_id']);
        $id = app(CorrectFinalizedContractualBillingSchedule::class)->execute($payload['tenant_id'], $payload['schedule_id'], $actor, [
            'source_correction_operation_id' => $payload['source_correction_operation_id'],
            'source_correction_reason' => $payload['source_correction_reason'],
            'source_correction_reference' => $payload['source_correction_reference'] ?? null,
            'entitlement_reversals' => $payload['entitlement_reversals'],
        ]);
        echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'supersede_source_action') {
        $actor = User::query()->findOrFail($payload['actor_id']);
        $id = app(SupersedeFinalizedContractualBillingSchedule::class)->execute($payload['tenant_id'], $payload['source_schedule_id'], $payload['successor_schedule_id'], $actor, [
            'source_correction_operation_id' => $payload['source_correction_operation_id'],
            'successor_finalization_operation_id' => $payload['successor_finalization_operation_id'],
            'source_correction_reason' => $payload['source_correction_reason'],
            'source_correction_reference' => $payload['source_correction_reference'] ?? null,
            'entitlement_reversals' => $payload['entitlement_reversals'],
        ]);
        echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR);
        return;
    }

    if ($action === 'insert_entitlement') {
        DB::table('contractual_billing_entitlements')->insert([
            'id' => $payload['entitlement_id'], 'tenant_id' => $payload['tenant_id'], 'billing_entitlement_operation_id' => $payload['operation_id'],
            'schedule_id' => $payload['schedule_id'], 'obligation_id' => $payload['obligation_id'], 'contract_id' => $payload['contract_id'],
            'customer_id' => $payload['customer_id'], 'amount' => $payload['amount'], 'currency' => 'SAR', 'economic_date' => $payload['economic_date'],
            'effective_at' => now(), 'status' => 'effective', 'recognized_by' => $payload['actor_id'], 'recognized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        return;
    }

    throw new InvalidArgumentException('Unsupported Contractual Billing concurrency worker action.');
} catch (Throwable $exception) {
    $sqlstate = null;
    if ($exception instanceof QueryException) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? '');
    } elseif ($exception instanceof PDOException) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode() ?? '');
    }
    echo json_encode(['ok' => false, 'class' => $exception::class, 'message' => $exception->getMessage(), 'sqlstate' => $sqlstate], JSON_THROW_ON_ERROR);
}
