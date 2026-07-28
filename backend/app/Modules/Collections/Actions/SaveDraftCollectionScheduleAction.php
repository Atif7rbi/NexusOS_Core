<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\DTOs\CollectionScheduleResult;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Exceptions\CollectionNotFoundException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Policies\DraftEditEligibilityPolicy;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use App\Modules\Collections\Support\LogCollectionAuditRecorder;
use App\Modules\Collections\Validation\CollectionLineValidator;
use App\Modules\Collections\Validation\CollectionScheduleValidator;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DDL spec section 23 (Draft Collections).
 *
 * Persists the full desired draft schedule for a Contract atomically:
 * - Lines with an existing `id` are edited in place (updated_by is set).
 * - Lines without an `id` are created (created_by set, updated_by left
 *   null per DDL spec section 17.2 — "Nullable on initial creation").
 * - Existing active rows not referenced by any submitted line are hard
 *   deleted (section 23 explicitly allows hard delete for Draft rows).
 *
 * Does not implement Finalization. Does not apply the Contract-total
 * invariant while building a Draft (section 23).
 */
final class SaveDraftCollectionScheduleAction
{
    public function __construct(
        private readonly DraftEditEligibilityPolicy $eligibilityPolicy = new DraftEditEligibilityPolicy(),
        private readonly DerivedScheduleStateResolver $stateResolver = new DerivedScheduleStateResolver(),
        private readonly CollectionLineValidator $lineValidator = new CollectionLineValidator(),
        private readonly CollectionScheduleValidator $scheduleValidator = new CollectionScheduleValidator(),
        private readonly ?CollectionAuditRecorderInterface $auditRecorder = null,
    ) {
    }

    /**
     * @param  array<int, \App\Modules\Collections\DTOs\CollectionLineData>  $lines
     */
    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
        array $lines,
    ): CollectionScheduleResult {
        return DB::transaction(function () use ($tenantId, $contractId, $actorId, $lines): CollectionScheduleResult {
            $contract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingCollections = Collection::query()
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contract->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->all();

            $currentState = $this->stateResolver->resolve($existingCollections);

            $this->eligibilityPolicy->assert($contract, $currentState);

            // Validate every submitted line, and the batch as a whole, before
            // writing anything.
            foreach ($lines as $line) {
                $this->lineValidator->validate($line);
            }

            $incomingRows = $this->scheduleValidator->rowsFromLines($lines);
            $this->scheduleValidator->assertUniqueActiveSequences($incomingRows);
            $this->scheduleValidator->assertNonDecreasingDueDates($incomingRows);

            $existingById = [];
            foreach ($this->stateResolver->activeOnly($existingCollections) as $collection) {
                $existingById[$collection->id] = $collection;
            }

            $keptIds = [];
            $resultRows = [];

            foreach ($lines as $line) {
                if ($line->id !== null) {
                    if (! isset($existingById[$line->id])) {
                        throw new CollectionNotFoundException();
                    }

                    $collection = $existingById[$line->id];
                    $collection->forceFill([
                        'sequence' => $line->sequence,
                        'title' => $line->title,
                        'amount' => $line->amount,
                        'due_date' => $line->dueDate,
                        'notes' => $line->notes,
                        'updated_by' => $actorId,
                    ])->save();

                    $keptIds[] = $collection->id;
                    $resultRows[] = $collection;

                    continue;
                }

                $collection = new Collection();
                $collection->id = (string) Str::ulid();
                $collection->forceFill([
                    'tenant_id' => $tenantId,
                    'contract_id' => $contract->id,
                    'sequence' => $line->sequence,
                    'title' => $line->title,
                    'amount' => $line->amount,
                    'due_date' => $line->dueDate,
                    'notes' => $line->notes,
                    'status' => CollectionStatus::Draft,
                    'created_by' => $actorId,
                    'updated_by' => null,
                ])->save();

                $keptIds[] = $collection->id;
                $resultRows[] = $collection;
            }

            // Section 23: Draft rows may be hard-deleted. Any active row not
            // referenced by an incoming line is removed.
            foreach ($existingById as $id => $collection) {
                if (! in_array($id, $keptIds, true)) {
                    $collection->delete();
                }
            }

            usort($resultRows, static fn (Collection $a, Collection $b): int => $a->sequence <=> $b->sequence);

            $newState = $this->stateResolver->resolve($resultRows);

            $this->auditRecorder()->record(
                $tenantId,
                $contract->id,
                'collection_draft_schedule_saved',
                $actorId,
                ['collection_count' => count($resultRows)],
            );

            return new CollectionScheduleResult($resultRows, $newState);
        });
    }

    private function auditRecorder(): CollectionAuditRecorderInterface
    {
        return $this->auditRecorder ?? new LogCollectionAuditRecorder();
    }
}
