<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\DTOs\CollectionScheduleResult;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Policies\FinalizationEligibilityPolicy;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use App\Modules\Collections\Support\LogCollectionAuditRecorder;
use App\Modules\Collections\Validation\CollectionScheduleValidator;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * DDL spec section 24 (Finalization).
 *
 * Converts every active draft Collection for a Contract into scheduled,
 * atomically, after validating the schedule as a whole. Order of
 * operations follows the specification exactly:
 *
 * Lock Contract -> Validate lifecycle -> Lock active Collections ->
 * Validate Derived Schedule State = draft -> Validate active sequence
 * uniqueness -> Validate due dates -> Validate total -> Convert draft to
 * scheduled -> Write audit -> Commit.
 */
final class FinalizeCollectionScheduleAction
{
    public function __construct(
        private readonly FinalizationEligibilityPolicy $eligibilityPolicy = new FinalizationEligibilityPolicy(),
        private readonly DerivedScheduleStateResolver $stateResolver = new DerivedScheduleStateResolver(),
        private readonly CollectionScheduleValidator $scheduleValidator = new CollectionScheduleValidator(),
        private readonly ?CollectionAuditRecorderInterface $auditRecorder = null,
    ) {
    }

    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
    ): CollectionScheduleResult {
        return DB::transaction(function () use ($tenantId, $contractId, $actorId): CollectionScheduleResult {
            $contract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->firstOrFail();

            $allCollections = Collection::query()
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contract->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->all();

            $currentState = $this->stateResolver->resolve($allCollections);

            // Validate lifecycle, then validate Derived Schedule State = draft.
            $this->eligibilityPolicy->assert($contract, $currentState);

            $activeCollections = $this->stateResolver->activeOnly($allCollections);

            $rows = $this->scheduleValidator->rowsFromCollections($activeCollections);
            $this->scheduleValidator->assertUniqueActiveSequences($rows);
            $this->scheduleValidator->assertNonDecreasingDueDates($rows);
            $this->scheduleValidator->assertTotalMatches($rows, (string) $contract->total_amount);

            $scheduledAt = now();

            foreach ($activeCollections as $collection) {
                $collection->forceFill([
                    'status' => CollectionStatus::Scheduled,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_by' => $actorId,
                    'updated_by' => $actorId,
                ])->save();
            }

            $this->auditRecorder()->record(
                $tenantId,
                $contract->id,
                'collection_schedule_finalized',
                $actorId,
                ['collection_count' => count($activeCollections)],
            );

            usort($activeCollections, static fn (Collection $a, Collection $b): int => $a->sequence <=> $b->sequence);

            return new CollectionScheduleResult(
                $activeCollections,
                $this->stateResolver->resolve($activeCollections),
            );
        });
    }

    private function auditRecorder(): CollectionAuditRecorderInterface
    {
        return $this->auditRecorder ?? new LogCollectionAuditRecorder();
    }
}
