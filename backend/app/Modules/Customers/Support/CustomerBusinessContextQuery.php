<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use App\Modules\Contracts\Models\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class CustomerBusinessContextQuery
{
    public function __construct(
        private readonly DerivedScheduleStateResolver $stateResolver,
    ) {}

    /**
     * @return array{
     *   summary: array{reservations_total: int, contracts_total: int},
     *   journeys: array<int, array<string, mixed>>
     * }
     */
    public function execute(string $tenantId, string $customerId): array
    {
        $reservations = $this->reservations($tenantId, $customerId);
        $reservationIds = $reservations->pluck('reservation_id')->all();
        $contracts = $this->contracts($tenantId, $reservationIds);
        $collectionsByContract = $this->collectionsByContract(
            $tenantId,
            $contracts->pluck('id')->all(),
        );
        $contractsByReservation = $contracts->groupBy('reservation_id');

        return [
            'summary' => [
                'reservations_total' => $reservations->count(),
                'contracts_total' => $contracts->count(),
            ],
            'journeys' => $reservations
                ->map(fn (object $reservation): array => [
                    'reservation' => $this->reservation($reservation),
                    'project' => $this->project($reservation),
                    'unit' => $this->unit($reservation),
                    'contracts' => $contractsByReservation
                        ->get($reservation->reservation_id, collect())
                        ->map(fn (Contract $contract): array => $this->contract(
                            $contract,
                            $collectionsByContract->get($contract->id, collect()),
                        ))
                        ->values()
                        ->all(),
                ])
                ->all(),
        ];
    }

    /** @return SupportCollection<int, object> */
    private function reservations(string $tenantId, string $customerId): SupportCollection
    {
        return DB::table('reservations')
            ->leftJoin('units', function ($join): void {
                $join
                    ->on('units.id', '=', 'reservations.unit_id')
                    ->on('units.tenant_id', '=', 'reservations.tenant_id');
            })
            ->leftJoin('projects', function ($join): void {
                $join
                    ->on('projects.id', '=', 'units.project_id')
                    ->on('projects.tenant_id', '=', 'reservations.tenant_id');
            })
            ->where('reservations.tenant_id', $tenantId)
            ->where('reservations.customer_id', $customerId)
            ->select([
                'reservations.id as reservation_id',
                'reservations.status as reservation_status',
                'reservations.reserved_at',
                'reservations.expires_at',
                'units.id as unit_id',
                'units.unit_number',
                'units.unit_type',
                'units.status as unit_status',
                'projects.id as project_id',
                'projects.project_number',
                'projects.name as project_name',
                'projects.status as project_status',
            ])
            ->orderByDesc('reservations.created_at')
            ->orderByDesc('reservations.id')
            ->get();
    }

    /**
     * @param  array<int, string>  $reservationIds
     * @return SupportCollection<int, Contract>
     */
    private function contracts(string $tenantId, array $reservationIds): SupportCollection
    {
        if ($reservationIds === []) {
            return collect();
        }

        return Contract::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('reservation_id', $reservationIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<int, string>  $contractIds
     * @return SupportCollection<string, SupportCollection<int, Collection>>
     */
    private function collectionsByContract(
        string $tenantId,
        array $contractIds,
    ): SupportCollection {
        if ($contractIds === []) {
            return collect();
        }

        return Collection::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('contract_id', $contractIds)
            ->orderBy('due_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy('contract_id');
    }

    /** @return array<string, mixed> */
    private function reservation(object $reservation): array
    {
        return [
            'id' => (string) $reservation->reservation_id,
            'status' => (string) $reservation->reservation_status,
            'reserved_at' => $this->timestamp($reservation->reserved_at),
            'expires_at' => $this->timestamp($reservation->expires_at),
        ];
    }

    /** @return array<string, string>|null */
    private function project(object $reservation): ?array
    {
        if ($reservation->project_id === null) {
            return null;
        }

        return [
            'id' => (string) $reservation->project_id,
            'project_number' => (string) $reservation->project_number,
            'name' => (string) $reservation->project_name,
            'status' => (string) $reservation->project_status,
        ];
    }

    /** @return array<string, string>|null */
    private function unit(object $reservation): ?array
    {
        if ($reservation->unit_id === null) {
            return null;
        }

        return [
            'id' => (string) $reservation->unit_id,
            'unit_number' => (string) $reservation->unit_number,
            'unit_type' => (string) $reservation->unit_type,
            'status' => (string) $reservation->unit_status,
        ];
    }

    /**
     * @param  SupportCollection<int, Collection>  $collections
     * @return array<string, mixed>
     */
    private function contract(Contract $contract, SupportCollection $collections): array
    {
        return [
            'id' => (string) $contract->id,
            'status' => $contract->status->value,
            'total_amount' => (string) $contract->total_amount,
            'currency' => (string) $contract->currency,
            'activated_at' => $this->timestamp($contract->activated_at),
            'completed_at' => $this->timestamp($contract->completed_at),
            'cancelled_at' => $this->timestamp($contract->cancelled_at),
            'collection_schedule' => $this->collectionSchedule($collections),
        ];
    }

    /**
     * @param  SupportCollection<int, Collection>  $collections
     * @return array<string, mixed>
     */
    private function collectionSchedule(SupportCollection $collections): array
    {
        $state = $this->stateResolver->resolve($collections->all());

        if ($state === DerivedScheduleState::Invalid) {
            throw new UnexpectedValueException(
                'Customer business context encountered an invalid Collection schedule.',
            );
        }

        $scheduled = $collections
            ->filter(
                fn (Collection $collection): bool =>
                    $collection->status === CollectionStatus::Scheduled,
            )
            ->values();
        $next = $scheduled->first();

        return [
            'state' => $state->value,
            'scheduled_total' => $this->total($scheduled),
            'scheduled_lines_count' => $scheduled->count(),
            'next_scheduled_collection' => $next instanceof Collection
                ? [
                    'id' => (string) $next->id,
                    'title' => (string) $next->title,
                    'amount' => (string) $next->amount,
                    'due_date' => $next->due_date->format('Y-m-d'),
                ]
                : null,
        ];
    }

    /** @param SupportCollection<int, Collection> $collections */
    private function total(SupportCollection $collections): string
    {
        $cents = $collections->sum(function (Collection $collection): int {
            [$whole, $fraction] = array_pad(
                explode('.', (string) $collection->amount, 2),
                2,
                '0',
            );

            return ((int) $whole * 100)
                + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        });

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function timestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->utc()->toISOString();
    }
}
