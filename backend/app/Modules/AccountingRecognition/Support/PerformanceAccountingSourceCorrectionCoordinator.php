<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Support;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PerformanceAccountingSourceCorrectionCoordinator
{
    public function __construct(
        private readonly PerformanceAccountingJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    public function reverseForSourceCorrection(
        string $tenantId,
        object $acceptance,
        User $actor,
        string $sourceReversalOperationId,
        string $reason,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Performance Accounting source correction requires a caller-owned transaction.',
            );
        }

        $transition = $this->lockPerformanceTransition(
            $tenantId,
            (string) $acceptance->contract_id,
            (string) $acceptance->id,
        );

        if ($transition === null) {
            return;
        }

        $this->lockTransitionGraph(
            $tenantId,
            (string) $transition->id,
        );

        $history = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where(
                'unit_handover_acceptance_id',
                $acceptance->id,
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($history->isEmpty()) {
            return;
        }

        $effective = $history->where('status', 'posted')->values();

        if ($effective->count() !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source correction requires exactly one effective lineage leaf.',
            );
        }

        $leaf = $effective->first();

        if (
            $leaf->performance_consideration_transition_id
                !== $transition->id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting effective leaf belongs to another Consideration Transition.',
            );
        }

        $origins = DB::table('accounting_position_origins')
            ->where('tenant_id', $tenantId)
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('origin_recognition_id', $leaf->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($origins as $origin) {
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
                    'Performance Accounting source correction is blocked by effective downstream Contract Asset consumption.',
                );
            }
        }

        $consumptions = DB::table('accounting_position_consumptions')
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

        $originIds = $consumptions
            ->pluck('origin_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($originIds !== []) {
            DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $originIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $journal = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('id', $leaf->journal_entry_id)
            ->lockForUpdate()
            ->first();

        if (
            $journal === null
            || $journal->status !== 'posted'
            || $journal->source_type
                !== 'performance_accounting_recognition'
            || $journal->source_id !== $leaf->id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source correction cannot resolve the exact Posted Journal.',
            );
        }

        $journalLines = DB::table('journal_lines')
            ->where('tenant_id', $tenantId)
            ->where('journal_entry_id', $journal->id)
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get();

        if ($journalLines->count() < 2) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source correction found incomplete Journal lines.',
            );
        }

        DB::table('accounting_settings')
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first()
            ?? throw new AccountingRecognitionConflict(
                'Accounting settings are missing for Performance source correction.',
            );

        $periods = DB::table('accounting_periods')
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $leaf->accounting_date)
            ->whereDate('end_date', '>=', $leaf->accounting_date)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($periods->count() !== 1 || $periods->first()->status !== 'open') {
            throw new AccountingRecognitionConflict(
                'Performance source correction requires the original Accounting Period to remain open.',
            );
        }

        $accountIds = $journalLines
            ->pluck('account_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $accounts = DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($accounts->count() !== count($accountIds)) {
            throw new AccountingRecognitionConflict(
                'Performance source correction is missing an exact historical Account.',
            );
        }

        $reversalJournalId = $this->journals->reverseExact(
            $tenantId,
            $actor,
            $journal,
            $reason,
        );

        $now = CarbonImmutable::now('UTC');

        foreach ($consumptions as $consumption) {
            if ($consumption->status !== 'effective') {
                throw new AccountingRecognitionConflict(
                    'Performance source correction found non-effective liability consumption on the effective leaf.',
                );
            }

            DB::table('accounting_position_consumptions')
                ->where('tenant_id', $tenantId)
                ->where('id', $consumption->id)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $sourceReversalOperationId,
                    'reversed_at' => $now,
                ]);
        }

        foreach ($origins as $origin) {
            if ($origin->status !== 'effective') {
                throw new AccountingRecognitionConflict(
                    'Performance source correction found non-effective Contract Asset origin on the effective leaf.',
                );
            }

            DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where('id', $origin->id)
                ->where('status', 'effective')
                ->update([
                    'status' => 'reversed',
                    'reversal_origin_operation_id' => $sourceReversalOperationId,
                    'reversed_at' => $now,
                ]);
        }

        $updated = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where('id', $leaf->id)
            ->where('status', 'posted')
            ->update([
                'status' => 'reversed',
                'reversal_operation_id' => $sourceReversalOperationId,
                'reversal_journal_entry_id' => $reversalJournalId,
                'reversed_by' => $actor->id,
                'reversed_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting effective leaf changed during source correction.',
            );
        }

        $this->audit->write(
            $tenantId,
            'performance_accounting.reversed',
            'performance_accounting_recognition',
            (string) $leaf->id,
            (int) $actor->id,
            [
                'source_reversal_operation_id' => $sourceReversalOperationId,
                'reversal_journal_entry_id' => $reversalJournalId,
            ],
            $now,
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function assertSourceCorrectionReplay(
        string $tenantId,
        object $acceptance,
        string $sourceReversalOperationId,
    ): void {
        $history = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $tenantId)
            ->where(
                'unit_handover_acceptance_id',
                $acceptance->id,
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($history->isEmpty()) {
            return;
        }

        if ($history->where('status', 'posted')->isNotEmpty()) {
            throw new AccountingRecognitionConflict(
                'Reversed Handover replay still has effective Performance Accounting.',
            );
        }

        $sourceReversed = $history
            ->where('reversal_operation_id', $sourceReversalOperationId)
            ->values();

        if ($sourceReversed->count() !== 1) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source-correction replay has inconsistent reversal identity.',
            );
        }

        $leaf = $sourceReversed->first();

        $reversal = DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('id', $leaf->reversal_journal_entry_id)
            ->first();

        if (
            $reversal === null
            || $reversal->status !== 'posted'
            || $reversal->origin !== 'reversal'
            || $reversal->source_id !== $leaf->journal_entry_id
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source-correction replay has inconsistent Journal reversal.',
            );
        }

        if (
            DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where(
                    'origin_recognition_type',
                    'PERFORMANCE_ACCOUNTING_RECOGNITION',
                )
                ->where('origin_recognition_id', $leaf->id)
                ->where('status', '<>', 'reversed')
                ->exists()
            || DB::table('accounting_position_consumptions')
                ->where('tenant_id', $tenantId)
                ->where(
                    'consuming_recognition_type',
                    'PERFORMANCE_ACCOUNTING_RECOGNITION',
                )
                ->where('consuming_recognition_id', $leaf->id)
                ->where('status', '<>', 'reversed')
                ->exists()
        ) {
            throw new AccountingRecognitionConflict(
                'Performance Accounting source-correction replay found effective provenance.',
            );
        }
    }

    private function lockPerformanceTransition(
        string $tenantId,
        string $contractId,
        string $acceptanceId,
    ): ?object {
        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->lockForUpdate()
            ->first();

        if ($position === null) {
            return null;
        }

        return DB::table('contract_consideration_transitions')
            ->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)
            ->where('source_type', 'UNIT_HANDOVER_ACCEPTANCE')
            ->where('source_id', $acceptanceId)
            ->lockForUpdate()
            ->first();
    }

    private function lockTransitionGraph(
        string $tenantId,
        string $transitionId,
    ): void {
        $lotIds = DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
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

        if ($lotIds !== []) {
            DB::table('contract_consideration_lots')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $lotIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        DB::table('contract_consideration_transition_lots')
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
