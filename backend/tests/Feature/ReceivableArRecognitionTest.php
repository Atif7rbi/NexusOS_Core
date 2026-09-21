<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ReceivableArRecognitionTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_early_billing_posts_ar_and_contract_liability_with_exact_provenance(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context);

        [$source, $graph] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source, $graph];
        });

        $receivableId = app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $operationId = (string) Str::ulid();

        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contractual_billing_entitlement_id' => $source['id'],
                'receivable_ar_operation_id' => $operationId,
            ],
        );

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('posted', $recognition->status);
        self::assertSame($source['id'], $recognition->contractual_billing_entitlement_id);
        self::assertSame($receivableId, $recognition->receivable_id);
        self::assertSame($graph['transition_id'], $recognition->billing_consideration_transition_id);
        self::assertSame($operationId, $recognition->receivable_ar_operation_id);
        self::assertSame('1000.00', $recognition->receivable_amount);
        self::assertSame('0.00', $recognition->contract_asset_release_amount);
        self::assertSame('1000.00', $recognition->contract_liability_creation_amount);
        self::assertSame($accounts['ar'], $recognition->ar_control_account_id);
        self::assertSame($accounts['liability'], $recognition->contract_liability_account_id);
        self::assertSame('2026-08-21', (string) $recognition->accounting_date);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $recognition->journal_entry_id)
            ->orderBy('line_number')
            ->get();

        self::assertCount(2, $lines);
        self::assertSame($accounts['ar'], $lines[0]->account_id);
        self::assertSame('1000.00', $lines[0]->debit);
        self::assertSame('0.00', $lines[0]->credit);
        self::assertSame($accounts['liability'], $lines[1]->account_id);
        self::assertSame('0.00', $lines[1]->debit);
        self::assertSame('1000.00', $lines[1]->credit);

        $origin = DB::table('accounting_position_origins')
            ->where(
                'origin_recognition_type',
                'RECEIVABLE_AR_RECOGNITION',
            )
            ->where('origin_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($origin);
        self::assertSame('CONTRACT_LIABILITY', $origin->position_type);
        self::assertSame($graph['lot_id'], $origin->consideration_lot_id);
        self::assertSame($accounts['liability'], $origin->account_id);
        self::assertSame('1000.00', $origin->origin_amount);

        self::assertSame(
            $recognitionId,
            app(RecognizeReceivableAr::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'contractual_billing_entitlement_id' => $source['id'],
                    'receivable_ar_operation_id' => $operationId,
                ],
            ),
        );

        self::assertSame(
            1,
            DB::table('receivable_ar_recognitions')
                ->where('contractual_billing_entitlement_id', $source['id'])
                ->count(),
        );
    }

    public function test_billing_after_performance_releases_exact_historical_contract_asset(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context);

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        [$handover, $performanceGraph] = DB::transaction(function () use (
            $context,
            $consideration,
        ): array {
            $source = $this->handoverSource($context);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source, $graph];
        });

        $performanceRecognitionId =
            app(RecognizePerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'unit_handover_acceptance_id' => $handover['id'],
                    'performance_accounting_operation_id' =>
                        (string) Str::ulid(),
                ],
            );

        $performanceOrigin = DB::table('accounting_position_origins')
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('origin_recognition_id', $performanceRecognitionId)
            ->first();

        self::assertNotNull($performanceOrigin);

        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];

        [$billing, $billingGraph] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
            $performanceGraph,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $performanceGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$source, $graph];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $billing['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contractual_billing_entitlement_id' => $billing['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('1000.00', $recognition->contract_asset_release_amount);
        self::assertSame('0.00', $recognition->contract_liability_creation_amount);
        self::assertNull($recognition->contract_liability_account_id);

        $lines = DB::table('journal_lines')
            ->where('journal_entry_id', $recognition->journal_entry_id)
            ->orderBy('line_number')
            ->get();

        self::assertCount(2, $lines);
        self::assertSame($accounts['ar'], $lines[0]->account_id);
        self::assertSame('1000.00', $lines[0]->debit);
        self::assertSame($accounts['contract_asset'], $lines[1]->account_id);
        self::assertSame('1000.00', $lines[1]->credit);

        $consumption = DB::table('accounting_position_consumptions')
            ->where(
                'consuming_recognition_type',
                'RECEIVABLE_AR_RECOGNITION',
            )
            ->where('consuming_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($consumption);
        self::assertSame($performanceOrigin->id, $consumption->origin_id);
        self::assertSame($billingGraph['transition_id'], $consumption->consideration_transition_id);
        self::assertSame($billingGraph['lot_id'], $consumption->consideration_lot_id);
        self::assertSame('1000.00', $consumption->amount);
    }

    public function test_billing_source_correction_reverses_receivable_ar_before_source_truth(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);
        $this->accountingProtocol($context);

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contractual_billing_entitlement_id' => $source['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        $beforeCorrection = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($beforeCorrection);

        try {
            app(ReverseJournalAction::class)->execute(
                $context['tenant_id'],
                (string) $beforeCorrection->journal_entry_id,
                $context['actor'],
                (string) $beforeCorrection->accounting_date,
                'Generic reversal must be rejected',
            );
            self::fail(
                'Generic Journal reversal bypassed Receivable AR ownership.',
            );
        } catch (AccountingValidationFailed) {
            self::assertSame(
                'posted',
                DB::table('receivable_ar_recognitions')
                    ->where('id', $recognitionId)
                    ->value('status'),
            );
        }

        $entitlement = DB::table('contractual_billing_entitlements')
            ->where('id', $source['id'])
            ->first();

        self::assertNotNull($entitlement);

        $sourceCorrectionOperationId = (string) Str::ulid();
        $entitlementReversalOperationId = (string) Str::ulid();

        app(CorrectFinalizedContractualBillingSchedule::class)->execute(
            $context['tenant_id'],
            (string) $entitlement->schedule_id,
            $context['actor'],
            [
                'source_correction_operation_id' =>
                    $sourceCorrectionOperationId,
                'source_correction_reason' => 'Correct billing source',
                'source_correction_reference' =>
                    'AR/CORRECTION/'.$sourceCorrectionOperationId,
                'entitlement_reversals' => [
                    $source['id'] => $entitlementReversalOperationId,
                ],
            ],
        );

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('reversed', $recognition->status);
        self::assertSame(
            $entitlementReversalOperationId,
            $recognition->reversal_operation_id,
        );
        self::assertNotNull($recognition->reversal_journal_entry_id);

        self::assertDatabaseHas('journal_entries', [
            'id' => $recognition->reversal_journal_entry_id,
            'reverses_journal_entry_id' => $recognition->journal_entry_id,
            'status' => 'posted',
        ]);

        $recordedReversal = DB::table('journal_entries')
            ->where('id', $recognition->reversal_journal_entry_id)
            ->first();

        self::assertNotNull($recordedReversal);

        try {
            app(ReverseJournalAction::class)->execute(
                $context['tenant_id'],
                (string) $recordedReversal->id,
                $context['actor'],
                (string) $recordedReversal->entry_date,
                'Reversal-of-reversal must be rejected',
            );
            self::fail(
                'Generic Journal reversal reactivated reversed Receivable AR.',
            );
        } catch (AccountingValidationFailed) {
            self::assertSame(
                0,
                DB::table('journal_entries')
                    ->where(
                        'reverses_journal_entry_id',
                        $recordedReversal->id,
                    )
                    ->count(),
            );
        }

        self::assertDatabaseHas('accounting_position_origins', [
            'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
            'origin_recognition_id' => $recognitionId,
            'status' => 'reversed',
            'reversal_origin_operation_id' =>
                $entitlementReversalOperationId,
        ]);

        self::assertDatabaseHas('contractual_billing_entitlements', [
            'id' => $source['id'],
            'status' => 'reversed',
            'source_correction_operation_id' =>
                $sourceCorrectionOperationId,
        ]);
    }

    private function accountingProtocol(array $context): array
    {
        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $create = fn (
            string $code,
            string $type,
            string $classification,
        ): string => app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => 'Receivable AR '.$code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );

        $ar = $create('AR-CTRL', 'asset', 'current_asset');
        $contractAsset = $create('AR-CA', 'asset', 'current_asset');
        $liability = $create(
            'AR-CL',
            'liability',
            'current_liability',
        );
        $revenue = $create(
            'AR-REV',
            'revenue',
            'operating_revenue',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $ar,
        );

        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $contractAsset,
            $liability,
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $revenue,
            $contractAsset,
            $liability,
        );

        return [
            'ar' => $ar,
            'contract_asset' => $contractAsset,
            'liability' => $liability,
            'revenue' => $revenue,
        ];
    }
}
