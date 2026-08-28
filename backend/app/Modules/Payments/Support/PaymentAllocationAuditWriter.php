<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentAllocationAuditWriter
{
    public function write(string $tenantId, string $event, string $subjectType, string $subjectId, int $actorId, array $context = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment audit requires an active transaction.');
        }

        DB::table('payment_allocation_audits')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'actor_id' => $actorId,
            'context' => json_encode((object) $context, JSON_THROW_ON_ERROR),
            'recorded_at' => now(),
        ]);
    }
}
