<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class PerformanceAccountingRecognitionTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_unbilled_handover_posts_contract_asset_and_revenue_with_exact_provenance(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context);

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        [$source, $graph] = DB::transaction(function () use (
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

        $operationId = (string) Str::ulid();

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => $operationId,
            ],
        );

        $recognition = DB::table('performance_accounting_recognitions')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('original', $recognition->recognition_kind);
        self::assertSame('posted', $recognition->status);
        self::assertSame($source['id'], $recognition->unit_handover_acceptance_id);
        self::assertSame($graph['transition_id'], $recognition->performance_consideration_transition_id);
        self::assertSame($operationId, $recognition->performance_accounting_operation_id);
        self::assertSame('1000.00', $recognition->performance_amount);
        self::assertSame('1000.00', $recognition->contract_asset_amount);
        self::assertSame('0.00', $recognition->contract_liability_release_amount);
        self::assertSame('1000.00', $recognition->revenue_amount);
        self::assertSame('2026-08-20', (string) $recognition->accounting_date);
        self::assertSame($accounts['contract_asset'], $recognition->contract_asset_account_id);
        self::assertSame($accounts['revenue'], $recognition->revenue_account_id);

        $journal = DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $recognition->journal_entry_id)
            ->first();

        self::assertNotNull($journal);
        self::assertSame('posted', $journal->status);
        self::assertSame('business', $journal->origin);
        self::assertSame('performance_accounting_recognition', $journal->source_type);
        self::assertSame($recognitionId, $journal->source_id);
        self::assertSame('2026-08-20', (string) $journal->entry_date);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $journal->id)
            ->orderBy('line_number')
            ->get();

        self::assertCount(2, $lines);
        self::assertSame($accounts['contract_asset'], $lines[0]->account_id);
        self::assertSame('1000.00', $lines[0]->debit);
        self::assertSame('0.00', $lines[0]->credit);
        self::assertSame($accounts['revenue'], $lines[1]->account_id);
        self::assertSame('0.00', $lines[1]->debit);
        self::assertSame('1000.00', $lines[1]->credit);

        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $context['tenant_id'])
            ->where('origin_recognition_type', 'PERFORMANCE_ACCOUNTING_RECOGNITION')
            ->where('origin_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($origin);
        self::assertSame('CONTRACT_ASSET', $origin->position_type);
        self::assertSame($accounts['contract_asset'], $origin->account_id);
        self::assertSame($graph['lot_id'], $origin->consideration_lot_id);
        self::assertSame('1000.00', $origin->origin_amount);
        self::assertSame(
            'PA:ORIGIN:'.$graph['transition_id'].':'.$graph['lot_id'],
            $origin->economic_leg_identity,
        );

        self::assertDatabaseHas(
            'accounting_position_origin_journal_line_allocations',
            [
                'origin_id' => $origin->id,
                'journal_entry_id' => $journal->id,
                'journal_line_id' => $lines[0]->id,
                'amount' => '1000.00',
                'economic_leg_identity' => $origin->economic_leg_identity,
            ],
        );

        self::assertSame(
            $recognitionId,
            app(RecognizePerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'unit_handover_acceptance_id' => $source['id'],
                    'performance_accounting_operation_id' => $operationId,
                ],
            ),
        );

        self::assertSame(
            1,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('unit_handover_acceptance_id', $source['id'])
                ->count(),
        );

        $this->expectException(AccountingRecognitionConflict::class);

        app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_billed_handover_releases_exact_historical_contract_liability_account(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-19',
        )[0];
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context, true);

        [$billingSource, $billingGraph] = DB::transaction(function () use (
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

        $this->createLiabilityOrigin(
            $context,
            $billingSource,
            $billingGraph,
            $accounts['historical_liability'],
            $accounts['contract_asset'],
        );

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        [$handoverSource, $handoverGraph] = DB::transaction(function () use (
            $context,
            $consideration,
            $billingGraph,
        ): array {
            $source = $this->handoverSource($context);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $billingGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$source, $graph];
        });

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $handoverSource['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('0.00', $recognition->contract_asset_amount);
        self::assertSame('1000.00', $recognition->contract_liability_release_amount);
        self::assertNull($recognition->contract_asset_account_id);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $recognition->journal_entry_id)
            ->orderBy('line_number')
            ->get();

        self::assertCount(2, $lines);
        self::assertSame($accounts['historical_liability'], $lines[0]->account_id);
        self::assertNotSame($accounts['contract_liability'], $lines[0]->account_id);
        self::assertSame('1000.00', $lines[0]->debit);
        self::assertSame('0.00', $lines[0]->credit);
        self::assertSame($accounts['revenue'], $lines[1]->account_id);
        self::assertSame('1000.00', $lines[1]->credit);

        $consumption = DB::table('accounting_position_consumptions')
            ->where('tenant_id', $context['tenant_id'])
            ->where(
                'consuming_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('consuming_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($consumption);
        self::assertSame($handoverGraph['transition_id'], $consumption->consideration_transition_id);
        self::assertSame($handoverGraph['lot_id'], $consumption->consideration_lot_id);
        self::assertSame('1000.00', $consumption->amount);

        $origin = DB::table('accounting_position_origins')
            ->where('id', $consumption->origin_id)
            ->first();

        self::assertNotNull($origin);
        self::assertSame($billingGraph['lot_id'], $origin->consideration_lot_id);
        self::assertSame($accounts['historical_liability'], $origin->account_id);

        self::assertDatabaseHas(
            'accounting_position_consumption_journal_line_allocations',
            [
                'consumption_id' => $consumption->id,
                'journal_entry_id' => $recognition->journal_entry_id,
                'journal_line_id' => $lines[0]->id,
                'amount' => '1000.00',
            ],
        );
    }

    public function test_closed_performance_period_fails_without_partial_accounting_truth(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context);

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        [$source] = DB::transaction(function () use (
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

        app(ManageAccountingPeriodAction::class)->close(
            $context['tenant_id'],
            $accounts['period'],
            $context['actor'],
        );

        try {
            app(RecognizePerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'unit_handover_acceptance_id' => $source['id'],
                    'performance_accounting_operation_id' => (string) Str::ulid(),
                ],
            );
            self::fail('Closed performance period unexpectedly accepted recognition.');
        } catch (AccountingRecognitionConflict) {
            self::assertSame(
                0,
                DB::table('performance_accounting_recognitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->count(),
            );
            self::assertSame(
                0,
                DB::table('journal_entries')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('source_type', 'performance_accounting_recognition')
                    ->count(),
            );
            self::assertSame(
                0,
                DB::table('accounting_position_origins')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where(
                        'origin_recognition_type',
                        'PERFORMANCE_ACCOUNTING_RECOGNITION',
                    )
                    ->count(),
            );
        }
    }

    private function accountingProtocol(
        array $context,
        bool $separateHistoricalLiability = false,
    ): array
    {
        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        $period = app(ManageAccountingPeriodAction::class)->create(
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
                'name' => 'Performance '.$code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );

        $contractAsset = $create(
            'PA-CA',
            'asset',
            'current_asset',
        );
        $contractLiability = $create(
            'PA-CL',
            'liability',
            'current_liability',
        );
        $historicalLiability = $separateHistoricalLiability
            ? $create(
                'PA-CL-HIST',
                'liability',
                'current_liability',
            )
            : $contractLiability;
        $revenue = $create(
            'PA-REV',
            'revenue',
            'operating_revenue',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $contractAsset,
            $historicalLiability,
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $revenue,
            $contractAsset,
            $contractLiability,
        );

        return [
            'period' => $period,
            'contract_asset' => $contractAsset,
            'contract_liability' => $contractLiability,
            'historical_liability' => $historicalLiability,
            'revenue' => $revenue,
        ];
    }
    private function createLiabilityOrigin(
        array $context,
        array $source,
        array $graph,
        string $liabilityAccountId,
        string $debitAccountId,
    ): string {
        $result = DB::transaction(
            fn () => app(BusinessPostingServiceInterface::class)->post(
                new BusinessPostingRequest(
                    $context['tenant_id'],
                    $context['actor']->id,
                    'receivable_recognition',
                    (string) Str::ulid(),
                    'SAR',
                    $source['economic_date'],
                    'Early billing Contract Liability provenance fixture',
                    [
                        new JournalLineData(
                            $debitAccountId,
                            $source['amount'],
                            '0.00',
                        ),
                        new JournalLineData(
                            $liabilityAccountId,
                            '0.00',
                            $source['amount'],
                        ),
                    ],
                ),
            ),
        );

        $liabilityLine = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $result->journalEntryId)
            ->where('account_id', $liabilityAccountId)
            ->first();

        if ($liabilityLine === null) {
            throw new \LogicException('Missing Contract Liability Journal line fixture.');
        }

        $originId = (string) Str::ulid();
        $recognitionId = (string) Str::ulid();
        $leg = 'AR:ORIGIN:'.$graph['transition_id'].':'.$graph['lot_id'];
        $now = now();

        DB::transaction(function () use (
            $context,
            $source,
            $graph,
            $liabilityAccountId,
            $result,
            $liabilityLine,
            $originId,
            $recognitionId,
            $leg,
            $now,
        ): void {
            DB::table('accounting_position_origins')->insert([
                'id' => $originId,
                'tenant_id' => $context['tenant_id'],
                'contract_id' => $context['contract_id'],
                'position_type' => 'CONTRACT_LIABILITY',
                'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
                'origin_recognition_id' => $recognitionId,
                'origin_journal_entry_id' => $result->journalEntryId,
                'account_id' => $liabilityAccountId,
                'economic_source_type' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
                'economic_source_id' => $source['id'],
                'consideration_transition_id' => $graph['transition_id'],
                'consideration_lot_id' => $graph['lot_id'],
                'economic_leg_identity' => $leg,
                'origin_amount' => $source['amount'],
                'currency' => 'SAR',
                'accounting_date' => $source['economic_date'],
                'status' => 'effective',
                'created_at' => $now,
                'reversal_origin_operation_id' => null,
                'reversed_at' => null,
            ]);

            DB::table(
                'accounting_position_origin_journal_line_allocations',
            )->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'contract_id' => $context['contract_id'],
                'origin_id' => $originId,
                'journal_entry_id' => $result->journalEntryId,
                'journal_line_id' => $liabilityLine->id,
                'amount' => $source['amount'],
                'currency' => 'SAR',
                'economic_leg_identity' => $leg,
                'created_at' => $now,
            ]);
        });

        return $originId;
    }

}
