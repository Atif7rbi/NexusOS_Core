<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CollectionsIndexQuery
{
    private const SCHEDULE_STATE_SQL = <<<'SQL'
        CASE
            WHEN COALESCE(collection_totals.collection_count, 0) = 0 THEN 'absent'
            WHEN COALESCE(collection_totals.active_count, 0) = 0 THEN 'cancelled'
            WHEN collection_totals.active_count = collection_totals.draft_count THEN 'draft'
            WHEN collection_totals.active_count = collection_totals.scheduled_count THEN 'scheduled'
            ELSE 'invalid'
        END
        SQL;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   pagination: array<string, int>,
     *   summary: array<string, int>
     * }
     */
    public function execute(string $tenantId, array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $itemsQuery = $this->validContracts($tenantId);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $itemsQuery->where(function (Builder $query) use ($search): void {
                $query
                    ->whereRaw(
                        'STRPOS(LOWER(customers.name), LOWER(?)) > 0',
                        [$search],
                    )
                    ->orWhereRaw(
                        'STRPOS(LOWER(projects.name), LOWER(?)) > 0',
                        [$search],
                    )
                    ->orWhereRaw(
                        'STRPOS(LOWER(units.unit_number), LOWER(?)) > 0',
                        [$search],
                    );
            });
        }

        if (! empty($filters['status'])) {
            $itemsQuery->where('contracts.status', $filters['status']);
        }

        if (! empty($filters['schedule_state'])) {
            $itemsQuery->whereRaw(
                self::SCHEDULE_STATE_SQL.' = ?',
                [$filters['schedule_state']],
            );
        }

        if (($filters['sort'] ?? null) === 'next_scheduled_collection') {
            $itemsQuery
                ->orderByRaw('next_scheduled_collections.due_date ASC NULLS LAST')
                ->orderBy('next_scheduled_collections.sequence')
                ->orderBy('next_scheduled_collections.id');
        } else {
            $itemsQuery->orderByDesc('contracts.created_at');
        }

        $paginator = $itemsQuery->paginate(
            $perPage,
            ['*'],
            'page',
            $page,
        );

        $summary = DB::query()
            ->fromSub(
                $this->validContracts($tenantId),
                'indexed_contracts',
            )
            ->selectRaw('COUNT(*) AS total_contracts')
            ->selectRaw("COUNT(*) FILTER (WHERE schedule_state = 'scheduled') AS scheduled_count")
            ->selectRaw("COUNT(*) FILTER (WHERE schedule_state = 'draft') AS draft_count")
            ->selectRaw("COUNT(*) FILTER (WHERE schedule_state = 'absent') AS absent_count")
            ->selectRaw("COUNT(*) FILTER (WHERE schedule_state = 'cancelled') AS cancelled_count")
            ->first();

        return [
            'items' => array_map(
                fn (object $item): array => $this->item($item),
                $paginator->items(),
            ),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total_contracts' => (int) ($summary->total_contracts ?? 0),
                'scheduled_count' => (int) ($summary->scheduled_count ?? 0),
                'draft_count' => (int) ($summary->draft_count ?? 0),
                'absent_count' => (int) ($summary->absent_count ?? 0),
                'cancelled_count' => (int) ($summary->cancelled_count ?? 0),
            ],
        ];
    }

    private function validContracts(string $tenantId): Builder
    {
        return $this->baseQuery($tenantId)
            ->whereRaw(self::SCHEDULE_STATE_SQL." <> 'invalid'");
    }

    private function baseQuery(string $tenantId): Builder
    {
        return DB::table('contracts')
            ->leftJoin('reservations', function ($join): void {
                $join
                    ->on('reservations.id', '=', 'contracts.reservation_id')
                    ->on('reservations.tenant_id', '=', 'contracts.tenant_id');
            })
            ->leftJoin('units', function ($join): void {
                $join
                    ->on('units.id', '=', 'reservations.unit_id')
                    ->on('units.tenant_id', '=', 'contracts.tenant_id');
            })
            ->leftJoin('customers', function ($join): void {
                $join
                    ->on('customers.id', '=', 'reservations.customer_id')
                    ->on('customers.tenant_id', '=', 'contracts.tenant_id');
            })
            ->leftJoin('projects', function ($join): void {
                $join
                    ->on('projects.id', '=', 'units.project_id')
                    ->on('projects.tenant_id', '=', 'contracts.tenant_id');
            })
            ->leftJoinSub(
                $this->collectionTotals(),
                'collection_totals',
                function ($join): void {
                    $join
                        ->on('collection_totals.tenant_id', '=', 'contracts.tenant_id')
                        ->on('collection_totals.contract_id', '=', 'contracts.id');
                },
            )
            ->leftJoinSub(
                $this->nextScheduledCollections(),
                'next_scheduled_collections',
                function ($join): void {
                    $join
                        ->on('next_scheduled_collections.tenant_id', '=', 'contracts.tenant_id')
                        ->on('next_scheduled_collections.contract_id', '=', 'contracts.id');
                },
            )
            ->where('contracts.tenant_id', $tenantId)
            ->select([
                'contracts.id AS contract_id',
                'contracts.status AS contract_status',
                'contracts.total_amount AS contract_total_amount',
                'contracts.currency',
                'customers.name AS customer_name',
                'units.unit_number',
                'projects.name AS project_name',
                'next_scheduled_collections.id AS next_scheduled_collection_id',
                'next_scheduled_collections.title AS next_scheduled_collection_title',
                'next_scheduled_collections.amount AS next_scheduled_collection_amount',
                'next_scheduled_collections.due_date AS next_scheduled_collection_due_date',
            ])
            ->selectRaw(self::SCHEDULE_STATE_SQL.' AS schedule_state')
            ->selectRaw('COALESCE(collection_totals.active_total, 0) AS schedule_active_total');
    }

    private function collectionTotals(): Builder
    {
        return DB::table('collections')
            ->select(['tenant_id', 'contract_id'])
            ->selectRaw('COUNT(*) AS collection_count')
            ->selectRaw("COUNT(*) FILTER (WHERE status <> 'cancelled') AS active_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'draft') AS draft_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'scheduled') AS scheduled_count")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE status <> 'cancelled'), 0) AS active_total")
            ->groupBy(['tenant_id', 'contract_id']);
    }

    private function nextScheduledCollections(): Builder
    {
        $ranked = DB::table('collections')
            ->where('status', 'scheduled')
            ->select([
                'id',
                'tenant_id',
                'contract_id',
                'sequence',
                'title',
                'amount',
                'due_date',
            ])
            ->selectRaw(
                <<<'SQL'
                    ROW_NUMBER() OVER (
                        PARTITION BY tenant_id, contract_id
                        ORDER BY due_date ASC, sequence ASC, id ASC
                    ) AS schedule_position
                SQL,
            );

        return DB::query()
            ->fromSub($ranked, 'ranked_scheduled_collections')
            ->where('schedule_position', 1);
    }

    /** @return array<string, mixed> */
    private function item(object $item): array
    {
        return [
            'contract_id' => (string) $item->contract_id,
            'contract_status' => (string) $item->contract_status,
            'contract_total_amount' => $this->decimal($item->contract_total_amount),
            'currency' => (string) $item->currency,
            'customer_name' => $item->customer_name === null
                ? null
                : (string) $item->customer_name,
            'unit_number' => $item->unit_number === null
                ? null
                : (string) $item->unit_number,
            'project_name' => $item->project_name === null
                ? null
                : (string) $item->project_name,
            'schedule_state' => (string) $item->schedule_state,
            'schedule_active_total' => $this->decimal($item->schedule_active_total),
            'next_scheduled_collection' => $item->next_scheduled_collection_id === null
                ? null
                : [
                    'id' => (string) $item->next_scheduled_collection_id,
                    'title' => (string) $item->next_scheduled_collection_title,
                    'amount' => $this->decimal($item->next_scheduled_collection_amount),
                    'due_date' => (string) $item->next_scheduled_collection_due_date,
                ],
        ];
    }

    private function decimal(mixed $value): string
    {
        $decimal = (string) $value;

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $matches)) {
            throw new \UnexpectedValueException('Database returned an invalid decimal value.');
        }

        return $matches[1].$matches[2].'.'.str_pad(
            substr($matches[3] ?? '', 0, 2),
            2,
            '0',
        );
    }
}
