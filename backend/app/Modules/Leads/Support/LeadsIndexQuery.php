<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Models\User;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class LeadsIndexQuery
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
        private readonly LeadResponseAssembler $assembler,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function execute(
        string $tenantId,
        string $tenantTimezone,
        User $actor,
        array $filters,
    ): array {
        $this->authorization->assertActor($actor, $tenantId);

        $archivedMode = $this->authorization->isAdministrator($actor)
            && ($filters['archived'] ?? false) === true;
        $baseQuery = Lead::query()
            ->forTenant($tenantId)
            ->visibleTo($actor);

        if ($archivedMode) {
            $baseQuery->archivedOnly();
        } else {
            $baseQuery->activeOnly();
        }

        $summary = $this->summary(clone $baseQuery, $tenantTimezone);
        $query = $baseQuery->with([
            'project:id,name,archived_at',
            'unit:id,project_id,unit_number,status,archived_at',
            'assignee:id,name,role,status',
            'assignee.tenantMemberships',
            'customer:id,name,status,archived_at',
        ]);

        $this->applyFilters($query, $filters, $tenantTimezone);

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return [
            'leads' => collect($paginator->items())
                ->map(fn (Lead $lead): array => $this->assembler->lead($lead, $actor))
                ->values()
                ->all(),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(Builder $query, string $tenantTimezone): array
    {
        $monthStart = CarbonImmutable::now($tenantTimezone)
            ->startOfMonth()
            ->utc();
        $nextMonthStart = $monthStart
            ->setTimezone($tenantTimezone)
            ->addMonth()
            ->startOfMonth()
            ->utc();
        $now = CarbonImmutable::now('UTC');
        $row = $query
            ->reorder()
            ->selectRaw(<<<'SQL'
                count(*) filter (
                    where stage in ('new', 'qualified', 'viewing', 'negotiation')
                ) as active
            SQL)
            ->selectRaw('count(*) filter (where assigned_to is null) as unassigned')
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where next_follow_up_at < ?
                          and stage in ('new', 'qualified', 'viewing', 'negotiation')
                          and archived_at is null
                    ) as overdue
                SQL,
                [$now],
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where converted_at >= ? and converted_at < ?
                    ) as converted_this_month
                SQL,
                [$monthStart, $nextMonthStart],
            )
            ->firstOrFail();

        return [
            'active' => (int) $row->active,
            'unassigned' => (int) $row->unassigned,
            'overdue' => (int) $row->overdue,
            'converted_this_month' => (int) $row->converted_this_month,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(
        Builder $query,
        array $filters,
        string $tenantTimezone,
    ): void {
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('name', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        foreach (['stage', 'source', 'assigned_to', 'project_id'] as $filter) {
            if (array_key_exists($filter, $filters) && $filters[$filter] !== null) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (isset($filters['follow_up_bucket'])) {
            $this->applyFollowUpBucket(
                $query,
                (string) $filters['follow_up_bucket'],
                $tenantTimezone,
            );

            return;
        }

        if (($filters['overdue'] ?? false) === true) {
            $query->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', CarbonImmutable::now('UTC'))
                ->whereIn('stage', LeadStage::openValues())
                ->whereNull('archived_at');
        }
    }

    private function applyFollowUpBucket(
        Builder $query,
        string $bucket,
        string $tenantTimezone,
    ): void {
        $todayStart = CarbonImmutable::now($tenantTimezone)->startOfDay();
        $tomorrowStart = $todayStart->addDay();
        $dayAfterTomorrowStart = $tomorrowStart->addDay();
        $weekEndExclusive = $todayStart->endOfWeek()->addMicrosecond();

        $query
            ->whereIn('stage', LeadStage::openValues())
            ->whereNull('archived_at');

        match ($bucket) {
            'overdue' => $query
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $todayStart->utc()),
            'today' => $query
                ->where('next_follow_up_at', '>=', $todayStart->utc())
                ->where('next_follow_up_at', '<', $tomorrowStart->utc()),
            'tomorrow' => $query
                ->where('next_follow_up_at', '>=', $tomorrowStart->utc())
                ->where('next_follow_up_at', '<', $dayAfterTomorrowStart->utc()),
            'this_week' => $query
                ->where('next_follow_up_at', '>=', $dayAfterTomorrowStart->utc())
                ->where('next_follow_up_at', '<', $weekEndExclusive->utc()),
            'unscheduled' => $query->whereNull('next_follow_up_at'),
            default => null,
        };
    }
}
