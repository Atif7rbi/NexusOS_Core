<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\DTOs\CollectionLineData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$schema = getenv('COLLECTIONS_CONCURRENCY_SCHEMA');
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

    $lines = array_map(
        static fn (array $line): CollectionLineData => new CollectionLineData(
            id: $line['id'] ?? null,
            sequence: $line['sequence'],
            title: $line['title'],
            amount: $line['amount'],
            dueDate: CarbonImmutable::parse($line['due_date']),
            notes: $line['notes'] ?? null,
        ),
        $payload['lines'] ?? [],
    );

    $auditRecorder = null;
    if ($payload['action'] === 'amend' && is_string($payload['audit_log_path'] ?? null)) {
        $auditRecorder = new class($payload['audit_log_path']) implements CollectionAuditRecorderInterface
        {
            public function __construct(private readonly string $path) {}

            public function record(
                string $tenantId,
                string $contractId,
                string $event,
                int|string $actorId,
                array $context = [],
            ): void {
                file_put_contents($this->path, "{$event}\n", FILE_APPEND | LOCK_EX);
            }
        };
    }

    match ($payload['action']) {
        'finalize' => (new FinalizeCollectionScheduleAction)->execute(
            $payload['tenant_id'],
            $payload['contract_id'],
            $payload['actor_id'],
        ),
        'amend' => (new AmendCollectionScheduleAction(auditRecorder: $auditRecorder))->execute(
            $payload['tenant_id'],
            $payload['contract_id'],
            $payload['actor_id'],
            $payload['expected_active_collection_ids'],
            $lines,
            $payload['cancellation_reason'],
        ),
        default => throw new InvalidArgumentException('Unsupported collection action worker command.'),
    };

    echo json_encode(['ok' => true, 'action' => $payload['action']], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);

    exit(1);
}
