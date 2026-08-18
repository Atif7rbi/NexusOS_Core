<?php

declare(strict_types=1);

namespace App\Modules\Collections\Contracts;

/**
 * Durable audit integration point for Collection schedule lifecycle events.
 * Implementations must persist the audit inside the caller's transaction so
 * a failed audit write rolls back the business mutation as well.
 */
interface CollectionAuditRecorderInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $tenantId,
        string $contractId,
        string $event,
        int|string $actorId,
        array $context = [],
    ): void;
}
