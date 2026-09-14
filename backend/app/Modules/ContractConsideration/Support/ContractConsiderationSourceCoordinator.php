<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractConsiderationSourceCoordinator
{
    private const BILLING = 'CONTRACTUAL_BILLING_ENTITLEMENT';

    private const HANDOVER = 'UNIT_HANDOVER_ACCEPTANCE';

    public function coordinateNewBillingEntitlement(
        string $tenantId,
        string $entitlementId,
        array $input,
    ): void {
        $this->coordinateEffectiveSource(
            $tenantId,
            self::BILLING,
            $entitlementId,
            $input,
            true,
        );
    }

    public function assertBillingEntitlementReplay(
        string $tenantId,
        string $entitlementId,
        array $input,
    ): void {
        $this->coordinateEffectiveSource(
            $tenantId,
            self::BILLING,
            $entitlementId,
            $input,
            false,
        );
    }

    public function coordinateNewUnitHandoverAcceptance(
        string $tenantId,
        string $acceptanceId,
        array $input,
    ): void {
        $this->coordinateEffectiveSource(
            $tenantId,
            self::HANDOVER,
            $acceptanceId,
            $input,
            true,
        );
    }

    public function assertUnitHandoverAcceptanceReplay(
        string $tenantId,
        string $acceptanceId,
        array $input,
    ): void {
        $this->coordinateEffectiveSource(
            $tenantId,
            self::HANDOVER,
            $acceptanceId,
            $input,
            false,
        );
    }

    public function reverseUnitHandoverAcceptance(
        string $tenantId,
        string $acceptanceId,
    ): void {
        $source = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if ($source === null) {
            throw new ContractConsiderationConflict(
                'Unit Handover source disappeared before Consideration reversal.',
            );
        }

        $position = $this->lockPosition(
            $tenantId,
            (string) $source->contract_id,
        );

        if ($position === null) {
            return;
        }

        $transition = $this->lockTransitionBySource(
            $tenantId,
            self::HANDOVER,
            $acceptanceId,
        );

        if ($transition === null) {
            throw new ContractConsiderationConflict(
                'Adopted Unit Handover source is missing historical Consideration Transition truth.',
            );
        }

        $this->reverseTransitionFromSource(
            $tenantId,
            $transition,
            $source,
            self::HANDOVER,
        );
    }

    /**
     * @param  array<int, string>  $entitlementIds
     */
    public function reverseBillingEntitlements(
        string $tenantId,
        array $entitlementIds,
    ): void {
        if ($entitlementIds === []) {
            return;
        }

        sort($entitlementIds, SORT_STRING);

        $sources = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $entitlementIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($sources->count() !== count($entitlementIds)) {
            throw new ContractConsiderationConflict(
                'Billing source set changed before Consideration reversal.',
            );
        }

        $contractIds = $sources
            ->pluck('contract_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($contractIds->count() !== 1) {
            throw new ContractConsiderationConflict(
                'Billing Consideration reversal requires one Contract source set.',
            );
        }

        $position = $this->lockPosition(
            $tenantId,
            $contractIds->first(),
        );

        if ($position === null) {
            return;
        }

        $transitions = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)
            ->where('source_type', self::BILLING)
            ->whereIn('source_id', $entitlementIds)
            ->orderByDesc('economic_date')
            ->orderByDesc('semantic_precedence')
            ->orderByDesc('source_id')
            ->lockForUpdate()
            ->get();

        if ($transitions->count() !== count($entitlementIds)) {
            throw new ContractConsiderationConflict(
                'Adopted Billing source is missing historical Consideration Transition truth.',
            );
        }

        $sourceById = $sources->keyBy(
            static fn (object $source): string => (string) $source->id,
        );

        foreach ($transitions as $transition) {
            $source = $sourceById->get((string) $transition->source_id);

            if ($source === null) {
                throw new ContractConsiderationConflict(
                    'Billing Consideration reversal source provenance changed.',
                );
            }

            $this->reverseTransitionFromSource(
                $tenantId,
                $transition,
                $source,
                self::BILLING,
            );
        }
    }

    private function coordinateEffectiveSource(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        array $input,
        bool $allowCreate,
    ): void {
        $source = $this->lockSource(
            $tenantId,
            $sourceType,
            $sourceId,
        );

        if ($source === null) {
            throw new ContractConsiderationConflict(
                'Supported source disappeared before Consideration coordination.',
            );
        }

        $position = $this->lockPosition(
            $tenantId,
            (string) $source->contract_id,
        );

        if ($position === null) {
            return;
        }

        if ($source->status !== 'effective') {
            throw new ContractConsiderationConflict(
                'New Contract Consideration coordination requires an effective source.',
            );
        }

        $operationId = $this->transitionOperation($input);

        $bySource = $this->lockTransitionBySource(
            $tenantId,
            $sourceType,
            $sourceId,
        );

        $byOperation = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('transition_operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if (
            $byOperation !== null
            && (
                $byOperation->source_type !== $sourceType
                || $byOperation->source_id !== $sourceId
            )
        ) {
            throw new ContractConsiderationConflict(
                'Contract Consideration Transition operation identity is already used by another source.',
            );
        }

        if ($bySource !== null) {
            if ($bySource->transition_operation_id !== $operationId) {
                throw new ContractConsiderationConflict(
                    'Supported source already has historical Consideration truth under a different operation identity.',
                );
            }

            $this->assertEffectiveTransitionMatchesSource(
                $bySource,
                $source,
                $sourceType,
                $position,
            );

            return;
        }

        if (! $allowCreate) {
            throw new ContractConsiderationConflict(
                'Adopted source replay cannot infer missing historical Consideration truth.',
            );
        }

        $this->createTransitionGraph(
            $tenantId,
            $position,
            $source,
            $sourceType,
            $operationId,
        );
    }

    private function createTransitionGraph(
        string $tenantId,
        object $position,
        object $source,
        string $sourceType,
        string $operationId,
    ): void {
        $sourceFacts = $this->sourceFacts($source, $sourceType);
        $amount = BigDecimal::of($sourceFacts['amount']);

        if (
            $sourceType === self::HANDOVER
            && ! $amount->isEqualTo(
                BigDecimal::of((string) $position->consideration_amount),
            )
        ) {
            throw new ContractConsiderationConflict(
                'Unit Handover Consideration must consume the full adopted Contract capacity.',
            );
        }

        $candidates = $this->candidateLots(
            $tenantId,
            (string) $position->id,
            $sourceType,
            $sourceFacts['economic_date'],
            $sourceFacts['semantic_precedence'],
            (string) $source->id,
        );

        $need = $amount;
        $edges = [];
        $outputs = [];

        foreach ($candidates as $candidate) {
            if ($need->isZero()) {
                break;
            }

            $consumed = DB::table('contract_consideration_transition_lots as edge')
                ->join(
                    'contract_consideration_transitions as transition',
                    function ($join): void {
                        $join->on(
                            'transition.tenant_id',
                            '=',
                            'edge.tenant_id',
                        )->on(
                            'transition.id',
                            '=',
                            'edge.transition_id',
                        );
                    },
                )
                ->where('edge.tenant_id', $tenantId)
                ->where('edge.lot_id', $candidate->id)
                ->where('transition.status', 'effective')
                ->sum('edge.consumed_amount');

            $remaining = BigDecimal::of((string) $candidate->amount)
                ->minus(BigDecimal::of((string) $consumed));

            if ($remaining->isLessThan(BigDecimal::zero())) {
                throw new ContractConsiderationConflict(
                    'Contract Consideration predecessor capacity is over-consumed.',
                );
            }

            if ($remaining->isZero()) {
                continue;
            }

            $consume = $need->isLessThan($remaining)
                ? $need
                : $remaining;

            if (
                $sourceType === self::HANDOVER
                && ! $consume->isEqualTo($remaining)
            ) {
                throw new ContractConsiderationConflict(
                    'Full handover cannot partially consume unperformed Consideration capacity.',
                );
            }

            $target = $this->successorPosition(
                $sourceType,
                (string) $candidate->semantic_position,
            );

            $outputs[$target] = isset($outputs[$target])
                ? $outputs[$target]->plus($consume)
                : $consume;

            $edges[] = [
                'lot_id' => (string) $candidate->id,
                'successor_position' => $target,
                'consumed_amount' => (string) $consume,
            ];

            $need = $need->minus($consume);
        }

        if (! $need->isZero()) {
            throw new ContractConsiderationConflict(
                'Supported source exceeds available Contract Consideration capacity.',
            );
        }

        $transitionId = (string) Str::ulid();
        $createdAt = $sourceFacts['created_at'];

        DB::table('contract_consideration_transitions')->insert([
            'id' => $transitionId,
            'tenant_id' => $tenantId,
            'contract_id' => (string) $position->contract_id,
            'position_id' => (string) $position->id,
            'transition_operation_id' => $operationId,
            'source_type' => $sourceType,
            'source_id' => (string) $source->id,
            'economic_date' => $sourceFacts['economic_date'],
            'semantic_precedence' => $sourceFacts['semantic_precedence'],
            'transition_amount' => $sourceFacts['amount'],
            'currency' => (string) $source->currency,
            'status' => 'effective',
            'created_by' => $sourceFacts['created_by'],
            'created_at' => $createdAt,
        ]);

        ksort($outputs, SORT_STRING);
        $successors = [];

        foreach ($outputs as $semanticPosition => $outputAmount) {
            $lotId = (string) Str::ulid();
            $successors[$semanticPosition] = $lotId;

            DB::table('contract_consideration_lots')->insert([
                'id' => $lotId,
                'tenant_id' => $tenantId,
                'contract_id' => (string) $position->contract_id,
                'position_id' => (string) $position->id,
                'transition_id' => $transitionId,
                'lot_kind' => 'TRANSITION_OUTPUT',
                'semantic_position' => $semanticPosition,
                'amount' => (string) $outputAmount,
                'currency' => (string) $source->currency,
                'created_at' => $createdAt,
            ]);
        }

        foreach ($edges as $edge) {
            DB::table('contract_consideration_transition_lots')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'contract_id' => (string) $position->contract_id,
                'position_id' => (string) $position->id,
                'transition_id' => $transitionId,
                'lot_id' => $edge['lot_id'],
                'successor_lot_id' => $successors[$edge['successor_position']],
                'consumed_amount' => $edge['consumed_amount'],
                'currency' => (string) $source->currency,
                'created_at' => $createdAt,
            ]);
        }
    }

    private function candidateLots(
        string $tenantId,
        string $positionId,
        string $sourceType,
        string $economicDate,
        int $semanticPrecedence,
        string $sourceId,
    ): Collection {
        $eligible = $sourceType === self::HANDOVER
            ? ['UNPERFORMED_UNBILLED', 'BILLED_UNEARNED']
            : ['UNPERFORMED_UNBILLED', 'EARNED_UNBILLED'];

        return DB::table('contract_consideration_lots as lot')
            ->leftJoin(
                'contract_consideration_transitions as prior',
                function ($join): void {
                    $join->on('prior.tenant_id', '=', 'lot.tenant_id')
                        ->on('prior.id', '=', 'lot.transition_id');
                },
            )
            ->where('lot.tenant_id', $tenantId)
            ->where('lot.position_id', $positionId)
            ->whereIn('lot.semantic_position', $eligible)
            ->select([
                'lot.id',
                'lot.lot_kind',
                'lot.semantic_position',
                'lot.amount',
                'prior.status as prior_status',
                'prior.economic_date as prior_economic_date',
                'prior.semantic_precedence as prior_semantic_precedence',
                'prior.source_id as prior_source_id',
            ])
            ->get()
            ->filter(function (object $lot) use (
                $economicDate,
                $semanticPrecedence,
                $sourceId,
            ): bool {
                if ($lot->lot_kind === 'GENESIS') {
                    return true;
                }

                if ($lot->prior_status !== 'effective') {
                    return false;
                }

                return $this->compareCanonical(
                    (string) $lot->prior_economic_date,
                    (int) $lot->prior_semantic_precedence,
                    (string) $lot->prior_source_id,
                    $economicDate,
                    $semanticPrecedence,
                    $sourceId,
                ) < 0;
            })
            ->sort(function (object $left, object $right): int {
                if ($left->lot_kind === 'GENESIS') {
                    return $right->lot_kind === 'GENESIS'
                        ? strcmp((string) $left->id, (string) $right->id)
                        : -1;
                }

                if ($right->lot_kind === 'GENESIS') {
                    return 1;
                }

                $comparison = $this->compareCanonical(
                    (string) $left->prior_economic_date,
                    (int) $left->prior_semantic_precedence,
                    (string) $left->prior_source_id,
                    (string) $right->prior_economic_date,
                    (int) $right->prior_semantic_precedence,
                    (string) $right->prior_source_id,
                );

                return $comparison !== 0
                    ? $comparison
                    : strcmp((string) $left->id, (string) $right->id);
            })
            ->values();
    }

    private function sourceFacts(object $source, string $sourceType): array
    {
        if ($sourceType === self::HANDOVER) {
            return [
                'economic_date' => (string) $source->performance_date,
                'amount' => (string) $source->performance_amount,
                'semantic_precedence' => 10,
                'created_by' => (int) $source->created_by,
                'created_at' => $source->created_at,
            ];
        }

        return [
            'economic_date' => (string) $source->economic_date,
            'amount' => (string) $source->amount,
            'semantic_precedence' => 20,
            'created_by' => (int) $source->recognized_by,
            'created_at' => $source->recognized_at,
        ];
    }

    private function successorPosition(
        string $sourceType,
        string $predecessorPosition,
    ): string {
        if ($sourceType === self::HANDOVER) {
            return match ($predecessorPosition) {
                'UNPERFORMED_UNBILLED' => 'EARNED_UNBILLED',
                'BILLED_UNEARNED' => 'BILLED_EARNED',
                default => throw new ContractConsiderationConflict(
                    'Unit Handover source found invalid predecessor Consideration position.',
                ),
            };
        }

        return match ($predecessorPosition) {
            'UNPERFORMED_UNBILLED' => 'BILLED_UNEARNED',
            'EARNED_UNBILLED' => 'BILLED_EARNED',
            default => throw new ContractConsiderationConflict(
                'Billing source found invalid predecessor Consideration position.',
            ),
        };
    }

    private function assertEffectiveTransitionMatchesSource(
        object $transition,
        object $source,
        string $sourceType,
        object $position,
    ): void {
        $facts = $this->sourceFacts($source, $sourceType);

        if (
            $transition->position_id !== $position->id
            || $transition->contract_id !== $position->contract_id
            || $transition->status !== 'effective'
            || (string) $transition->economic_date !== $facts['economic_date']
            || (int) $transition->semantic_precedence
                !== $facts['semantic_precedence']
            || ! BigDecimal::of((string) $transition->transition_amount)
                ->isEqualTo(BigDecimal::of($facts['amount']))
            || $transition->currency !== $source->currency
            || (int) $transition->created_by !== $facts['created_by']
        ) {
            throw new ContractConsiderationConflict(
                'Consideration Transition replay differs from authoritative source truth.',
            );
        }
    }

    private function reverseTransitionFromSource(
        string $tenantId,
        object $transition,
        object $source,
        string $sourceType,
    ): void {
        if ($source->status !== 'reversed') {
            throw new ContractConsiderationConflict(
                'Consideration reversal requires authoritative reversed source truth.',
            );
        }

        $sourceOperation = $sourceType === self::HANDOVER
            ? $source->reversal_operation_id
            : $source->source_correction_operation_id;

        $sourceReference = $sourceType === self::HANDOVER
            ? $source->reversal_reference
            : $source->source_rescission_reference;

        $expected = [
            'reversal_operation_id' => $source->reversal_operation_id,
            'reversal_source_operation_id' => $sourceOperation,
            'reversal_reason' => $source->reversal_reason,
            'reversal_reference' => $sourceReference,
            'reversed_by' => $source->reversed_by,
            'reversed_at' => $source->reversed_at,
        ];

        if ($transition->status === 'reversed') {
            if (
                $transition->reversal_operation_id
                    !== $expected['reversal_operation_id']
                || $transition->reversal_source_operation_id
                    !== $expected['reversal_source_operation_id']
                || $transition->reversal_reason
                    !== $expected['reversal_reason']
                || $transition->reversal_reference
                    !== $expected['reversal_reference']
                || $transition->reversed_by
                    !== $expected['reversed_by']
                || (string) $transition->reversed_at
                    !== (string) $expected['reversed_at']
            ) {
                throw new ContractConsiderationConflict(
                    'Consideration reversal replay differs from authoritative source truth.',
                );
            }

            return;
        }

        if ($transition->status !== 'effective') {
            throw new ContractConsiderationConflict(
                'Consideration Transition has invalid lifecycle for source reversal.',
            );
        }

        $updated = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('id', $transition->id)
            ->where('status', 'effective')
            ->update(['status' => 'reversed'] + $expected);

        if ($updated !== 1) {
            throw new ContractConsiderationConflict(
                'Consideration Transition changed during source reversal.',
            );
        }
    }

    private function lockSource(
        string $tenantId,
        string $sourceType,
        string $sourceId,
    ): ?object {
        $table = $sourceType === self::HANDOVER
            ? 'unit_handover_acceptances'
            : 'contractual_billing_entitlements';

        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    private function lockPosition(
        string $tenantId,
        string $contractId,
    ): ?object {
        return DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->lockForUpdate()
            ->first();
    }

    private function lockTransitionBySource(
        string $tenantId,
        string $sourceType,
        string $sourceId,
    ): ?object {
        return DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    private function transitionOperation(array $input): string
    {
        $operationId = $input[
            'contract_consideration_transition_operation_id'
        ] ?? null;

        if (
            ! is_string($operationId)
            || ! Str::isUlid($operationId)
        ) {
            throw new ContractConsiderationValidationFailed(
                'contract_consideration_transition_operation_id must be a ULID for an adopted Contract.',
            );
        }

        return $operationId;
    }

    private function compareCanonical(
        string $leftDate,
        int $leftPrecedence,
        string $leftSourceId,
        string $rightDate,
        int $rightPrecedence,
        string $rightSourceId,
    ): int {
        return [$leftDate, $leftPrecedence, $leftSourceId]
            <=> [$rightDate, $rightPrecedence, $rightSourceId];
    }
}
