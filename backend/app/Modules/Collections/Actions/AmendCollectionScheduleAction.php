<?php

declare(strict_types=1);

namespace App\Modules\Collections\Actions;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\DTOs\CollectionScheduleResult;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Exceptions\InvalidCancellationReasonException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Policies\AmendmentEligibilityPolicy;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use App\Modules\Collections\Support\LogCollectionAuditRecorder;
use App\Modules\Collections\Validation\CollectionLineValidator;
use App\Modules\Collections\Validation\CollectionScheduleValidator;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DDL spec section 25 (Amendment).
 *
 * Cancel-and-replace is the only mutation pattern for a Scheduled
 * Collection schedule (section 26, Financial Immutability). Order of
 * operations follows the specification exactly:
 *
 * Lock Contract -> Lock Collections -> Validate lifecycle -> Validate
 * amendment eligibility -> Cancel replaced rows -> Create replacements
 * directly as scheduled -> Validate sequence -> Validate due-date order ->
 * Validate final total -> Write audit -> Commit.
 *
 * Because the cross-row validations run after the cancel-and-replace
 * writes (as specified), a validation failure rolls back the entire
 * transaction, undoing both the cancellation and the replacement rows.
 */
final class AmendCollectionScheduleAction
{
    public function __construct(
        private readonly AmendmentEligibilityPolicy $eligibilityPolicy = new AmendmentEligibilityPolicy(),
        private readonly DerivedScheduleStateResolver $stateResolver = new DerivedScheduleStateResolver(),
        private readonly CollectionLineValidator $lineValidator = new CollectionLineValidator(),
        private readonly CollectionScheduleValidator $scheduleValidator = new CollectionScheduleValidator(),
        private readonly ?CollectionAuditRecorderInterface $auditRecorder = null,
    ) {
    }

    /**
     * @param  array<int, \App\Modules\Collections\DTOs\CollectionLineData>  $replacementLines
     */
    public function execute(
        string $tenantId,
        string $contractId,
        int|string $actorId,
        array $replacementLines,
        string $cancellationReason,
    ): CollectionScheduleResult {
        $this->assertCancellationReason($cancellationReason);

        foreach ($replacementLines as $line) {
            $this->lineValidator->validate($line);
        }

        return DB::transaction(function () use (
            $tenantId,
            $contractId,
            $actorId,
            $replacementLines,
            $cancellationReason,
        ): CollectionScheduleResult {
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

            $this->eligibilityPolicy->assert($contract, $currentState);

            $replacedCollections = $this->stateResolver->activeOnly($allCollections);
            $cancelledAt = now();

            foreach ($replacedCollections as $collection) {
                $collection->forceFill([
                    'status' => CollectionStatus::Cancelled,
                    'cancelled_at' => $cancelledAt,
                    'cancelled_by' => $actorId,
                    'cancellation_reason' => $cancellationReason,
                    'updated_by' => $actorId,
                ])->save();
            }

            $scheduledAt = now();
            $replacements = [];

            foreach ($replacementLines as $line) {
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
                    'status' => CollectionStatus::Scheduled,
                    'scheduled_at' => $scheduledAt,
                    'scheduled_by' => $actorId,
                    'created_by' => $actorId,
                    'updated_by' => null,
                ])->save();

                $replacements[] = $collection;
            }

            $rows = $this->scheduleValidator->rowsFromCollections($replacements);
            $this->scheduleValidator->assertUniqueActiveSequences($rows);
            $this->scheduleValidator->assertNonDecreasingDueDates($rows);
            $this->scheduleValidator->assertTotalMatches($rows, (string) $contract->total_amount);

            $this->auditRecorder()->record(
                $tenantId,
                $contract->id,
                'collection_schedule_amended',
                $actorId,
                [
                    'cancelled_count' => count($replacedCollections),
                    'replacement_count' => count($replacements),
                    'cancellation_reason' => $cancellationReason,
                ],
            );

            usort($replacements, static fn (Collection $a, Collection $b): int => $a->sequence <=> $b->sequence);

            return new CollectionScheduleResult(
                $replacements,
                $this->stateResolver->resolve($replacements),
            );
        });
    }

    private function assertCancellationReason(string $reason): void
    {
        $trimmed = trim($reason);

        if ($trimmed === '' || mb_strlen($reason) > 500) {
            throw new InvalidCancellationReasonException();
        }
    }

    private function auditRecorder(): CollectionAuditRecorderInterface
    {
        return $this->auditRecorder ?? new LogCollectionAuditRecorder();
    }
}
