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

        $summary = $this->summary(
            clone $baseQuery,
            $tenantTimezone,
            $filters,
        );
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
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    private function summary(
        Builder $query,
        string $tenantTimezone,
        array $filters,
    ): array {
        $now = CarbonImmutable::now($tenantTimezone);
        $todayStart = $now->startOfDay();
        $tomorrowStart = $todayStart->addDay();
        $monthStart = $now->startOfMonth();
        $nextMonthStart = $monthStart->addMonth()->startOfMonth();

        [$selectedStart, $selectedEndExclusive] = $this->selectedDateRange(
            $filters,
            $tenantTimezone,
        );

        $row = $query
            ->reorder()
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where stage in ('new', 'qualified', 'viewing', 'negotiation')
                          and archived_at is null
                    ) as open_leads
                SQL,
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where next_follow_up_at < ?
                          and stage in ('new', 'qualified', 'viewing', 'negotiation')
                          and archived_at is null
                    ) as overdue_follow_ups
                SQL,
                [$todayStart->utc()],
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where next_follow_up_at >= ?
                          and next_follow_up_at < ?
                          and stage in ('new', 'qualified', 'viewing', 'negotiation')
                          and archived_at is null
                    ) as today_follow_ups
                SQL,
                [$todayStart->utc(), $tomorrowStart->utc()],
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where assigned_to is null
                          and stage in ('new', 'qualified', 'viewing', 'negotiation')
                          and archived_at is null
                    ) as unassigned_leads
                SQL,
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where converted_at >= ? and converted_at < ?
                    ) as monthly_conversions
                SQL,
                [$monthStart->utc(), $nextMonthStart->utc()],
            )
            ->selectRaw(
                <<<'SQL'
                    count(*) filter (
                        where lost_at >= ? and lost_at < ?
                    ) as lost_leads_in_selected_period
                SQL,
                [$selectedStart->utc(), $selectedEndExclusive->utc()],
            )
            ->firstOrFail();

        return [
            'open_leads' => (int) $row->open_leads,
            'overdue_follow_ups' => (int) $row->overdue_follow_ups,
            'today_follow_ups' => (int) $row->today_follow_ups,
            'unassigned_leads' => (int) $row->unassigned_leads,
            'monthly_conversions' => (int) $row->monthly_conversions,
            'lost_leads_in_selected_period' => (int) $row->lost_leads_in_selected_period,
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
            if (
                array_key_exists($filter, $filters)
                && $filters[$filter] !== null
                && $filters[$filter] !== ''
            ) {
                $query->where($filter, $filters[$filter]);
            }
        }

        $this->applyLifecycle($query, $filters['lifecycle'] ?? null);

        $followUpState = $filters['follow_up_state']
            ?? $filters['follow_up_bucket']
            ?? null;

        if ($followUpState !== null && $followUpState !== '') {
            $this->applyFollowUpState(
                $query,
                (string) $followUpState,
                $tenantTimezone,
            );
        } elseif (($filters['overdue'] ?? false) === true) {
            $todayStart = CarbonImmutable::now($tenantTimezone)->startOfDay();

            $query->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $todayStart->utc())
                ->whereIn('stage', LeadStage::openValues())
                ->whereNull('archived_at');
        }

        $this->applyOperationalDateRange(
            $query,
            $filters,
            $tenantTimezone,
        );
    }

    private function applyFollowUpState(
        Builder $query,
        string $bucket,
        string $tenantTimezone,
    ): void {
        $todayStart = CarbonImmutable::now($tenantTimezone)->startOfDay();
        $tomorrowStart = $todayStart->addDay();
        $dayAfterTomorrowStart = $tomorrowStart->addDay();
        $nextWeekStart = $todayStart
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->addWeek();

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
                ->where('next_follow_up_at', '<', $nextWeekStart->utc()),
            'scheduled_later' => $query
                ->where('next_follow_up_at', '>=', $nextWeekStart->utc()),
            'unscheduled' => $query->whereNull('next_follow_up_at'),
            default => null,
        };
    }

    private function applyLifecycle(Builder $query, mixed $lifecycle): void
    {
        match ($lifecycle) {
            'open' => $query->whereIn('stage', LeadStage::openValues()),
            'won' => $query->where('stage', LeadStage::Won->value),
            'lost' => $query->where('stage', LeadStage::Lost->value),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyOperationalDateRange(
        Builder $query,
        array $filters,
        string $tenantTimezone,
    ): void {
        if (
            empty($filters['date_from'])
            && empty($filters['date_to'])
        ) {
            return;
        }

        if (! empty($filters['date_from'])) {
            $start = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_from'],
                $tenantTimezone,
            )->startOfDay();

            $query->where('next_follow_up_at', '>=', $start->utc());
        }

        if (! empty($filters['date_to'])) {
            $endExclusive = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_to'],
                $tenantTimezone,
            )->addDay()->startOfDay();

            $query->where('next_follow_up_at', '<', $endExclusive->utc());
        }
    }

    /**
     * When no interval is selected, use the current Riyadh calendar month.
     *
     * @param array<string, mixed> $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function selectedDateRange(
        array $filters,
        string $tenantTimezone,
    ): array {
        $now = CarbonImmutable::now($tenantTimezone);

        $start = empty($filters['date_from'])
            ? $now->startOfMonth()
            : CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_from'],
                $tenantTimezone,
            )->startOfDay();

        $endExclusive = empty($filters['date_to'])
            ? (
                empty($filters['date_from'])
                    ? $now->addMonth()->startOfMonth()
                    : $start->addDay()
            )
            : CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_to'],
                $tenantTimezone,
            )->addDay()->startOfDay();

        return [$start, $endExclusive];
    }
}
