<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionValidationFailed;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionAuthorization;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionTransaction;
use App\Modules\AccountingRecognition\Support\PerformanceAccountingJournalWriter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecognizePerformanceAccounting
{
    public function __construct(
        private readonly AccountingRecognitionTransaction $transaction,
        private readonly AccountingRecognitionAuthorization $authorization,
        private readonly PerformanceAccountingJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        array $input,
    ): string {
        $allowed = [
            'unit_handover_acceptance_id',
            'performance_accounting_operation_id',
        ];

        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting Recognition accepts identities only.',
            );
        }

        $acceptanceId = (string) (
            $input['unit_handover_acceptance_id'] ?? ''
        );
        $operationId = (string) (
            $input['performance_accounting_operation_id'] ?? ''
        );

        if (! Str::isUlid($acceptanceId) || ! Str::isUlid($operationId)) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting Recognition identities must be ULIDs.',
            );
        }

        $this->authorization->authorize($tenantId, $actor);

        try {
            return $this->transaction->run(
                fn (): string => $this->recognize(
                    $tenantId,
                    $actor,
                    $acceptanceId,
                    $operationId,
                ),
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->execute($tenantId, $actor, $input);
        }
    }

    private function recognize(
        string $tenantId,
        User $actor,
        string $acceptanceId,
        string $operationId,
    ): string {
        $this->authorization->authorizeTransactional(
            $tenantId,
            $actor,
        );

        $identity = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->first();

        if ($identity === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source Handover does not exist.',
            );
        }

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->contract_id)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source Contract is missing.',
            );
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $contract->reservation_id)
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source Reservation is missing.',
            );
        }

        $unit = DB::table('units')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservation->unit_id)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source Unit is missing.',
            );
        }

        $evidence = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->handover_evidence_id)
            ->lockForUpdate()
            ->first();

        if ($evidence === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source Evidence is missing.',
            );
        }

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if (
            $acceptance === null
            || $acceptance->contract_id !== $contract->id
            || $acceptance->reservation_id !== $reservation->id
            || $acceptance->unit_id !== $unit->id
            || $acceptance->handover_evidence_id !== $evidence->id
            || $evidence->contract_id !== $contract->id
            || $evidence->reservation_id !== $reservation->id
            || $evidence->unit_id !== $unit->id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source provenance is inconsistent.',
            );
        }

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)
            ->lockForUpdate()
            ->first();

        if ($position === null || $position->status !== 'ADOPTED') {
            throw new AccountingRecognitionConflict(
                'Performance Accounting requires adopted Contract Consideration.',
            );
        }

        $adoption = DB::table('performance_accounting_adoptions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)
            ->lockForUpdate()
            ->first();

        $transition = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)
            ->where('source_type', 'UNIT_HANDOVER_ACCEPTANCE')
            ->where('source_id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if ($transition === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting requires the exact Handover Consideration Transition.',
            );
        }

        [$edges, $lots] = $this->lockTransitionGraph(
            $tenantId,
            (string) $transition->id,
        );

        [$u, $b, $assetLot, $liabilityEdges] =
            $this->derivePerformanceGrammar($edges, $lots);

        $performanceAmount = BigDecimal::of(
            (string) $acceptance->performance_amount,
        );

        if (
            ! $u->plus($b)->isEqualTo($performanceAmount)
            || ! $performanceAmount->isEqualTo(
                BigDecimal::of((string) $transition->transition_amount),
            )
            || (string) $acceptance->performance_date
                !== (string) $transition->economic_date
            || $acceptance->currency !== 'SAR'
            || $transition->currency !== 'SAR'
            || $contract->currency !== 'SAR'
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source amounts or dates are inconsistent.',
            );
        }

        $lockedLiabilityOrigins = $this->lockLiabilityOrigins(
            $tenantId,
            (string) $contract->id,
            $liabilityEdges,
        );

        $byOperation = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('performance_accounting_operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if ($byOperation !== null) {
            $this->assertHistoricalReplay(
                $byOperation,
                $acceptance,
                $transition,
                $u,
                $b,
            );

            return (string) $byOperation->id;
        }

        $root = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('unit_handover_acceptance_id', $acceptanceId)
            ->where('recognition_kind', 'original')
            ->lockForUpdate()
            ->first();

        if ($root !== null) {
            throw new AccountingRecognitionConflict(
                'Handover already has historical Performance Accounting root truth.',
            );
        }

        if (
            $acceptance->status !== 'effective'
            || $transition->status !== 'effective'
        ) {
            throw new AccountingRecognitionConflict(
                'New Performance Accounting requires effective Handover and Consideration truth.',
            );
        }

        if (
            $adoption === null
            || $adoption->status !== 'ADOPTED'
            || $adoption->consideration_position_id !== $position->id
            || $adoption->accounting_scope_version
                !== 'PERFORMANCE_ACCOUNTING_V1'
        ) {
            throw new AccountingRecognitionConflict(
                'Contract is not adopted into Performance Accounting v1.',
            );
        }

        $consumptionPlans = $this->planLiabilityConsumption(
            $lockedLiabilityOrigins,
            $liabilityEdges,
        );

        DB::table('accounting_settings')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first()
            ?? throw new AccountingRecognitionConflict(
                'Accounting must be active before Performance Accounting recognition.',
            );

        $policy = $this->lockPolicy(
            $tenantId,
            (string) $acceptance->performance_date,
        );

        $period = DB::table('accounting_periods')
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $acceptance->performance_date)
            ->whereDate('end_date', '>=', $acceptance->performance_date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($period->count() !== 1 || $period->first()->status !== 'open') {
            throw new AccountingRecognitionConflict(
                'Performance Accounting requires one containing open Accounting Period.',
            );
        }

        $liabilityDebits = $this->liabilityDebits(
            $consumptionPlans,
        );

        $accountIds = array_keys($liabilityDebits);
        $accountIds[] = (string) $policy->revenue_account_id;

        if (! $u->isZero()) {
            $accountIds[] = (string) $policy->contract_asset_control_account_id;
        }

        $accountIds = array_values(array_unique($accountIds));
        sort($accountIds, SORT_STRING);

        $accounts = DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting required Account is missing.',
            );
        }

        $this->assertAccountsEligible(
            $accounts,
            $policy,
            $liabilityDebits,
            ! $u->isZero(),
        );

        $recognitionId = (string) Str::ulid();

        $journal = $this->journals->postRecognition(
            $tenantId,
            $actor,
            $recognitionId,
            (string) $acceptance->performance_date,
            $liabilityDebits,
            $u->isZero() ? null : [
                'account_id' => (string) $policy->contract_asset_control_account_id,
                'amount' => (string) $u,
            ],
            [
                'account_id' => (string) $policy->revenue_account_id,
                'amount' => (string) $performanceAmount,
            ],
        );

        $now = CarbonImmutable::now('UTC');

        DB::table('performance_accounting_recognitions')->insert([
            'id' => $recognitionId,
            'tenant_id' => $tenantId,
            'contract_id' => $contract->id,
            'unit_handover_acceptance_id' => $acceptance->id,
            'performance_consideration_transition_id' => $transition->id,
            'recognition_kind' => 'original',
            'performance_accounting_operation_id' => $operationId,
            'performance_accounting_correction_operation_id' => null,
            'root_recognition_id' => $recognitionId,
            'predecessor_recognition_id' => null,
            'performance_amount' => (string) $performanceAmount,
            'currency' => 'SAR',
            'accounting_date' => $acceptance->performance_date,
            'contract_asset_amount' => (string) $u,
            'contract_liability_release_amount' => (string) $b,
            'revenue_amount' => (string) $performanceAmount,
            'performance_accounting_policy_id' => $policy->id,
            'performance_accounting_policy_version' => $policy->policy_version,
            'contract_asset_account_id' => $u->isZero()
                ? null
                : $policy->contract_asset_control_account_id,
            'revenue_account_id' => $policy->revenue_account_id,
            'journal_entry_id' => $journal['journal_entry_id'],
            'status' => 'posted',
            'created_by' => $actor->id,
            'created_at' => $now,
            'reversal_operation_id' => null,
            'reversal_journal_entry_id' => null,
            'reversed_by' => null,
            'reversed_at' => null,
        ]);

        $this->writeLiabilityConsumptions(
            $tenantId,
            $recognitionId,
            (string) $contract->id,
            (string) $transition->id,
            (string) $journal['journal_entry_id'],
            $journal['liability_line_ids'],
            $consumptionPlans,
            $now,
        );

        if (! $u->isZero()) {
            if ($assetLot === null || $journal['contract_asset_line_id'] === null) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting Contract Asset leg is incomplete.',
                );
            }

            $this->writeContractAssetOrigin(
                $tenantId,
                $recognitionId,
                (string) $contract->id,
                (string) $acceptance->id,
                (string) $transition->id,
                $assetLot,
                (string) $policy->contract_asset_control_account_id,
                (string) $journal['journal_entry_id'],
                (string) $journal['contract_asset_line_id'],
                (string) $u,
                (string) $acceptance->performance_date,
                $now,
            );
        }

        $this->audit->write(
            $tenantId,
            'performance_accounting.recognized',
            'performance_accounting_recognition',
            $recognitionId,
            (int) $actor->id,
            [
                'unit_handover_acceptance_id' => (string) $acceptance->id,
                'journal_entry_id' => (string) $journal['journal_entry_id'],
                'performance_accounting_operation_id' => $operationId,
            ],
            $now,
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $recognitionId;
    }

    private function lockTransitionGraph(
        string $tenantId,
        string $transitionId,
    ): array {
        $identities = DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
            ->orderBy('id')
            ->get();

        if ($identities->isEmpty()) {
            throw new AccountingRecognitionConflict(
                'Performance Consideration Transition has no economic graph.',
            );
        }

        $lotIds = $identities
            ->flatMap(
                static fn (object $edge): array => [
                    (string) $edge->lot_id,
                    (string) $edge->successor_lot_id,
                ],
            )
            ->unique()
            ->sort()
            ->values()
            ->all();

        $lots = DB::table('contract_consideration_lots')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $lotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $edges = DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lots->count() !== count($lotIds)
            || $edges->count() !== $identities->count()) {
            throw new AccountingRecognitionConflict(
                'Performance Consideration graph changed during recognition.',
            );
        }

        return [$edges, $lots];
    }

    private function derivePerformanceGrammar(
        Collection $edges,
        Collection $lots,
    ): array {
        $u = BigDecimal::zero();
        $b = BigDecimal::zero();
        $assetLot = null;
        $liabilityEdges = [];

        foreach ($edges as $edge) {
            $before = $lots->get($edge->lot_id);
            $after = $lots->get($edge->successor_lot_id);

            if ($before === null || $after === null) {
                throw new AccountingRecognitionConflict(
                    'Performance Consideration edge references missing Lot truth.',
                );
            }

            $amount = BigDecimal::of((string) $edge->consumed_amount);

            if (
                $before->semantic_position === 'UNPERFORMED_UNBILLED'
                && $after->semantic_position === 'EARNED_UNBILLED'
            ) {
                $u = $u->plus($amount);

                if ($assetLot !== null && $assetLot->id !== $after->id) {
                    throw new AccountingRecognitionConflict(
                        'Performance Accounting v1 requires one EARNED_UNBILLED output Lot.',
                    );
                }

                $assetLot = $after;

                continue;
            }

            if (
                $before->semantic_position === 'BILLED_UNEARNED'
                && $after->semantic_position === 'BILLED_EARNED'
            ) {
                $b = $b->plus($amount);
                $liabilityEdges[] = [
                    'edge_id' => (string) $edge->id,
                    'predecessor_lot_id' => (string) $before->id,
                    'successor_lot_id' => (string) $after->id,
                    'amount' => (string) $amount,
                ];

                continue;
            }

            throw new AccountingRecognitionConflict(
                'Performance Consideration graph contains unsupported accounting grammar.',
            );
        }

        return [$u, $b, $assetLot, $liabilityEdges];
    }

    private function lockLiabilityOrigins(
        string $tenantId,
        string $contractId,
        array $liabilityEdges,
    ): array {
        $locked = [];

        foreach ($liabilityEdges as $edge) {
            $origins = DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->where('position_type', 'CONTRACT_LIABILITY')
                ->where(
                    'consideration_lot_id',
                    $edge['predecessor_lot_id'],
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($origins as $origin) {
                $consumptions = DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $tenantId)
                    ->where('origin_id', $origin->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $locked[$edge['predecessor_lot_id']][] = [
                    'origin' => $origin,
                    'consumptions' => $consumptions,
                ];
            }
        }

        return $locked;
    }

    private function planLiabilityConsumption(
        array $lockedOrigins,
        array $liabilityEdges,
    ): array {
        $plans = [];

        foreach ($liabilityEdges as $edge) {
            $required = BigDecimal::of($edge['amount']);
            $availableTotal = BigDecimal::zero();
            $edgePlans = [];

            foreach (
                $lockedOrigins[$edge['predecessor_lot_id']] ?? [] as $locked
            ) {
                $origin = $locked['origin'];

                if ($origin->status !== 'effective') {
                    continue;
                }

                $used = BigDecimal::zero();

                foreach ($locked['consumptions'] as $consumption) {
                    if ($consumption->status === 'effective') {
                        $used = $used->plus(
                            BigDecimal::of((string) $consumption->amount),
                        );
                    }
                }

                $available = BigDecimal::of(
                    (string) $origin->origin_amount,
                )->minus($used);

                if ($available->isLessThan(BigDecimal::zero())) {
                    throw new AccountingRecognitionConflict(
                        'Contract Liability accounting provenance is over-consumed.',
                    );
                }

                if ($available->isZero()) {
                    continue;
                }

                $availableTotal = $availableTotal->plus($available);

                $edgePlans[] = [
                    'origin' => $origin,
                    'amount' => (string) $available,
                    'predecessor_lot_id' => $edge['predecessor_lot_id'],
                    'successor_lot_id' => $edge['successor_lot_id'],
                ];
            }

            if (! $availableTotal->isEqualTo($required)) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting requires exact Contract Liability predecessor capacity.',
                );
            }

            array_push($plans, ...$edgePlans);
        }

        return $plans;
    }

    private function lockPolicy(
        string $tenantId,
        string $accountingDate,
    ): object {
        $policies = DB::table('performance_accounting_policies')
            ->where('tenant_id', $tenantId)
            ->whereDate('effective_from', '<=', $accountingDate)
            ->where(function ($query) use ($accountingDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $accountingDate);
            })
            ->orderBy('policy_version')
            ->lockForUpdate()
            ->get();

        if ($policies->count() !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting requires exactly one policy applicable to the accounting date.',
            );
        }

        return $policies->first();
    }

    private function liabilityDebits(array $plans): array
    {
        $grouped = [];

        foreach ($plans as $plan) {
            $accountId = (string) $plan['origin']->account_id;
            $current = isset($grouped[$accountId])
                ? BigDecimal::of($grouped[$accountId])
                : BigDecimal::zero();

            $grouped[$accountId] = (string) $current->plus(
                BigDecimal::of($plan['amount']),
            );
        }

        ksort($grouped, SORT_STRING);

        return $grouped;
    }

    private function assertAccountsEligible(
        Collection $accounts,
        object $policy,
        array $liabilityDebits,
        bool $needsAsset,
    ): void {
        $revenue = $accounts->get($policy->revenue_account_id);

        if (
            $revenue === null
            || $revenue->kind !== 'posting'
            || $revenue->status !== 'active'
            || $revenue->account_type !== 'revenue'
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting Revenue account is not eligible.',
            );
        }

        if ($needsAsset) {
            $asset = $accounts->get(
                $policy->contract_asset_control_account_id,
            );

            if (
                $asset === null
                || $asset->kind !== 'posting'
                || $asset->status !== 'active'
                || $asset->account_type !== 'asset'
            ) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting Contract Asset account is not eligible.',
                );
            }
        }

        foreach (array_keys($liabilityDebits) as $accountId) {
            $liability = $accounts->get($accountId);

            if (
                $liability === null
                || $liability->kind !== 'posting'
                || $liability->status !== 'active'
                || $liability->account_type !== 'liability'
            ) {
                throw new AccountingRecognitionConflict(
                    'Historical Contract Liability account is not eligible for exact release.',
                );
            }
        }
    }

    private function writeLiabilityConsumptions(
        string $tenantId,
        string $recognitionId,
        string $contractId,
        string $transitionId,
        string $journalId,
        array $liabilityLineIds,
        array $plans,
        CarbonImmutable $now,
    ): void {
        foreach ($plans as $plan) {
            $origin = $plan['origin'];
            $consumptionId = (string) Str::ulid();
            $leg = 'PA:CONSUME:'
                .$transitionId.':'
                .$plan['predecessor_lot_id'].':'
                .$plan['successor_lot_id'];

            DB::table('accounting_position_consumptions')->insert([
                'id' => $consumptionId,
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'origin_id' => $origin->id,
                'consuming_recognition_type' => 'PERFORMANCE_ACCOUNTING_RECOGNITION',
                'consuming_recognition_id' => $recognitionId,
                'consuming_journal_entry_id' => $journalId,
                'consideration_transition_id' => $transitionId,
                'consideration_lot_id' => $plan['successor_lot_id'],
                'economic_leg_identity' => $leg,
                'amount' => $plan['amount'],
                'currency' => 'SAR',
                'status' => 'effective',
                'created_at' => $now,
                'reversal_operation_id' => null,
                'reversed_at' => null,
            ]);

            $lineId = $liabilityLineIds[$origin->account_id] ?? null;

            if ($lineId === null) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting liability Journal allocation is missing.',
                );
            }

            DB::table(
                'accounting_position_consumption_journal_line_allocations',
            )->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'consumption_id' => $consumptionId,
                'journal_entry_id' => $journalId,
                'journal_line_id' => $lineId,
                'amount' => $plan['amount'],
                'currency' => 'SAR',
                'economic_leg_identity' => $leg,
                'created_at' => $now,
            ]);
        }
    }

    private function writeContractAssetOrigin(
        string $tenantId,
        string $recognitionId,
        string $contractId,
        string $acceptanceId,
        string $transitionId,
        object $assetLot,
        string $accountId,
        string $journalId,
        string $lineId,
        string $amount,
        string $accountingDate,
        CarbonImmutable $now,
    ): void {
        $originId = (string) Str::ulid();
        $leg = 'PA:ORIGIN:'.$transitionId.':'.$assetLot->id;

        DB::table('accounting_position_origins')->insert([
            'id' => $originId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'position_type' => 'CONTRACT_ASSET',
            'origin_recognition_type' => 'PERFORMANCE_ACCOUNTING_RECOGNITION',
            'origin_recognition_id' => $recognitionId,
            'origin_journal_entry_id' => $journalId,
            'account_id' => $accountId,
            'economic_source_type' => 'UNIT_HANDOVER_ACCEPTANCE',
            'economic_source_id' => $acceptanceId,
            'consideration_transition_id' => $transitionId,
            'consideration_lot_id' => $assetLot->id,
            'economic_leg_identity' => $leg,
            'origin_amount' => $amount,
            'currency' => 'SAR',
            'accounting_date' => $accountingDate,
            'status' => 'effective',
            'created_at' => $now,
            'reversal_origin_operation_id' => null,
            'reversed_at' => null,
        ]);

        DB::table(
            'accounting_position_origin_journal_line_allocations',
        )->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'origin_id' => $originId,
            'journal_entry_id' => $journalId,
            'journal_line_id' => $lineId,
            'amount' => $amount,
            'currency' => 'SAR',
            'economic_leg_identity' => $leg,
            'created_at' => $now,
        ]);
    }

    private function assertHistoricalReplay(
        object $recognition,
        object $acceptance,
        object $transition,
        BigDecimal $u,
        BigDecimal $b,
    ): void {
        if (
            $recognition->recognition_kind !== 'original'
            || $recognition->unit_handover_acceptance_id !== $acceptance->id
            || $recognition->performance_consideration_transition_id
                !== $transition->id
            || ! BigDecimal::of(
                (string) $recognition->performance_amount,
            )->isEqualTo(
                BigDecimal::of((string) $acceptance->performance_amount),
            )
            || ! BigDecimal::of(
                (string) $recognition->contract_asset_amount,
            )->isEqualTo($u)
            || ! BigDecimal::of(
                (string) $recognition->contract_liability_release_amount,
            )->isEqualTo($b)
            || (string) $recognition->accounting_date
                !== (string) $acceptance->performance_date
            || $recognition->currency !== 'SAR'
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting operation identity conflicts with historical truth.',
            );
        }
    }
}
