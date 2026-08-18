<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DatabaseCollectionAuditRecorder implements CollectionAuditRecorderInterface
{
    public function record(
        string $tenantId,
        string $contractId,
        string $event,
        int|string $actorId,
        array $context = [],
    ): void {
        DB::table('collection_schedule_audits')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'event' => $event,
            'actor_id' => (int) $actorId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'recorded_at' => now(),
        ]);

        Log::info('collection_domain_audit', array_merge([
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'event' => $event,
            'actor_id' => $actorId,
        ], $context));
    }
}
