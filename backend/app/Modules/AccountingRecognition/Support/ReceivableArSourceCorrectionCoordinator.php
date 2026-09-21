<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Support;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReceivableArSourceCorrectionCoordinator
{
    public function __construct(
        private readonly ReceivableArJournalWriter $journals,
        private readonly AccountingAuditWriter $audit,
    ) {}

    /**
     * @param  array<string,string>  $entitlementReversals
     */
    public function reverseLockedSet(
        string $tenantId,
        array $entitlementReversals,
        User $actor,
        string $reason,
        CarbonImmutable $at,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Receivable AR source correction requires a caller-owned transaction.',
            );
        }

        if ($entitlementReversals === []) {
            return;
        }

        $entitlementIds = array_keys($entitlementReversals);
        sort($entitlementIds, SORT_STRING);

        $recognitions = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->whereIn('contractual_billing_entitlement_id', $entitlementIds)
            ->orderBy('contractual_billing_entitlement_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($recognitions as $recognition) {
            $entitlementId = (string) $recognition
                ->contractual_billing_entitlement_id;
            $reversalOperationId = $entitlementReversals[$entitlementId]
                ?? null;

            if ($reversalOperationId === null) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction found Recognition outside the authoritative Entitlement reversal set.',
                );
            }

            if ($recognition->status === 'reversed') {
                if (
                    $recognition->reversal_operation_id
                        !== $reversalOperationId
                    || $recognition->reversal_journal_entry_id === null
                ) {
                    throw new AccountingRecognitionConflict(
                        'Receivable AR source correction replay found different historical reversal truth.',
                    );
                }

                continue;
            }

            if ($recognition->status !== 'posted') {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction found unsupported Recognition state.',
                );
            }

            $journal = DB::table('journal_entries')
                ->where('tenant_id', $tenantId)
                ->where('id', $recognition->journal_entry_id)
                ->lockForUpdate()
                ->first();

            if (
                $journal === null
                || $journal->status !== 'posted'
                || $journal->origin !== 'business'
                || $journal->source_type !== 'receivable_ar_recognition'
                || $journal->source_id !== $recognition->id
            ) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction found inconsistent owned Journal.',
                );
            }

            $origins = DB::table('accounting_position_origins')
                ->where('tenant_id', $tenantId)
                ->where(
                    'origin_recognition_type',
                    'RECEIVABLE_AR_RECOGNITION',
                )
                ->where('origin_recognition_id', $recognition->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($origins as $origin) {
                $dependent = DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $tenantId)
                    ->where('origin_id', $origin->id)
                    ->where('status', 'effective')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($dependent->isNotEmpty()) {
                    throw new AccountingRecognitionConflict(
                        'Receivable AR source correction cannot reverse Contract Liability provenance while dependent accounting consumption remains effective.',
                    );
                }
            }

            $consumptions = DB::table('accounting_position_consumptions')
                ->where('tenant_id', $tenantId)
                ->where(
                    'consuming_recognition_type',
                    'RECEIVABLE_AR_RECOGNITION',
                )
                ->where('consuming_recognition_id', $recognition->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $journalLines = DB::table('journal_lines')
                ->where('tenant_id', $tenantId)
                ->where('journal_entry_id', $journal->id)
                ->orderBy('line_number')
                ->lockForUpdate()
                ->get();

            if ($journalLines->count() < 2) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction found incomplete Journal lines.',
                );
            }

            DB::table('accounting_settings')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first()
                ?? throw new AccountingRecognitionConflict(
                    'Accounting settings are missing for Receivable AR source correction.',
                );

            $periods = DB::table('accounting_periods')
                ->where('tenant_id', $tenantId)
                ->whereDate(
                    'start_date',
                    '<=',
                    $recognition->accounting_date,
                )
                ->whereDate(
                    'end_date',
                    '>=',
                    $recognition->accounting_date,
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $periods->count() !== 1
                || $periods->first()->status !== 'open'
            ) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction requires the original Accounting Period to remain open.',
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
                    'Receivable AR source correction is missing an exact historical Account.',
                );
            }

            $reversalJournalId = $this->journals->reverseExact(
                $tenantId,
                $actor,
                $journal,
                $reason,
            );

            foreach ($consumptions as $consumption) {
                if ($consumption->status !== 'effective') {
                    throw new AccountingRecognitionConflict(
                        'Receivable AR source correction found already-reversed Contract Asset consumption.',
                    );
                }

                DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $consumption->id)
                    ->update([
                        'status' => 'reversed',
                        'reversal_operation_id' => $reversalOperationId,
                        'reversed_at' => $at,
                    ]);
            }

            foreach ($origins as $origin) {
                if ($origin->status !== 'effective') {
                    throw new AccountingRecognitionConflict(
                        'Receivable AR source correction found already-reversed Contract Liability origin.',
                    );
                }

                DB::table('accounting_position_origins')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $origin->id)
                    ->update([
                        'status' => 'reversed',
                        'reversal_origin_operation_id' => $reversalOperationId,
                        'reversed_at' => $at,
                    ]);
            }

            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $tenantId)
                ->where('id', $recognition->id)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $reversalOperationId,
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reversed_by' => $actor->id,
                    'reversed_at' => $at,
                ]);

            $this->audit->write(
                $tenantId,
                'receivable_ar.reversed',
                'receivable_ar_recognition',
                (string) $recognition->id,
                (int) $actor->id,
                [
                    'contractual_billing_entitlement_id' => $entitlementId,
                    'reversal_operation_id' => $reversalOperationId,
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reason' => $reason,
                ],
                $at,
            );
        }

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /**
     * @param  array<string,string>  $entitlementReversals
     */
    public function assertReplay(
        string $tenantId,
        array $entitlementReversals,
    ): void {
        if ($entitlementReversals === []) {
            return;
        }

        $recognitions = DB::table('receivable_ar_recognitions')
            ->where('tenant_id', $tenantId)
            ->whereIn(
                'contractual_billing_entitlement_id',
                array_keys($entitlementReversals),
            )
            ->orderBy('contractual_billing_entitlement_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($recognitions as $recognition) {
            $operationId = $entitlementReversals[
                (string) $recognition->contractual_billing_entitlement_id
            ] ?? null;

            if (
                $operationId === null
                || $recognition->status !== 'reversed'
                || $recognition->reversal_operation_id !== $operationId
                || $recognition->reversal_journal_entry_id === null
            ) {
                throw new AccountingRecognitionConflict(
                    'Receivable AR source correction replay is inconsistent.',
                );
            }
        }
    }
}
