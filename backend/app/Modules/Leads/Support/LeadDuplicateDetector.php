<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class LeadDuplicateDetector
{
    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * Return only duplicate Leads visible to the current actor.
     *
     * @return Collection<int, Lead>
     */
    public function visibleMatches(
        string $tenantId,
        User $actor,
        mixed $phone,
        ?string $exceptLeadId = null,
    ): Collection {
        $canonicalPhone = $this->phoneNormalizer->normalizeRequired($phone);

        return Lead::query()
            ->forTenant($tenantId)
            ->visibleTo($actor)
            ->activeOnly()
            ->where('phone', $canonicalPhone)
            ->when(
                $exceptLeadId !== null,
                fn (Builder $query): Builder => $query->where(
                    'id',
                    '<>',
                    $exceptLeadId,
                ),
            )
            ->with('assignee:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }
}
