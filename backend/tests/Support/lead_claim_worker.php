<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Leads\Actions\ClaimLeadAction;
use App\Modules\Leads\Exceptions\LeadClaimConflictException;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$schema = getenv('LEADS_CONCURRENCY_SCHEMA');
if (is_string($schema) && preg_match('/\A[a-z_][a-z0-9_]*\z/', $schema) === 1) {
    $connectionName = config('database.default');
    config(["database.connections.{$connectionName}.search_path" => $schema]);
    DB::purge($connectionName);
    DB::reconnect($connectionName);
}

try {
    $payload = json_decode(
        base64_decode($argv[1] ?? '', true),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $barrier = $payload['barrier'] ?? null;
    $deadline = microtime(true) + 10;

    while (is_string($barrier) && ! is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the Lead claim barrier.');
        }

        usleep(1_000);
    }

    $actor = User::query()->findOrFail($payload['actor_id']);
    $lead = app(ClaimLeadAction::class)->execute(
        tenantId: $payload['tenant_id'],
        leadId: $payload['lead_id'],
        actor: $actor,
    );

    echo json_encode([
        'ok' => true,
        'actor_id' => $actor->id,
        'assigned_to' => $lead->assigned_to,
    ], JSON_THROW_ON_ERROR);
} catch (LeadClaimConflictException|LeadNotFoundException $exception) {
    // In this isolated race, the losing worker may observe the Lead only after
    // the winner commits. The visibility contract then hides the now-assigned
    // Lead with LeadNotFoundException, which is still the same claim conflict
    // outcome for this concurrently coordinated worker pair.
    echo json_encode([
        'ok' => false,
        'status' => 409,
        'code' => 'lead_claim_conflict',
        'class' => $exception::class,
    ], JSON_THROW_ON_ERROR);

    exit(1);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 500,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);

    exit(1);
}
