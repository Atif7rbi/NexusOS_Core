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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CorrectPerformanceAccounting
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
            'performance_accounting_correction_operation_id',
            'correction_reason',
            'correction_reference',
        ];

        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting correction accepts correction identities and evidence only.',
            );
        }

        $acceptanceId = (string) (
            $input['unit_handover_acceptance_id'] ?? ''
        );
        $operationId = (string) (
            $input['performance_accounting_correction_operation_id'] ?? ''
        );
        $reason = trim((string) ($input['correction_reason'] ?? ''));
        $reference = trim((string) ($input['correction_reference'] ?? ''));

        if (! Str::isUlid($acceptanceId) || ! Str::isUlid($operationId)) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting correction identities must be ULIDs.',
            );
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new AccountingRecognitionValidationFailed(
                'correction_reason is required and must not exceed 500 characters.',
            );
        }

        if ($reference === '' || mb_strlen($reference) > 255) {
            throw new AccountingRecognitionValidationFailed(
                'correction_reference is required and must not exceed 255 characters.',
            );
        }

        $this->authorization->authorize($tenantId, $actor);

        try {
            return $this->transaction->run(
                fn (): string => $this->correct(
                    $tenantId,
                    $actor,
                    $acceptanceId,
                    $operationId,
                    $reason,
                    $reference,
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

    private function correct(
        string $tenantId,
        User $actor,
        string $acceptanceId,
        string $operationId,
        string $reason,
        string $reference,
    ): string {
        $this->authorization->authorizeTransactional($tenantId, $actor);

        $identity = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->first();

        if ($identity === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction source Handover does not exist.',
            );
        }

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->contract_id)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction source Contract is missing.',
            );
        }

        $reservation = DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $contract->reservation_id)
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction source Reservation is missing.',
            );
        }

        $unit = DB::table('units')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservation->unit_id)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction source Unit is missing.',
            );
        }

        $evidence = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where('id', $identity->handover_evidence_id)
            ->lockForUpdate()
            ->first();

        $acceptance = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if (
            $evidence === null
            || $acceptance === null
            || $acceptance->contract_id !== $contract->id
            || $acceptance->reservation_id !== $reservation->id
            || $acceptance->unit_id !== $unit->id
            || $acceptance->handover_evidence_id !== $evidence->id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction source provenance is inconsistent.',
            );
        }

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)
            ->lockForUpdate()
            ->first();

        if ($position === null || $position->status !== 'ADOPTED') {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires adopted Contract Consideration.',
            );
        }

        $transition = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)
            ->where('source_type', 'UNIT_HANDOVER_ACCEPTANCE')
            ->where('source_id', $acceptanceId)
            ->lockForUpdate()
            ->first();

        if ($transition === null) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires the exact Handover Consideration Transition.',
            );
        }

        $lotIds = DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transition->id)
            ->orderBy('id')
            ->get()
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

        DB::table('contract_consideration_lots')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $lotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transition->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $history = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('unit_handover_acceptance_id', $acceptanceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $byOperation = $history->firstWhere(
            'performance_accounting_correction_operation_id',
            $operationId,
        );

        if ($byOperation !== null) {
            if (
                $byOperation->unit_handover_acceptance_id !== $acceptanceId
                || $byOperation->performance_consideration_transition_id
                    !== $transition->id
                || $byOperation->recognition_kind !== 'accounting_correction'
                || $byOperation->correction_reason !== $reason
                || $byOperation->correction_reference !== $reference
            ) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting correction operation conflicts with historical truth.',
                );
            }

            $predecessor = $history->firstWhere(
                'id',
                $byOperation->predecessor_recognition_id,
            );

            if (
                $predecessor === null
                || $predecessor->reversal_operation_id !== $operationId
                || $predecessor->status !== 'reversed'
            ) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting correction replay has incomplete predecessor reversal truth.',
                );
            }

            return (string) $byOperation->id;
        }

        if (
            $acceptance->status !== 'effective'
            || $transition->status !== 'effective'
        ) {
            throw new AccountingRecognitionConflict(
                'New Performance Accounting correction requires effective economic source truth.',
            );
        }

        $effective = $history->where('status', 'posted')->values();

        if ($effective->count() !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires exactly one effective lineage leaf.',
            );
        }

        $leaf = $effective->first();

        if ($leaf->performance_consideration_transition_id !== $transition->id) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting effective leaf belongs to another economic Transition.',
            );
        }

        $assetOrigins = DB::table('accounting_position_origins')
            ->where('tenant_id', $tenantId)
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('origin_recognition_id', $leaf->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($assetOrigins as $origin) {
            $downstream = DB::table('accounting_position_consumptions')
                ->where('tenant_id', $tenantId)
                ->where('origin_id', $origin->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($downstream->contains(
                static fn (object $row): bool => $row->status === 'effective',
            )) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting correction is blocked by effective downstream Contract Asset consumption.',
                );
            }
        }

        $predecessorConsumptions = DB::table(
            'accounting_position_consumptions',
        )
            ->where('tenant_id', $tenantId)
            ->where(
                'consuming_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('consuming_recognition_id', $leaf->id)
            ->orderBy('origin_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $originIds = $predecessorConsumptions
            ->pluck('origin_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $liabilityOrigins = DB::table('accounting_position_origins')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $originIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($predecessorConsumptions as $consumption) {
            $origin = $liabilityOrigins->get($consumption->origin_id);

            if (
                $consumption->status !== 'effective'
                || $origin === null
                || $origin->status !== 'effective'
                || $origin->position_type !== 'CONTRACT_LIABILITY'
            ) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting correction requires exact effective predecessor liability provenance.',
                );
            }
        }

        DB::table('accounting_settings')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first()
            ?? throw new AccountingRecognitionConflict(
                'Accounting settings are missing for Performance Accounting correction.',
            );

        $policies = DB::table('performance_accounting_policies')
            ->where('tenant_id', $tenantId)
            ->whereDate('effective_from', '<=', $leaf->accounting_date)
            ->where(function ($query) use ($leaf): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $leaf->accounting_date);
            })
            ->orderBy('policy_version')
            ->lockForUpdate()
            ->get();

        if ($policies->count() !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires exactly one corrected policy applicable to the accounting date.',
            );
        }

        $policy = $policies->first();

        if (
            $policy->id === $leaf->performance_accounting_policy_id
            && (int) $policy->policy_version
                === (int) $leaf->performance_accounting_policy_version
            && $policy->revenue_account_id === $leaf->revenue_account_id
            && $policy->contract_asset_control_account_id
                === $leaf->contract_asset_account_id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires a changed policy/accounting mapping.',
            );
        }

        $periods = DB::table('accounting_periods')
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $leaf->accounting_date)
            ->whereDate('end_date', '>=', $leaf->accounting_date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($periods->count() !== 1 || $periods->first()->status !== 'open') {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction requires the original Accounting Period to remain open.',
            );
        }

        $liabilityDebits = [];

        foreach ($predecessorConsumptions as $consumption) {
            $origin = $liabilityOrigins->get($consumption->origin_id);
            $accountId = (string) $origin->account_id;
            $current = isset($liabilityDebits[$accountId])
                ? BigDecimal::of($liabilityDebits[$accountId])
                : BigDecimal::zero();

            $liabilityDebits[$accountId] = (string) $current->plus(
                BigDecimal::of((string) $consumption->amount),
            );
        }

        ksort($liabilityDebits, SORT_STRING);

        $accountIds = array_keys($liabilityDebits);
        $accountIds[] = (string) $policy->revenue_account_id;

        if (BigDecimal::of((string) $leaf->contract_asset_amount)->isPositive()) {
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
                'Performance Accounting correction required Account is missing.',
            );
        }

        $revenue = $accounts->get($policy->revenue_account_id);

        if (
            $revenue === null
            || $revenue->kind !== 'posting'
            || $revenue->status !== 'active'
            || $revenue->account_type !== 'revenue'
        ) {
            throw new AccountingRecognitionConflict(
                'Corrected Performance Revenue account is not eligible.',
            );
        }

        if (BigDecimal::of((string) $leaf->contract_asset_amount)->isPositive()) {
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
                    'Corrected Performance Contract Asset account is not eligible.',
                );
            }
        }

        foreach (array_keys($liabilityDebits) as $accountId) {
            $liability = $accounts->get($accountId);

            if (
                $liability === null
                || $liability->kind !== 'posting'
                || $liability->account_type !== 'liability'
            ) {
                throw new AccountingRecognitionConflict(
                    'Historical Contract Liability account is not eligible for correction reconsumption.',
                );
            }
        }

        $predecessorJournal = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('id', $leaf->journal_entry_id)
            ->lockForUpdate()
            ->first();

        if (
            $predecessorJournal === null
            || $predecessorJournal->status !== 'posted'
            || $predecessorJournal->source_type
                !== 'performance_accounting_recognition'
            || $predecessorJournal->source_id !== $leaf->id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting correction cannot resolve the exact predecessor Journal.',
            );
        }

        DB::table('journal_lines')
            ->where('tenant_id', $tenantId)
            ->where('journal_entry_id', $predecessorJournal->id)
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get();

        $reversalJournalId = $this->journals->reverseExact(
            $tenantId,
            $actor,
            $predecessorJournal,
            $reason,
        );

        $now = CarbonImmutable::now('UTC');

        foreach ($predecessorConsumptions as $consumption) {
            DB::table('accounting_position_consumptions')
                ->where('tenant_id', $tenantId)
                ->where('id', $consumption->id)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $operationId,
                    'reversed_at' => $now,
                ]);
        }

        foreach ($assetOrigins as $origin) {
            if ($origin->status !== 'effective') {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting correction found non-effective predecessor Contract Asset provenance.',
                );
            }

            DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where('id', $origin->id)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_origin_operation_id' => $operationId,
                    'reversed_at' => $now,
                ]);
        }

        $predecessorUpdated = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('id', $leaf->id)
            ->where('status', 'posted')
            ->update([
                'status' => 'reversed',
                'reversal_operation_id' => $operationId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'reversed_by' => $actor->id,
                'reversed_at' => $now,
            ]);

        if ($predecessorUpdated !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting predecessor changed during correction.',
            );
        }

        $successorId = (string) Str::ulid();

        $journal = $this->journals->postRecognition(
            $tenantId,
            $actor,
            $successorId,
            (string) $leaf->accounting_date,
            $liabilityDebits,
            BigDecimal::of(
                (string) $leaf->contract_asset_amount,
            )->isZero()
                ? null
                : [
                    'account_id' =>
                        (string) $policy->contract_asset_control_account_id,
                    'amount' => (string) $leaf->contract_asset_amount,
                ],
            [
                'account_id' => (string) $policy->revenue_account_id,
                'amount' => (string) $leaf->revenue_amount,
            ],
        );

        DB::table('performance_accounting_recognitions')->insert([
            'id' => $successorId,
            'tenant_id' => $tenantId,
            'contract_id' => $leaf->contract_id,
            'unit_handover_acceptance_id' =>
                $leaf->unit_handover_acceptance_id,
            'performance_consideration_transition_id' =>
                $leaf->performance_consideration_transition_id,
            'recognition_kind' => 'accounting_correction',
            'performance_accounting_operation_id' => null,
            'performance_accounting_correction_operation_id' => $operationId,
            'correction_reason' => $reason,
            'correction_reference' => $reference,
            'root_recognition_id' => $leaf->root_recognition_id,
            'predecessor_recognition_id' => $leaf->id,
            'performance_amount' => $leaf->performance_amount,
            'currency' => $leaf->currency,
            'accounting_date' => $leaf->accounting_date,
            'contract_asset_amount' => $leaf->contract_asset_amount,
            'contract_liability_release_amount' =>
                $leaf->contract_liability_release_amount,
            'revenue_amount' => $leaf->revenue_amount,
            'performance_accounting_policy_id' => $policy->id,
            'performance_accounting_policy_version' =>
                $policy->policy_version,
            'contract_asset_account_id' =>
                BigDecimal::of(
                    (string) $leaf->contract_asset_amount,
                )->isZero()
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

        foreach ($predecessorConsumptions as $consumption) {
            $origin = $liabilityOrigins->get($consumption->origin_id);
            $lineId = $journal['liability_line_ids'][$origin->account_id]
                ?? null;

            if ($lineId === null) {
                throw new AccountingRecognitionConflict(
                    'Corrected Performance liability Journal allocation is missing.',
                );
            }

            $consumptionId = (string) Str::ulid();

            DB::table('accounting_position_consumptions')->insert([
                'id' => $consumptionId,
                'tenant_id' => $tenantId,
                'contract_id' => $leaf->contract_id,
                'origin_id' => $consumption->origin_id,
                'consuming_recognition_type' =>
                    'PERFORMANCE_ACCOUNTING_RECOGNITION',
                'consuming_recognition_id' => $successorId,
                'consuming_journal_entry_id' => $journal['journal_entry_id'],
                'consideration_transition_id' =>
                    $consumption->consideration_transition_id,
                'consideration_lot_id' => $consumption->consideration_lot_id,
                'economic_leg_identity' =>
                    $consumption->economic_leg_identity,
                'amount' => $consumption->amount,
                'currency' => $consumption->currency,
                'status' => 'effective',
                'created_at' => $now,
                'reversal_operation_id' => null,
                'reversed_at' => null,
            ]);

            DB::table(
                'accounting_position_consumption_journal_line_allocations',
            )->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'contract_id' => $leaf->contract_id,
                'consumption_id' => $consumptionId,
                'journal_entry_id' => $journal['journal_entry_id'],
                'journal_line_id' => $lineId,
                'amount' => $consumption->amount,
                'currency' => $consumption->currency,
                'economic_leg_identity' =>
                    $consumption->economic_leg_identity,
                'created_at' => $now,
            ]);
        }

        foreach ($assetOrigins as $origin) {
            $lineId = $journal['contract_asset_line_id'];

            if ($lineId === null) {
                throw new AccountingRecognitionConflict(
                    'Corrected Performance Contract Asset Journal allocation is missing.',
                );
            }

            $newOriginId = (string) Str::ulid();

            DB::table('accounting_position_origins')->insert([
                'id' => $newOriginId,
                'tenant_id' => $tenantId,
                'contract_id' => $leaf->contract_id,
                'position_type' => 'CONTRACT_ASSET',
                'origin_recognition_type' =>
                    'PERFORMANCE_ACCOUNTING_RECOGNITION',
                'origin_recognition_id' => $successorId,
                'origin_journal_entry_id' => $journal['journal_entry_id'],
                'account_id' =>
                    $policy->contract_asset_control_account_id,
                'economic_source_type' => $origin->economic_source_type,
                'economic_source_id' => $origin->economic_source_id,
                'consideration_transition_id' =>
                    $origin->consideration_transition_id,
                'consideration_lot_id' => $origin->consideration_lot_id,
                'economic_leg_identity' => $origin->economic_leg_identity,
                'origin_amount' => $origin->origin_amount,
                'currency' => $origin->currency,
                'accounting_date' => $origin->accounting_date,
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
                'contract_id' => $leaf->contract_id,
                'origin_id' => $newOriginId,
                'journal_entry_id' => $journal['journal_entry_id'],
                'journal_line_id' => $lineId,
                'amount' => $origin->origin_amount,
                'currency' => $origin->currency,
                'economic_leg_identity' => $origin->economic_leg_identity,
                'created_at' => $now,
            ]);
        }

        $this->audit->write(
            $tenantId,
            'performance_accounting.corrected',
            'performance_accounting_recognition',
            $successorId,
            (int) $actor->id,
            [
                'predecessor_recognition_id' => (string) $leaf->id,
                'performance_accounting_correction_operation_id' =>
                    $operationId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'successor_journal_entry_id' =>
                    (string) $journal['journal_entry_id'],
                'correction_reference' => $reference,
            ],
            $now,
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $successorId;
    }
}
