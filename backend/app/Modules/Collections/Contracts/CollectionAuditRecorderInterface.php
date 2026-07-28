<?php

declare(strict_types=1);

namespace App\Modules\Collections\Contracts;

/**
 * Integration point for Collection domain audit generation
 * (DDL spec section 31: "Audit generation").
 *
 * No Audit module or `audits` table exists anywhere in this codebase yet
 * (verified: no audit-related migration, table, or package is present).
 * Rather than invent audit persistence — which would be a schema/
 * architecture decision outside this branch's scope — this interface
 * defines the integration point the Collection Actions call. The default
 * implementation (LogCollectionAuditRecorder) writes to the application
 * log so behaviour is observable today; a real Audit module can implement
 * this interface later without changing any Action.
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
