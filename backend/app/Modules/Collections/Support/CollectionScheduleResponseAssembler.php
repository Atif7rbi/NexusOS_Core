<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Models\User;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Http\Exceptions\CollectionScheduleIntegrityViolationException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Policies\AmendmentEligibilityPolicy;
use App\Modules\Collections\Policies\DraftEditEligibilityPolicy;
use App\Modules\Collections\Policies\FinalizationEligibilityPolicy;
use App\Modules\Contracts\Models\Contract;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CollectionScheduleResponseAssembler
{
    public function __construct(
        private readonly DerivedScheduleStateResolver $stateResolver = new DerivedScheduleStateResolver,
        private readonly DraftEditEligibilityPolicy $draftPolicy = new DraftEditEligibilityPolicy,
        private readonly FinalizationEligibilityPolicy $finalizationPolicy = new FinalizationEligibilityPolicy,
        private readonly AmendmentEligibilityPolicy $amendmentPolicy = new AmendmentEligibilityPolicy,
        private readonly CollectionScheduleAuthorization $authorization = new CollectionScheduleAuthorization,
    ) {}

    /**
     * @return array{
     *   contract: array<string, mixed>,
     *   schedule: array<string, mixed>,
     *   allowed_actions: array<string, bool>
     * }
     */
    public function assemble(
        Contract $contract,
        User $user,
        bool $includeHistory = false,
    ): array {
        $collections = Collection::query()
            ->with([
                'scheduler:id,name',
                'canceller:id,name',
            ])
            ->where('tenant_id', $contract->tenant_id)
            ->where('contract_id', $contract->id)
            ->get()
            ->all();

        $state = $this->stateResolver->resolve($collections);

        if ($state === DerivedScheduleState::Invalid) {
            Log::critical('Invalid Collection schedule state detected during API response assembly.', [
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
            ]);

            throw new CollectionScheduleIntegrityViolationException;
        }

        $active = $this->stateResolver->activeOnly($collections);
        usort($active, static fn (Collection $left, Collection $right): int =>
            [$left->sequence, (string) $left->id] <=> [$right->sequence, (string) $right->id]
        );

        $schedule = [
            'derived_state' => $state->value,
            'active_total' => $this->total($active),
            'active_collections' => array_map(
                fn (Collection $collection): array => $this->activeCollection($collection),
                $active,
            ),
        ];

        if ($includeHistory) {
            $cancelled = array_values(array_filter(
                $collections,
                static fn (Collection $collection): bool => $collection->status === CollectionStatus::Cancelled,
            ));

            usort($cancelled, static function (Collection $left, Collection $right): int {
                $cancelledComparison = $right->cancelled_at->getTimestamp() <=> $left->cancelled_at->getTimestamp();

                return $cancelledComparison !== 0
                    ? $cancelledComparison
                    : ([$left->sequence, (string) $left->id] <=> [$right->sequence, (string) $right->id]);
            });

            $schedule['cancelled_history'] = array_map(
                fn (Collection $collection): array => $this->cancelledCollection($collection),
                $cancelled,
            );
        }

        return [
            'contract' => [
                'id' => (string) $contract->id,
                'status' => $contract->status->value,
                'currency' => (string) $contract->currency,
                'total_amount' => (string) $contract->total_amount,
            ],
            'schedule' => $schedule,
            'allowed_actions' => [
                'can_save_draft' => $this->authorization->canSaveDraft($user, $contract)
                    && $this->policyAllows(function () use ($contract, $state): void {
                        $this->draftPolicy->assert($contract, $state);
                    }),
                'can_finalize' => $this->authorization->canFinalize($user)
                    && $this->policyAllows(function () use ($contract, $state): void {
                        $this->finalizationPolicy->assert($contract, $state);
                    }),
                'can_amend' => $this->authorization->canAmend($user)
                    && $this->policyAllows(function () use ($contract, $state): void {
                        $this->amendmentPolicy->assert($contract, $state);
                    }),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function activeCollection(Collection $collection): array
    {
        return [
            'id' => (string) $collection->id,
            'sequence' => $collection->sequence,
            'title' => $collection->title,
            'amount' => (string) $collection->amount,
            'due_date' => $collection->due_date->format('Y-m-d'),
            'notes' => $collection->notes,
            'status' => $collection->status->value,
            'scheduled_at' => $this->timestamp($collection->scheduled_at),
            'scheduled_by' => $this->actor($collection->scheduler),
        ];
    }

    /** @return array<string, mixed> */
    private function cancelledCollection(Collection $collection): array
    {
        return [
            'id' => (string) $collection->id,
            'sequence' => $collection->sequence,
            'title' => $collection->title,
            'amount' => (string) $collection->amount,
            'due_date' => $collection->due_date->format('Y-m-d'),
            'notes' => $collection->notes,
            'status' => $collection->status->value,
            'scheduled_at' => $this->timestamp($collection->scheduled_at),
            'scheduled_by' => $this->actor($collection->scheduler),
            'cancelled_at' => $this->timestamp($collection->cancelled_at),
            'cancelled_by' => $this->actor($collection->canceller),
            'cancellation_reason' => $collection->cancellation_reason,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function actor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
        ];
    }

    private function timestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toImmutable()->utc()->toISOString();
    }

    /** @param array<int, Collection> $collections */
    private function total(array $collections): string
    {
        $cents = 0;

        foreach ($collections as $collection) {
            [$whole, $fraction] = array_pad(explode('.', (string) $collection->amount, 2), 2, '0');
            $cents += ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function policyAllows(callable $assertion): bool
    {
        try {
            $assertion();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
