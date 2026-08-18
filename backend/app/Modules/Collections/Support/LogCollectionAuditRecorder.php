<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;

/**
 * Backward-compatible recorder name retained for existing Action defaults.
 * Audit persistence is now durable; structured application logging remains
 * a secondary side effect of DatabaseCollectionAuditRecorder.
 */
final class LogCollectionAuditRecorder implements CollectionAuditRecorderInterface
{
    public function __construct(
        private readonly DatabaseCollectionAuditRecorder $databaseRecorder = new DatabaseCollectionAuditRecorder(),
    ) {
    }

    public function record(
        string $tenantId,
        string $contractId,
        string $event,
        int|string $actorId,
        array $context = [],
    ): void {
        $this->databaseRecorder->record(
            $tenantId,
            $contractId,
            $event,
            $actorId,
            $context,
        );
    }
}
