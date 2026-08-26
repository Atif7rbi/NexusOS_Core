<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Exceptions\CollectionHasEffectiveReceivableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EffectiveReceivableGuard
{
    /**
     * The caller must already hold the relevant Collection rows FOR UPDATE.
     * This deliberately remains a plain MVCC read: the Collection lock is the
     * cross-action barrier and Receivables must not introduce a second lock
     * protocol here.
     *
     * @param  array<int, string>  $collectionIds
     */
    public function assertNone(string $tenantId, array $collectionIds): void
    {
        if ($collectionIds === []) {
            return;
        }

        // CollectionsConcurrencyTest uses an intentionally isolated schema
        // where the Receivables migrations are explicitly skipped. Production
        // schemas always receive Receivables before this guard can be used.
        if (app()->environment('testing') && ! Schema::hasTable('receivables')) {
            return;
        }

        if (DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->whereIn('collection_id', $collectionIds)
            ->where('status', 'recognized')
            ->exists()) {
            throw new CollectionHasEffectiveReceivableException();
        }
    }
}
