<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

if (app()->environment('testing') === false || str_ends_with((string) config('database.connections.pgsql.database'), '_testing') === false) {
    throw new RuntimeException('Consideration concurrency workers require an isolated testing database.');
}
DB::selectOne("SELECT set_config('application_name', ?, false)", [$payload['name']]);

function considerationBarrier(array $payload): void
{
    file_put_contents($payload['ready'], 'ready');
    $deadline = microtime(true) + 12;
    while (is_file($payload['release']) === false) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Consideration barrier timed out.');
        }
        usleep(10_000);
    }
}

try {
    $result = DB::transaction(function () use ($payload): array {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '20s'");
        $action = $payload['action'];
        if (in_array($action, ['hold_contract', 'change_capacity'], true)) {
            DB::table('contracts')->where('tenant_id', $payload['tenant_id'])->where('id', $payload['contract_id'])->lockForUpdate()->firstOrFail();
            considerationBarrier($payload);
            if ($action === 'change_capacity') {
                DB::table('contracts')->where('id', $payload['contract_id'])->update(['total_amount' => '1200.00']);
            }

            return [];
        }
        if ($action === 'suspend_membership') {
            $query = DB::table('tenant_users')->where('tenant_id', $payload['tenant_id'])->where('user_id', $payload['actor_id']);
            $query->lockForUpdate()->firstOrFail();
            considerationBarrier($payload);
            $query->update(['status' => 'suspended']);

            return [];
        }
        $actor = User::query()->findOrFail($payload['actor_id']);
        if (in_array($action, ['billing', 'billing_hold'], true)) {
            $result = ['source_id' => app(ActivateContractualBillingEntitlement::class)->execute(
                $payload['tenant_id'], $payload['obligation_id'], $actor,
                ['billing_entitlement_operation_id' => $payload['operation_id']],
            )];
        } else {
            $result = app(AdoptContractConsideration::class)->execute($payload['tenant_id'], $actor, [
                'contract_id' => $payload['contract_id'], 'coordination_adoption_operation_id' => $payload['operation_id'],
            ]);
        }
        if (str_ends_with($action, '_hold')) {
            considerationBarrier($payload);
        }

        return $result;
    });
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'class' => $exception::class], JSON_THROW_ON_ERROR);
}
