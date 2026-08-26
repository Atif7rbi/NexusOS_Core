<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Actions;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use App\Modules\Collections\Support\EffectiveReceivableGuard;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractCannotBeCancelledException;
use App\Modules\Contracts\Exceptions\ContractReservationStateException;
use App\Modules\Contracts\Exceptions\ContractUnitStateException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * CP6 (Contract Cancellation -> Collections Integrity):
 *
 * There is no independent "Cancel Collection Schedule" user action.
 * Cancelling a Contract (draft or active) must, in the same atomic
 * transaction, cancel every currently-active (non-cancelled) Collection
 * row for that Contract. Already-cancelled rows and historical/replaced
 * versions are left untouched. An absent or already-cancelled schedule
 * is a no-op.
 */
final class CancelContractAction
{
    private const SYSTEM_CANCELLATION_REASON = 'إلغاء تلقائي بسبب إلغاء العقد.';

    public function __construct(
        private readonly DerivedScheduleStateResolver $stateResolver = new DerivedScheduleStateResolver,
        private readonly EffectiveReceivableGuard $effectiveReceivableGuard = new EffectiveReceivableGuard,
        private readonly ?CollectionAuditRecorderInterface $auditRecorder = null,
    ) {
    }

    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
    ): Contract {
        return DB::transaction(function () use (
            $tenantId,
            $contractId,
            $actorId,
        ): Contract {
            $contract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($contract->status, [ContractStatus::Draft, ContractStatus::Active], true)) {
                throw new ContractCannotBeCancelledException();
            }

            if ($contract->status === ContractStatus::Active) {
                $reservation = Reservation::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($contract->reservation_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($reservation->status !== ReservationStatus::Converted) {
                    throw new ContractReservationStateException();
                }

                $unit = Unit::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($reservation->unit_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($unit->status !== UnitStatus::Sold) {
                    throw new ContractUnitStateException();
                }

                $unit->update([
                    'status' => UnitStatus::Available,
                ]);
            }

            $cancelledCollectionCount = $this->cancelActiveCollectionSchedule(
                $tenantId,
                $contract,
                $actorId,
            );

            $contract->forceFill([
                'status' => ContractStatus::Cancelled,
                'cancelled_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            if ($cancelledCollectionCount > 0) {
                $this->auditRecorder()->record(
                    $tenantId,
                    (string) $contract->id,
                    'collection_schedule_cancelled_by_contract',
                    $actorId,
                    [
                        'collection_count' => $cancelledCollectionCount,
                        'reason' => self::SYSTEM_CANCELLATION_REASON,
                    ],
                );
            }

            return $contract;
        });
    }

    private function cancelActiveCollectionSchedule(
        string $tenantId,
        Contract $contract,
        int|string $actorId,
    ): int {
        $collections = Collection::query()
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get()
            ->all();

        $activeCollections = $this->stateResolver->activeOnly($collections);

        if ($activeCollections === []) {
            return 0;
        }

        $this->effectiveReceivableGuard->assertNone($tenantId, array_map(
            static fn (Collection $collection): string => (string) $collection->id,
            $activeCollections,
        ));

        $cancelledAt = now();

        foreach ($activeCollections as $collection) {
            $collection->forceFill([
                'status' => CollectionStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $actorId,
                'cancellation_reason' => self::SYSTEM_CANCELLATION_REASON,
                'updated_by' => $actorId,
            ])->save();
        }

        return count($activeCollections);
    }

    private function auditRecorder(): CollectionAuditRecorderInterface
    {
        return $this->auditRecorder
            ?? app(CollectionAuditRecorderInterface::class);
    }
}
