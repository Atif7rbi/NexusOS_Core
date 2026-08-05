<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Leads\Actions\CancelLeadFollowUpAction;
use App\Modules\Leads\Actions\CompleteLeadFollowUpAction;
use App\Modules\Leads\Exceptions\LeadFollowUpConflictException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$schema = getenv('LEADS_CONCURRENCY_SCHEMA');

if (
    is_string($schema)
    && preg_match('/\A[a-z_][a-z0-9_]*\z/', $schema) === 1
) {
    $connectionName = config('database.default');

    config([
        "database.connections.{$connectionName}.search_path" => $schema,
    ]);

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
            throw new RuntimeException(
                'Timed out waiting for the Lead follow-up barrier.',
            );
        }

        usleep(1_000);
    }

    $actor = User::query()->findOrFail($payload['actor_id']);
    $operation = $payload['operation'] ?? null;

    $lead = match ($operation) {
        'complete' => app(CompleteLeadFollowUpAction::class)->execute(
            tenantId: $payload['tenant_id'],
            leadId: $payload['lead_id'],
            actor: $actor,
            note: 'Concurrent complete.',
        ),
        'cancel' => app(CancelLeadFollowUpAction::class)->execute(
            tenantId: $payload['tenant_id'],
            leadId: $payload['lead_id'],
            actor: $actor,
            note: 'Concurrent cancel.',
        ),
        default => throw new InvalidArgumentException(
            'Unsupported follow-up operation.',
        ),
    };

    echo json_encode([
        'ok' => true,
        'operation' => $operation,
        'lead_id' => (string) $lead->id,
    ], JSON_THROW_ON_ERROR);
} catch (LeadFollowUpConflictException $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 409,
        'code' => 'lead_follow_up_conflict',
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
