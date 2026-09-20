<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionValidationFailed;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionAuthorization;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionTransaction;
use App\Modules\AccountingRecognition\Support\ReceivableArJournalWriter;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecognizeReceivableAr
{
    public function __construct(
        private readonly AccountingRecognitionTransaction $transaction,
        private readonly AccountingRecognitionAuthorization $authorization,
        private readonly ReceivableArJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        array $input,
    ): string {
        $allowed = [
            'contractual_billing_entitlement_id',
            'receivable_ar_operation_id',
        ];

        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AccountingRecognitionValidationFailed(
                'Receivable AR Recognition accepts source and operation identities only.',
            );
        }

        $entitlementId = (string) (
            $input['contractual_billing_entitlement_id'] ?? ''
        );
        $operationId = (string) (
            $input['receivable_ar_operation_id'] ?? ''
        );

        if (! Str::isUlid($entitlementId) || ! Str::isUlid($operationId)) {
            throw new AccountingRecognitionValidationFailed(
                'Receivable AR Recognition identities must be ULIDs.',
            );
        }

        $this->authorization->authorize($tenantId, $actor);

        $committed = $this->resolveCommitted(
            $tenantId,
            $entitlementId,
            $operationId,
        );

        if ($committed !== null) {
            return $committed;
        }

        try {
            return $this->transaction->run(
                fn (): string => $this->recognize(
                    $tenantId,
                    $actor,
                    $entitlementId,
                    $operationId,
                ),
                preserveUniqueViolation: true,
            );
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            $committed = $this->resolveCommitted(
                $tenantId,
                $entitlementId,
                $operationId,
            );

            if ($committed !== null) {
                return $committed;
            }

            throw new AccountingRecognitionConflict(
                'Receivable AR uniqueness conflict has no exact committed replay winner.',
                previous: $exception,
            );
        }
    }

    private function resolveCommitted(
        string $tenantId,
        string $entitlementId,
        string $operationId,
    ): ?string {
        $winner = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('receivable_ar_operation_id', $operationId)
            ->first();

        if ($winner === null) {
            return null;
        }

        if (
            $winner->contractual_billing_entitlement_id !== $entitlementId
            || $winner->recognition_kind !== 'original'
        ) {
            throw new AccountingRecognitionConflict(
                'Receivable AR operation is already owned by different canonical truth.',
            );
        }

        return (string) $winner->id;
    }

    private function recognize(
        string $tenantId,
        User $actor,
        string $entitlementId,
        string $operationId,
    ): string {
        $this->authorization->authorizeTransactional($tenantId, $actor);

        $identity = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('id', $entitlementId)
            ->first();

        if ($identity === null) {
            throw new AccountingRecognitionConflict(
                'Receivable AR source Entitlement does not exist.',
            );
        }

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->contract_id)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new AccountingRecognitionConflict(
                'Receivable AR source Contract is missing.',
            );
        }

        $schedule = DB::table('contractual_billing_schedules')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->schedule_id)
            ->lockForUpdate()
            ->first();

        $obligation = DB::table('contractual_billing_obligations')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->obligation_id)
            ->lockForUpdate()
            ->first();

        $entitlement = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('id', $entitlementId)
            ->lockForUpdate()
            ->first();

        if (
            $schedule === null
            || $obligation === null
            || $entitlement === null
            || $schedule->contract_id !== $contract->id
            || $obligation->contract_id !== $contract->id
            || $obligation->schedule_id !== $schedule->id
            || $entitlement->contract_id !== $contract->id
            || $entitlement->schedule_id !== $schedule->id
            || $entitlement->obligation_id !== $obligation->id
        ) {
            throw new AccountingRecognitionConflict(
                'Receivable AR Entitlement source provenance is inconsistent.',
            );
        }

        $link = DB::table('entitlement_receivable_links')
            ->where('tenant_id', $tenantId)
            ->where('entitlement_id', $entitlementId)
            ->lockForUpdate()
            ->first();

        if ($link === null) {
            throw new AccountingRecognitionConflict(
                'Receivable AR requires exact Entitlement-to-Receivable provenance.',
            );
        }

        $receivable = DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $link->receivable_id)
            ->lockForUpdate()
            ->first();

        if (
            $receivable === null
            || $link->source_correction_operation_id !== null
            || $receivable->status !== 'recognized'
            || $receivable->recognition_operation_id
                !== $link->receivable_establishment_operation_id
            || $receivable->contract_id !== $entitlement->contract_id
            || $receivable->customer_id !== $entitlement->customer_id
            || $receivable->collection_id !== null
            || (string) $receivable->recognized_amount
                !== (string) $entitlement->amount
            || $receivable->currency !== $entitlement->currency
            || (string) $receivable->due_date
                !== (string) $entitlement->economic_date
        ) {
            throw new AccountingRecognitionConflict(
                'Receivable AR exact Receivable provenance is inconsistent.',
            );
        }

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)
            ->lockForUpdate()
            ->first();

        if ($position === null || $position->status !== 'ADOPTED') {
            throw new AccountingRecognitionConflict(
                'Receivable AR requires adopted Contract Consideration.',
            );
        }

        $transition = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)
            ->where('source_type', 'CONTRACTUAL_BILLING_ENTITLEMENT')
            ->where('source_id', $entitlementId)
            ->lockForUpdate()
            ->first();

        if ($transition === null) {
            throw new AccountingRecognitionConflict(
                'Receivable AR requires the exact Billing Consideration Transition.',
            );
        }

        [$edges, $lots] = $this->lockTransitionGraph(
            $tenantId,
            (string) $transition->id,
        );

        [$liabilityAmount, $assetAmount, $liabilityLot, $assetEdges] =
            $this->deriveBillingGrammar($edges, $lots);

        $amount = BigDecimal::of((string) $entitlement->amount);

        if (
            $entitlement->status !== 'effective'
            || $schedule->status !== 'finalized'
            || $transition->status !== 'effective'
            || $entitlement->currency !== 'SAR'
            || $receivable->currency !== 'SAR'
            || $transition->currency !== 'SAR'
            || ! BigDecimal::of((string) $transition->transition_amount)
                ->isEqualTo($amount)
            || ! $liabilityAmount->plus($assetAmount)->isEqualTo($amount)
            || (string) $transition->economic_date
                !== (string) $entitlement->economic_date
        ) {
            throw new AccountingRecognitionConflict(
                'Receivable AR source is not eligible for accounting recognition.',
            );
        }

        $existingSource = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('contractual_billing_entitlement_id', $entitlementId)
            ->lockForUpdate()
            ->first();

        $existingOperation = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('receivable_ar_operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if ($existingSource !== null || $existingOperation !== null) {
            if (
                $existingSource === null
                || $existingOperation === null
                || $existingSource->id !== $existingOperation->id
                || $existingSource->recognition_kind !== 'original'
                || $existingSource->receivable_id !== $receivable->id
                || $existingSource->billing_consideration_transition_id
                    !== $transition->id
                || (string) $existingSource->receivable_amount
                    !== (string) $entitlement->amount
                || (string) $existingSource->accounting_date
                    !== (string) $entitlement->economic_date
            ) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source or operation already has different historical truth.',
                );
            }

            return (string) $existingSource->id;
        }

        $plans = $this->lockAndPlanContractAssetConsumption(
            $tenantId,
            (string) $contract->id,
            $assetEdges,
        );

        $arPolicy = $this->lockPolicy(
            'receivable_ar_policies',
            $tenantId,
            (string) $entitlement->economic_date,
        );
        $counterpartPolicy = $this->lockPolicy(
            'receivable_ar_counterpart_policies',
            $tenantId,
            (string) $entitlement->economic_date,
        );

        $assetCredits = $this->assetCredits($plans);
        $accountIds = array_values(array_unique(array_merge(
            [(string) $arPolicy->ar_control_account_id],
            $liabilityAmount->isZero()
                ? []
                : [(string) $counterpartPolicy->contract_liability_control_account_id],
            array_keys($assetCredits),
        )));
        sort($accountIds, SORT_STRING);

        $accounts = DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertAccountsEligible(
            $accounts,
            $arPolicy,
            $counterpartPolicy,
            $assetCredits,
            ! $liabilityAmount->isZero(),
        );

        $recognitionId = (string) Str::ulid();

        $journal = $this->journals->postRecognition(
            $tenantId,
            $actor,
            $recognitionId,
            (string) $entitlement->economic_date,
            [
                'account_id' => (string) $arPolicy->ar_control_account_id,
                'amount' => (string) $amount,
            ],
            $assetCredits,
            $liabilityAmount->isZero()
                ? null
                : [
                    'account_id' => (string) $counterpartPolicy
                        ->contract_liability_control_account_id,
                    'amount' => (string) $liabilityAmount,
                ],
        );

        $now = CarbonImmutable::now('UTC');

        DB::table('receivable_ar_recognitions')->insert([
            'id' => $recognitionId,
            'tenant_id' => $tenantId,
            'contract_id' => (string) $contract->id,
            'contractual_billing_entitlement_id' => $entitlementId,
            'receivable_id' => (string) $receivable->id,
            'billing_consideration_transition_id' => (string) $transition->id,
            'recognition_kind' => 'original',
            'receivable_ar_operation_id' => $operationId,
            'receivable_amount' => (string) $amount,
            'contract_asset_release_amount' => (string) $assetAmount,
            'contract_liability_creation_amount' => (string) $liabilityAmount,
            'currency' => 'SAR',
            'accounting_date' => (string) $entitlement->economic_date,
            'receivable_ar_policy_id' => (string) $arPolicy->id,
            'receivable_ar_policy_version' => (int) $arPolicy->policy_version,
            'counterpart_policy_id' => (string) $counterpartPolicy->id,
            'counterpart_policy_version' => (int) $counterpartPolicy->policy_version,
            'ar_control_account_id' => (string) $arPolicy->ar_control_account_id,
            'counterpart_contract_asset_account_id' =>
                (string) $counterpartPolicy->contract_asset_control_account_id,
            'contract_liability_account_id' => $liabilityAmount->isZero()
                ? null
                : (string) $counterpartPolicy->contract_liability_control_account_id,
            'journal_entry_id' => (string) $journal['journal_entry_id'],
            'status' => 'posted',
            'created_by' => $actor->id,
            'created_at' => $now,
            'reversal_operation_id' => null,
            'reversal_journal_entry_id' => null,
            'reversed_by' => null,
            'reversed_at' => null,
        ]);

        $this->writeContractAssetConsumptions(
            $tenantId,
            $recognitionId,
            (string) $contract->id,
            (string) $transition->id,
            (string) $journal['journal_entry_id'],
            $journal['contract_asset_line_ids'],
            $plans,
            $now,
        );

        if (! $liabilityAmount->isZero()) {
            if ($liabilityLot === null
                || $journal['contract_liability_line_id'] === null) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR Contract Liability provenance is incomplete.',
                );
            }

            $this->writeContractLiabilityOrigin(
                $tenantId,
                $recognitionId,
                (string) $contract->id,
                $entitlementId,
                (string) $transition->id,
                $liabilityLot,
                (string) $counterpartPolicy->contract_liability_control_account_id,
                (string) $journal['journal_entry_id'],
                (string) $journal['contract_liability_line_id'],
                (string) $liabilityAmount,
                (string) $entitlement->economic_date,
                $now,
            );
        }

        $this->audit->write(
            $tenantId,
            'receivable_ar.recognized',
            'receivable_ar_recognition',
            $recognitionId,
            (int) $actor->id,
            [
                'contractual_billing_entitlement_id' => $entitlementId,
                'receivable_id' => (string) $receivable->id,
                'journal_entry_id' => (string) $journal['journal_entry_id'],
                'receivable_ar_operation_id' => $operationId,
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
                'Billing Consideration Transition has no economic graph.',
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
                'Billing Consideration graph changed during Receivable AR recognition.',
            );
        }

        return [$edges, $lots];
    }

    private function deriveBillingGrammar(
        Collection $edges,
        Collection $lots,
    ): array {
        $liability = BigDecimal::zero();
        $asset = BigDecimal::zero();
        $liabilityLot = null;
        $assetEdges = [];

        foreach ($edges as $edge) {
            $before = $lots->get($edge->lot_id);
            $after = $lots->get($edge->successor_lot_id);

            if ($before === null || $after === null) {
                throw new AccountingRecognitionConflict(
                    'Billing Consideration edge references missing Lot truth.',
                );
            }

            $amount = BigDecimal::of((string) $edge->consumed_amount);

            if (
                $before->semantic_position === 'UNPERFORMED_UNBILLED'
                && $after->semantic_position === 'BILLED_UNEARNED'
            ) {
                $liability = $liability->plus($amount);

                if ($liabilityLot !== null && $liabilityLot->id !== $after->id) {
                    throw new AccountingRecognitionConflict(
                        'Receivable AR v1 requires one BILLED_UNEARNED output Lot.',
                    );
                }

                $liabilityLot = $after;

                continue;
            }

            if (
                $before->semantic_position === 'EARNED_UNBILLED'
                && $after->semantic_position === 'BILLED_EARNED'
            ) {
                $asset = $asset->plus($amount);
                $assetEdges[] = [
                    'edge_id' => (string) $edge->id,
                    'predecessor_lot_id' => (string) $before->id,
                    'successor_lot_id' => (string) $after->id,
                    'amount' => (string) $amount,
                ];

                continue;
            }

            throw new AccountingRecognitionConflict(
                'Billing Consideration graph contains unsupported Receivable AR grammar.',
            );
        }

        return [$liability, $asset, $liabilityLot, $assetEdges];
    }

    private function lockAndPlanContractAssetConsumption(
        string $tenantId,
        string $contractId,
        array $assetEdges,
    ): array {
        $plans = [];

        foreach ($assetEdges as $edge) {
            $origins = DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->where('position_type', 'CONTRACT_ASSET')
                ->where('consideration_lot_id', $edge['predecessor_lot_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $required = BigDecimal::of($edge['amount']);
            $availableTotal = BigDecimal::zero();
            $edgePlans = [];

            foreach ($origins as $origin) {
                $consumptions = DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $tenantId)
                    ->where('origin_id', $origin->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($origin->status !== 'effective') {
                    continue;
                }

                $used = BigDecimal::zero();

                foreach ($consumptions as $consumption) {
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
                        'Contract Asset accounting provenance is over-consumed.',
                    );
                }

                if ($available->isZero()) {
                    continue;
                }

                $take = $available->isLessThan($required->minus($availableTotal))
                    ? $available
                    : $required->minus($availableTotal);

                if ($take->isZero()) {
                    continue;
                }

                $availableTotal = $availableTotal->plus($take);
                $edgePlans[] = [
                    'origin' => $origin,
                    'amount' => (string) $take,
                    'predecessor_lot_id' => $edge['predecessor_lot_id'],
                    'successor_lot_id' => $edge['successor_lot_id'],
                ];

                if ($availableTotal->isEqualTo($required)) {
                    break;
                }
            }

            if (! $availableTotal->isEqualTo($required)) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR requires exact historical Contract Asset predecessor capacity.',
                );
            }

            array_push($plans, ...$edgePlans);
        }

        return $plans;
    }

    private function lockPolicy(
        string $table,
        string $tenantId,
        string $accountingDate,
    ): object {
        $policies = DB::table($table)
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
                'Receivable AR requires exactly one policy applicable to the accounting date.',
            );
        }

        return $policies->first();
    }

    private function assetCredits(array $plans): array
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
        object $arPolicy,
        object $counterpartPolicy,
        array $assetCredits,
        bool $needsLiability,
    ): void {
        $ar = $accounts->get($arPolicy->ar_control_account_id);

        if (
            $ar === null
            || $ar->kind !== 'posting'
            || $ar->status !== 'active'
            || $ar->account_type !== 'asset'
        ) {
            throw new AccountingRecognitionConflict(
                'Receivable AR control account is not eligible.',
            );
        }

        if ($needsLiability) {
            $liability = $accounts->get(
                $counterpartPolicy->contract_liability_control_account_id,
            );

            if (
                $liability === null
                || $liability->kind !== 'posting'
                || $liability->status !== 'active'
                || $liability->account_type !== 'liability'
            ) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR Contract Liability account is not eligible.',
                );
            }
        }

        foreach (array_keys($assetCredits) as $accountId) {
            $asset = $accounts->get($accountId);

            if (
                $asset === null
                || $asset->kind !== 'posting'
                || $asset->status !== 'active'
                || $asset->account_type !== 'asset'
            ) {
                throw new AccountingRecognitionConflict(
                    'Historical Contract Asset account is not eligible for exact release.',
                );
            }
        }
    }

    private function writeContractAssetConsumptions(
        string $tenantId,
        string $recognitionId,
        string $contractId,
        string $transitionId,
        string $journalId,
        array $assetLineIds,
        array $plans,
        CarbonImmutable $now,
    ): void {
        foreach ($plans as $plan) {
            $origin = $plan['origin'];
            $consumptionId = (string) Str::ulid();
            $leg = 'AR:CONSUME:'
                .$transitionId.':'
                .$plan['predecessor_lot_id'].':'
                .$plan['successor_lot_id'];

            DB::table('accounting_position_consumptions')->insert([
                'id' => $consumptionId,
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'origin_id' => $origin->id,
                'consuming_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
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

            $lineId = $assetLineIds[$origin->account_id] ?? null;

            if ($lineId === null) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR Contract Asset Journal allocation is missing.',
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

    private function writeContractLiabilityOrigin(
        string $tenantId,
        string $recognitionId,
        string $contractId,
        string $entitlementId,
        string $transitionId,
        object $liabilityLot,
        string $accountId,
        string $journalId,
        string $lineId,
        string $amount,
        string $accountingDate,
        CarbonImmutable $now,
    ): void {
        $originId = (string) Str::ulid();
        $leg = 'AR:ORIGIN:'.$transitionId.':'.$liabilityLot->id;

        DB::table('accounting_position_origins')->insert([
            'id' => $originId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'position_type' => 'CONTRACT_LIABILITY',
            'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
            'origin_recognition_id' => $recognitionId,
            'origin_journal_entry_id' => $journalId,
            'account_id' => $accountId,
            'economic_source_type' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
            'economic_source_id' => $entitlementId,
            'consideration_transition_id' => $transitionId,
            'consideration_lot_id' => $liabilityLot->id,
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
}
