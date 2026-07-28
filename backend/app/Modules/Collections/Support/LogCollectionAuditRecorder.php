<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Default CollectionAuditRecorderInterface implementation. Writes a
 * structured log line. This is a deliberate placeholder — see the
 * interface docblock — and is not a substitute for a real Audit module.
 */
final class LogCollectionAuditRecorder implements CollectionAuditRecorderInterface
{
    public function record(
        string $tenantId,
        string $contractId,
        string $event,
        int|string $actorId,
        array $context = [],
    ): void {
        Log::info('collection_domain_audit', array_merge([
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'event' => $event,
            'actor_id' => $actorId,
        ], $context));
    }
}
