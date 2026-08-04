<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Leads\Actions\ConvertLeadToCustomerAction;
use App\Modules\Leads\Exceptions\LeadConversionNewCustomerConflictException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = [];

try {
    $payload = json_decode(
        base64_decode($argv[1] ?? '', true),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $schema = getenv('LEADS_CONCURRENCY_SCHEMA');
    if (! is_string($schema) || preg_match('/\A[a-z_][a-z0-9_]*\z/', $schema) !== 1) {
        throw new RuntimeException('Invalid concurrency schema.');
    }

    $connectionName = DB::getDefaultConnection();
    config(["database.connections.{$connectionName}.search_path" => $schema]);
    DB::purge($connectionName);
    DB::reconnect($connectionName);

    $readyFile = $payload['ready_file'] ?? null;
    $releaseFile = $payload['release_file'] ?? null;
    if (! is_string($readyFile) || ! is_string($releaseFile)) {
        throw new RuntimeException('Worker synchronization files are missing.');
    }

    touch($readyFile);
    $deadline = microtime(true) + 15;
    while (! is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for conversion release.');
        }
        usleep(1_000);
    }

    $actor = User::query()->findOrFail($payload['actor_id']);

    /** @var ConvertLeadToCustomerAction $action */
    $action = app(ConvertLeadToCustomerAction::class);
    $lead = $action->execute(
        tenantId: $payload['tenant_id'],
        leadId: $payload['lead_id'],
        actorId: $actor->id,
        conversionIntent: 'create_new',
        existingCustomerId: null,
        customerData: ['type' => 'individual', 'category' => 'buyer'],
    );

    echo json_encode([
        'ok' => true,
        'lead_id' => $payload['lead_id'],
        'customer_id' => $lead->customer_id,
    ], JSON_THROW_ON_ERROR);
} catch (LeadConversionNewCustomerConflictException $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 409,
        'code' => 'lead_conversion_new_customer_conflict',
        'lead_id' => $payload['lead_id'] ?? null,
        'customer_id' => $exception->conflictingCustomerId,
        'conflict_source' => $exception->conflictSource,
    ], JSON_THROW_ON_ERROR);
    exit(1);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 500,
        'lead_id' => $payload['lead_id'] ?? null,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
