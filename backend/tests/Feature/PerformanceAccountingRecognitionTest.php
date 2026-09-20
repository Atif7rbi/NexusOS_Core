<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\CorrectPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Support\PerformanceAccountingJournalWriter;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
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

        $this->commitDeferredState();

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

        $this->commitDeferredState();

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

    public function test_mixed_handover_groups_exact_liability_accounts_and_creates_contract_asset(): void
    {
        $context = $this->considerationContext();
        $obligationIds = array_slice(
            $this->billingObligations(
                $context,
                ['300.00', '200.00', '500.00'],
                '2026-08-19',
            ),
            0,
            2,
        );
        $consideration = $this->adopt($context);
        $accounts = $this->accountingProtocol($context);

        $secondLiability = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-CL-2',
                'name' => 'Performance Historical Liability 2',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'liability',
                'classification' => 'current_liability',
                'parent_id' => null,
            ],
        );

        $billing = [];

        foreach ($obligationIds as $obligationId) {
            $source = $this->billingSource($context, $obligationId);
            $billing[] = ['source' => $source];
        }

        usort(
            $billing,
            static fn (array $left, array $right): int => strcmp($left['source']['id'], $right['source']['id']),
        );

        DB::transaction(function () use (
            $context,
            $consideration,
            &$billing,
        ): void {
            foreach ($billing as $index => &$entry) {
                $entry['graph'] = $this->transition(
                    $context,
                    $consideration,
                    $entry['source'],
                    $consideration['genesis_lot_id'],
                    'BILLED_UNEARNED',
                );
            }
        });

        $this->createLiabilityOrigin(
            $context,
            $billing[0]['source'],
            $billing[0]['graph'],
            $accounts['contract_liability'],
            $accounts['contract_asset'],
        );
        $this->createLiabilityOrigin(
            $context,
            $billing[1]['source'],
            $billing[1]['graph'],
            $secondLiability,
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
        $this->commitDeferredState();

        $handover = $this->handoverSource($context);

        $performanceGraph = DB::transaction(
            fn (): array => $this->mixedPerformanceTransition(
                $context,
                $consideration,
                $handover,
                $consideration['genesis_lot_id'],
                [
                    [
                        'lot_id' => $billing[0]['graph']['lot_id'],
                        'amount' => $billing[0]['source']['amount'],
                    ],
                    [
                        'lot_id' => $billing[1]['graph']['lot_id'],
                        'amount' => $billing[1]['source']['amount'],
                    ],
                ],
                '500.00',
            ),
        );

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $handover['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('500.00', $recognition->contract_asset_amount);
        self::assertSame('500.00', $recognition->contract_liability_release_amount);
        self::assertSame('1000.00', $recognition->revenue_amount);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $recognition->journal_entry_id)
            ->get()
            ->keyBy('account_id');

        self::assertCount(4, $lines);
        self::assertSame(
            $billing[0]['source']['amount'],
            $lines->get($accounts['contract_liability'])->debit,
        );
        self::assertSame(
            $billing[1]['source']['amount'],
            $lines->get($secondLiability)->debit,
        );
        self::assertSame(
            '500.00',
            $lines->get($accounts['contract_asset'])->debit,
        );
        self::assertSame(
            '1000.00',
            $lines->get($accounts['revenue'])->credit,
        );

        $consumptions = DB::table('accounting_position_consumptions')
            ->where('tenant_id', $context['tenant_id'])
            ->where(
                'consuming_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('consuming_recognition_id', $recognitionId)
            ->orderBy('origin_id')
            ->get();

        self::assertCount(2, $consumptions);
        self::assertSame(
            '500.00',
            $consumptions
                ->reduce(
                    static fn (string $sum, object $row): string => (string) BigDecimal::of($sum)->plus(
                        BigDecimal::of((string) $row->amount),
                    ),
                    '0.00',
                ),
        );

        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $context['tenant_id'])
            ->where(
                'origin_recognition_type',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
            )
            ->where('origin_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($origin);
        self::assertSame('500.00', $origin->origin_amount);
        self::assertSame($performanceGraph['earned_lot_id'], $origin->consideration_lot_id);
    }

    public function test_performance_owned_journal_cannot_be_reversed_outside_owner_workflow(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $this->accountingProtocol($context);

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );
        $this->commitDeferredState();

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
        ): array {
            $source = $this->handoverSource($context);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source];
        });

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);

        try {
            app(ReverseJournalAction::class)->execute(
                $context['tenant_id'],
                (string) $recognition->journal_entry_id,
                $context['actor'],
                (string) $recognition->accounting_date,
                'Generic reversal must be rejected',
            );
            self::fail(
                'Generic Journal reversal bypassed Performance Accounting ownership.',
            );
        } catch (AccountingValidationFailed) {
            self::assertSame(
                0,
                DB::table('journal_entries')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where(
                        'reverses_journal_entry_id',
                        $recognition->journal_entry_id,
                    )
                    ->count(),
            );
        }

        $target = DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $recognition->journal_entry_id)
            ->first();

        self::assertNotNull($target);

        DB::beginTransaction();

        try {
            app(PerformanceAccountingJournalWriter::class)->reverseExact(
                $context['tenant_id'],
                $context['actor'],
                $target,
                'Owner-state bypass must fail at final state',
            );

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            self::fail(
                'PostgreSQL accepted a Performance Journal reversal without owner-state reversal.',
            );
        } catch (QueryException $exception) {
            self::assertSame(
                '23514',
                (string) ($exception->errorInfo[0] ?? ''),
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }

        self::assertDatabaseHas('performance_accounting_recognitions', [
            'id' => $recognitionId,
            'status' => 'posted',
        ]);
        self::assertSame(
            0,
            DB::table('journal_entries')
                ->where('tenant_id', $context['tenant_id'])
                ->where(
                    'reverses_journal_entry_id',
                    $recognition->journal_entry_id,
                )
                ->count(),
        );
    }

    public function test_handover_source_correction_reverses_effective_performance_accounting_before_economic_source(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $this->accountingProtocol($context);

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );
        $this->commitDeferredState();

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

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $before = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($before);

        $reversalOperation = (string) Str::ulid();
        $input = [
            'reversal_operation_id' => $reversalOperation,
            'reversal_reason' => 'Customer acceptance rescinded',
            'reversal_reference' => 'PA-SOURCE-REV-001',
        ];

        self::assertSame(
            $source['id'],
            app(ReverseUnitHandoverPerformanceSource::class)->execute(
                $context['tenant_id'],
                $source['id'],
                $context['actor'],
                $input,
            ),
        );

        $recognition = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertSame('reversed', $recognition->status);
        self::assertSame($reversalOperation, $recognition->reversal_operation_id);
        self::assertNotNull($recognition->reversal_journal_entry_id);

        $reversalJournal = DB::table('journal_entries')
            ->where('id', $recognition->reversal_journal_entry_id)
            ->first();

        self::assertNotNull($reversalJournal);
        self::assertSame('posted', $reversalJournal->status);
        self::assertSame('reversal', $reversalJournal->origin);
        self::assertSame($before->journal_entry_id, $reversalJournal->source_id);
        self::assertSame('2026-08-20', (string) $reversalJournal->entry_date);

        $this->assertRecordedPerformanceReversalIsProtected(
            $context,
            (string) $recognition->reversal_journal_entry_id,
        );

        self::assertDatabaseHas('accounting_position_origins', [
            'origin_recognition_id' => $recognitionId,
            'status' => 'reversed',
            'reversal_origin_operation_id' => $reversalOperation,
        ]);

        self::assertDatabaseHas('unit_handover_acceptances', [
            'id' => $source['id'],
            'status' => 'reversed',
            'reversal_operation_id' => $reversalOperation,
        ]);

        self::assertDatabaseHas('contract_consideration_transitions', [
            'id' => $graph['transition_id'],
            'status' => 'reversed',
        ]);

        self::assertSame(
            $source['id'],
            app(ReverseUnitHandoverPerformanceSource::class)->execute(
                $context['tenant_id'],
                $source['id'],
                $context['actor'],
                $input,
            ),
        );
    }

    public function test_accounting_only_correction_creates_linear_successor_and_preserves_economic_origin(): void
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
        $this->commitDeferredState();

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

        $originalOperation = (string) Str::ulid();

        $originalId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => $originalOperation,
            ],
        );

        $originalOrigin = DB::table('accounting_position_origins')
            ->where('tenant_id', $context['tenant_id'])
            ->where('origin_recognition_id', $originalId)
            ->first();

        self::assertNotNull($originalOrigin);

        $correctedAsset = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-CA-CORR',
                'name' => 'Corrected Performance Contract Asset',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'asset',
                'classification' => 'current_asset',
                'parent_id' => null,
            ],
        );

        $correctedRevenue = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-REV-CORR',
                'name' => 'Corrected Performance Revenue',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'revenue',
                'classification' => 'operating_revenue',
                'parent_id' => null,
            ],
        );

        $policy = app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-08-01',
            $correctedRevenue,
            $correctedAsset,
            $accounts['contract_liability'],
        );

        self::assertSame(2, $policy['policy_version']);

        $correctionOperation = (string) Str::ulid();
        $input = [
            'unit_handover_acceptance_id' => $source['id'],
            'performance_accounting_correction_operation_id' => $correctionOperation,
            'correction_reason' => 'Correct Performance Accounting mapping',
            'correction_reference' => 'PA-CORR-001',
        ];

        $successorId = app(CorrectPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            $input,
        );

        $original = DB::table('performance_accounting_recognitions')
            ->where('id', $originalId)
            ->first();
        $successor = DB::table('performance_accounting_recognitions')
            ->where('id', $successorId)
            ->first();

        self::assertNotNull($original);
        self::assertNotNull($successor);
        self::assertSame('reversed', $original->status);
        self::assertSame($correctionOperation, $original->reversal_operation_id);
        self::assertNotNull($original->reversal_journal_entry_id);

        $this->assertRecordedPerformanceReversalIsProtected(
            $context,
            (string) $original->reversal_journal_entry_id,
        );

        self::assertSame('accounting_correction', $successor->recognition_kind);
        self::assertSame('posted', $successor->status);
        self::assertSame($originalId, $successor->root_recognition_id);
        self::assertSame($originalId, $successor->predecessor_recognition_id);
        self::assertSame(
            $correctionOperation,
            $successor->performance_accounting_correction_operation_id,
        );
        self::assertSame($source['id'], $successor->unit_handover_acceptance_id);
        self::assertSame(
            $graph['transition_id'],
            $successor->performance_consideration_transition_id,
        );
        self::assertSame($original->performance_amount, $successor->performance_amount);
        self::assertSame($original->accounting_date, $successor->accounting_date);
        self::assertSame($correctedAsset, $successor->contract_asset_account_id);
        self::assertSame($correctedRevenue, $successor->revenue_account_id);
        self::assertSame(2, (int) $successor->performance_accounting_policy_version);

        $successorOrigin = DB::table('accounting_position_origins')
            ->where('tenant_id', $context['tenant_id'])
            ->where('origin_recognition_id', $successorId)
            ->first();

        self::assertNotNull($successorOrigin);
        self::assertSame(
            'reversed',
            (string) DB::table('accounting_position_origins')
                ->where('id', $originalOrigin->id)
                ->value('status'),
        );
        self::assertSame($originalOrigin->consideration_lot_id, $successorOrigin->consideration_lot_id);
        self::assertSame($originalOrigin->economic_leg_identity, $successorOrigin->economic_leg_identity);
        self::assertSame($originalOrigin->origin_amount, $successorOrigin->origin_amount);
        self::assertSame($correctedAsset, $successorOrigin->account_id);

        self::assertSame(
            $successorId,
            app(CorrectPerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                $input,
            ),
        );

        self::assertSame(
            $originalId,
            app(RecognizePerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'unit_handover_acceptance_id' => $source['id'],
                    'performance_accounting_operation_id' => $originalOperation,
                ],
            ),
        );

        try {
            app(CorrectPerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                array_merge(
                    $input,
                    ['correction_reason' => 'Different reason'],
                ),
            );
            self::fail('Correction replay accepted different canonical facts.');
        } catch (AccountingRecognitionConflict) {
            self::assertSame(
                2,
                DB::table('performance_accounting_recognitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('root_recognition_id', $originalId)
                    ->count(),
            );
        }
    }

    public function test_correction_operation_reuse_across_roots_conflicts_without_mutating_second_root(): void
    {
        $first = $this->considerationContext();
        $firstConsideration = $this->adopt($first);
        $accounts = $this->accountingProtocol($first);

        app(AdoptPerformanceAccounting::class)->execute(
            $first['tenant_id'],
            $first['actor'],
            [
                'contract_id' => $first['contract_id'],
                'performance_accounting_adoption_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $second = $this->sameTenantPerformanceContext($first);
        $secondConsideration = $this->adopt($second);

        app(AdoptPerformanceAccounting::class)->execute(
            $second['tenant_id'],
            $second['actor'],
            [
                'contract_id' => $second['contract_id'],
                'performance_accounting_adoption_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $this->commitDeferredState();

        [$firstSource] = DB::transaction(function () use (
            $first,
            $firstConsideration,
        ): array {
            $source = $this->handoverSource($first);
            $this->transition(
                $first,
                $firstConsideration,
                $source,
                $firstConsideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source];
        });

        [$secondSource] = DB::transaction(function () use (
            $second,
            $secondConsideration,
        ): array {
            $source = $this->handoverSource($second);
            $this->transition(
                $second,
                $secondConsideration,
                $source,
                $secondConsideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source];
        });

        $firstRoot = app(RecognizePerformanceAccounting::class)->execute(
            $first['tenant_id'],
            $first['actor'],
            [
                'unit_handover_acceptance_id' => $firstSource['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $secondRoot = app(RecognizePerformanceAccounting::class)->execute(
            $second['tenant_id'],
            $second['actor'],
            [
                'unit_handover_acceptance_id' => $secondSource['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $correctedAsset = app(ManageAccountAction::class)->create(
            $first['tenant_id'],
            $first['actor'],
            [
                'code' => 'PA-XROOT-A',
                'name' => 'Cross-root corrected Contract Asset',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'asset',
                'classification' => 'current_asset',
                'parent_id' => null,
            ],
        );

        $correctedRevenue = app(ManageAccountAction::class)->create(
            $first['tenant_id'],
            $first['actor'],
            [
                'code' => 'PA-XROOT-R',
                'name' => 'Cross-root corrected Revenue',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'revenue',
                'classification' => 'operating_revenue',
                'parent_id' => null,
            ],
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $first['tenant_id'],
            $first['actor'],
            '2026-08-01',
            $correctedRevenue,
            $correctedAsset,
            $accounts['contract_liability'],
        );

        $operationId = (string) Str::ulid();
        $reason = 'Correct shared-tenant accounting mapping';
        $reference = 'PA-XROOT-CORR-001';

        $firstSuccessor = app(CorrectPerformanceAccounting::class)->execute(
            $first['tenant_id'],
            $first['actor'],
            [
                'unit_handover_acceptance_id' => $firstSource['id'],
                'performance_accounting_correction_operation_id' =>
                    $operationId,
                'correction_reason' => $reason,
                'correction_reference' => $reference,
            ],
        );

        $secondBefore = DB::table('performance_accounting_recognitions')
            ->where('id', $secondRoot)
            ->first();

        self::assertNotNull($secondBefore);
        self::assertSame('posted', $secondBefore->status);
        self::assertNull($secondBefore->reversal_operation_id);

        try {
            app(CorrectPerformanceAccounting::class)->execute(
                $second['tenant_id'],
                $second['actor'],
                [
                    'unit_handover_acceptance_id' => $secondSource['id'],
                    'performance_accounting_correction_operation_id' =>
                        $operationId,
                    'correction_reason' => $reason,
                    'correction_reference' => $reference,
                ],
            );
            self::fail(
                'Tenant-wide correction operation was reused on another root.',
            );
        } catch (AccountingRecognitionConflict) {
            self::assertSame(
                1,
                DB::table('performance_accounting_recognitions')
                    ->where('tenant_id', $second['tenant_id'])
                    ->where('root_recognition_id', $secondRoot)
                    ->count(),
            );

            $secondAfter = DB::table('performance_accounting_recognitions')
                ->where('id', $secondRoot)
                ->first();

            self::assertNotNull($secondAfter);
            self::assertSame('posted', $secondAfter->status);
            self::assertNull($secondAfter->reversal_operation_id);
            self::assertNull($secondAfter->reversal_journal_entry_id);

            self::assertSame(
                1,
                DB::table('journal_entries')
                    ->where('tenant_id', $second['tenant_id'])
                    ->where('source_type', 'performance_accounting_recognition')
                    ->where('source_id', $secondRoot)
                    ->count(),
            );

            self::assertDatabaseHas('performance_accounting_recognitions', [
                'id' => $firstSuccessor,
                'root_recognition_id' => $firstRoot,
                'performance_accounting_correction_operation_id' =>
                    $operationId,
            ]);
        }
    }

    public function test_fully_billed_unchanged_policy_correction_is_atomic_no_op_conflict(): void
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
                'performance_accounting_adoption_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $this->commitDeferredState();

        [$handoverSource] = DB::transaction(function () use (
            $context,
            $consideration,
            $billingGraph,
        ): array {
            $source = $this->handoverSource($context);
            $this->transition(
                $context,
                $consideration,
                $source,
                $billingGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$source];
        });

        $recognitionId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $handoverSource['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $before = DB::table('performance_accounting_recognitions')
            ->where('id', $recognitionId)
            ->first();

        self::assertNotNull($before);
        self::assertSame('0.00', $before->contract_asset_amount);
        self::assertNull($before->contract_asset_account_id);

        try {
            app(CorrectPerformanceAccounting::class)->execute(
                $context['tenant_id'],
                $context['actor'],
                [
                    'unit_handover_acceptance_id' => $handoverSource['id'],
                    'performance_accounting_correction_operation_id' =>
                        (string) Str::ulid(),
                    'correction_reason' => 'No actual mapping change',
                    'correction_reference' => 'PA-NOOP-FULLY-BILLED',
                ],
            );
            self::fail(
                'Fully billed Recognition accepted an unchanged immutable policy correction.',
            );
        } catch (AccountingRecognitionConflict) {
            self::assertSame(
                1,
                DB::table('performance_accounting_recognitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('root_recognition_id', $recognitionId)
                    ->count(),
            );
            self::assertSame(
                1,
                DB::table('journal_entries')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('source_type', 'performance_accounting_recognition')
                    ->where('source_id', $recognitionId)
                    ->count(),
            );

            $after = DB::table('performance_accounting_recognitions')
                ->where('id', $recognitionId)
                ->first();

            self::assertNotNull($after);
            self::assertSame('posted', $after->status);
            self::assertNull($after->reversal_operation_id);
            self::assertNull($after->reversal_journal_entry_id);

            self::assertSame(
                1,
                DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where(
                        'consuming_recognition_type',
                        'PERFORMANCE_ACCOUNTING_RECOGNITION',
                    )
                    ->where('consuming_recognition_id', $recognitionId)
                    ->where('status', 'effective')
                    ->count(),
            );
        }
    }

    public function test_source_correction_after_accounting_correction_targets_only_effective_leaf(): void
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
        $this->commitDeferredState();

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
        ): array {
            $source = $this->handoverSource($context);
            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source];
        });

        $rootId = app(RecognizePerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_operation_id' => (string) Str::ulid(),
            ],
        );

        $correctedAsset = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-LEAF-A',
                'name' => 'Leaf corrected Contract Asset',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'asset',
                'classification' => 'current_asset',
                'parent_id' => null,
            ],
        );

        $correctedRevenue = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-LEAF-R',
                'name' => 'Leaf corrected Revenue',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'revenue',
                'classification' => 'operating_revenue',
                'parent_id' => null,
            ],
        );

        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-08-01',
            $correctedRevenue,
            $correctedAsset,
            $accounts['contract_liability'],
        );

        $correctionOperation = (string) Str::ulid();

        $leafId = app(CorrectPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'unit_handover_acceptance_id' => $source['id'],
                'performance_accounting_correction_operation_id' => $correctionOperation,
                'correction_reason' => 'Correct mapping before source reversal',
                'correction_reference' => 'PA-LEAF-CORR-001',
            ],
        );

        $rootBeforeSourceCorrection = DB::table(
            'performance_accounting_recognitions',
        )
            ->where('id', $rootId)
            ->first();

        self::assertNotNull($rootBeforeSourceCorrection);
        self::assertSame('reversed', $rootBeforeSourceCorrection->status);
        self::assertSame(
            $correctionOperation,
            $rootBeforeSourceCorrection->reversal_operation_id,
        );
        self::assertNotNull(
            $rootBeforeSourceCorrection->reversal_journal_entry_id,
        );

        $sourceReversalOperation = (string) Str::ulid();
        $sourceInput = [
            'reversal_operation_id' => $sourceReversalOperation,
            'reversal_reason' => 'Economic source corrected after accounting mapping correction',
            'reversal_reference' => 'PA-LEAF-SOURCE-REV-001',
        ];

        self::assertSame(
            $source['id'],
            app(ReverseUnitHandoverPerformanceSource::class)->execute(
                $context['tenant_id'],
                $source['id'],
                $context['actor'],
                $sourceInput,
            ),
        );

        $root = DB::table('performance_accounting_recognitions')
            ->where('id', $rootId)
            ->first();
        $leaf = DB::table('performance_accounting_recognitions')
            ->where('id', $leafId)
            ->first();

        self::assertNotNull($root);
        self::assertNotNull($leaf);
        self::assertSame('reversed', $root->status);
        self::assertSame($correctionOperation, $root->reversal_operation_id);
        self::assertSame(
            $rootBeforeSourceCorrection->reversal_journal_entry_id,
            $root->reversal_journal_entry_id,
        );

        self::assertSame('reversed', $leaf->status);
        self::assertSame(
            $sourceReversalOperation,
            $leaf->reversal_operation_id,
        );
        self::assertNotNull($leaf->reversal_journal_entry_id);

        self::assertDatabaseHas('accounting_position_origins', [
            'origin_recognition_id' => $leafId,
            'status' => 'reversed',
            'reversal_origin_operation_id' => $sourceReversalOperation,
        ]);

        self::assertSame(
            0,
            DB::table('performance_accounting_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('root_recognition_id', $rootId)
                ->where('status', 'posted')
                ->count(),
        );

        self::assertSame(
            $source['id'],
            app(ReverseUnitHandoverPerformanceSource::class)->execute(
                $context['tenant_id'],
                $source['id'],
                $context['actor'],
                $sourceInput,
            ),
        );

        $rootAfterReplay = DB::table('performance_accounting_recognitions')
            ->where('id', $rootId)
            ->first();

        self::assertNotNull($rootAfterReplay);
        self::assertSame(
            $correctionOperation,
            $rootAfterReplay->reversal_operation_id,
        );
        self::assertSame(
            $rootBeforeSourceCorrection->reversal_journal_entry_id,
            $rootAfterReplay->reversal_journal_entry_id,
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

        $this->commitDeferredState();

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

    private function assertRecordedPerformanceReversalIsProtected(
        array $context,
        string $reversalJournalId,
    ): void {
        $target = DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $reversalJournalId)
            ->first();

        self::assertNotNull($target);
        self::assertSame('posted', $target->status);
        self::assertSame('reversal', $target->origin);

        try {
            app(ReverseJournalAction::class)->execute(
                $context['tenant_id'],
                $reversalJournalId,
                $context['actor'],
                (string) $target->entry_date,
                'Recorded Performance reversal must not be reversible',
            );
            self::fail(
                'Generic reversal reactivated a reversed Performance Accounting effect.',
            );
        } catch (AccountingValidationFailed) {
            self::assertSame(
                0,
                DB::table('journal_entries')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('reverses_journal_entry_id', $reversalJournalId)
                    ->count(),
            );
        }

        DB::beginTransaction();

        try {
            $now = now();

            DB::table('journal_entries')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'entry_date' => $target->entry_date,
                'description' => 'Direct SQL reversal-of-reversal probe',
                'status' => 'draft',
                'origin' => 'reversal',
                'source_type' => 'journal_entry',
                'source_id' => $reversalJournalId,
                'created_by' => $context['actor']->id,
                'updated_by' => $context['actor']->id,
                'created_at' => $now,
                'updated_at' => $now,
                'reverses_journal_entry_id' => $reversalJournalId,
                'reversal_reason' =>
                    'Direct SQL reversal-of-reversal must fail',
            ]);

            DB::statement(
                'SET CONSTRAINTS performance_accounting_journal_final IMMEDIATE',
            );

            self::fail(
                'PostgreSQL accepted reversal of a recorded Performance reversal Journal.',
            );
        } catch (QueryException $exception) {
            self::assertSame(
                '23514',
                (string) ($exception->errorInfo[0] ?? ''),
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'recorded reversal Journal cannot itself be reversed',
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }

        self::assertSame(
            0,
            DB::table('journal_entries')
                ->where('tenant_id', $context['tenant_id'])
                ->where('reverses_journal_entry_id', $reversalJournalId)
                ->count(),
        );
    }

    private function sameTenantPerformanceContext(array $context): array
    {
        $project = $this->createIntegrityProject(
            $context['tenant_id'],
            $context['actor']->id,
        );
        $unit = $this->createIntegrityUnit(
            $context['tenant_id'],
            (string) $project->id,
            $context['actor']->id,
            'sold',
        );
        $customer = $this->createIntegrityCustomer(
            $context['tenant_id'],
            $context['actor']->id,
        );
        $reservation = $this->createIntegrityReservation(
            $context['tenant_id'],
            (string) $unit->id,
            (string) $customer->id,
            $context['actor']->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $context['tenant_id'],
            (string) $reservation->id,
            $context['actor']->id,
            'active',
            ['total_amount' => '1000.00'],
        );

        return [
            'tenant_id' => $context['tenant_id'],
            'actor' => $context['actor'],
            'contract_id' => (string) $contract->id,
            'customer_id' => (string) $customer->id,
            'reservation_id' => (string) $reservation->id,
            'unit_id' => (string) $unit->id,
            'operation_id' => (string) Str::ulid(),
        ];
    }

    private function commitDeferredState(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function accountingProtocol(
        array $context,
        bool $separateHistoricalLiability = false,
    ): array {
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

    private function mixedPerformanceTransition(
        array $context,
        array $consideration,
        array $source,
        string $unbilledLotId,
        array $billedLots,
        string $unbilledAmount,
    ): array {
        $transitionId = (string) Str::ulid();
        $earnedLotId = (string) Str::ulid();
        $billedEarnedLotId = (string) Str::ulid();
        $now = now();

        $owner = [
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'position_id' => $consideration['position_id'],
        ];

        DB::table('contract_consideration_transitions')->insert($owner + [
            'id' => $transitionId,
            'transition_operation_id' => (string) Str::ulid(),
            'source_type' => 'UNIT_HANDOVER_ACCEPTANCE',
            'source_id' => $source['id'],
            'economic_date' => $source['economic_date'],
            'semantic_precedence' => 10,
            'transition_amount' => '1000.00',
            'currency' => 'SAR',
            'status' => 'effective',
            'created_by' => $context['actor']->id,
            'created_at' => $now,
        ]);

        DB::table('contract_consideration_lots')->insert([
            $owner + [
                'id' => $earnedLotId,
                'transition_id' => $transitionId,
                'lot_kind' => 'TRANSITION_OUTPUT',
                'semantic_position' => 'EARNED_UNBILLED',
                'amount' => $unbilledAmount,
                'currency' => 'SAR',
                'created_at' => $now,
            ],
            $owner + [
                'id' => $billedEarnedLotId,
                'transition_id' => $transitionId,
                'lot_kind' => 'TRANSITION_OUTPUT',
                'semantic_position' => 'BILLED_EARNED',
                'amount' => '500.00',
                'currency' => 'SAR',
                'created_at' => $now,
            ],
        ]);

        $edges = [[
            'id' => (string) Str::ulid(),
            'lot_id' => $unbilledLotId,
            'successor_lot_id' => $earnedLotId,
            'consumed_amount' => $unbilledAmount,
        ]];

        foreach ($billedLots as $billed) {
            $edges[] = [
                'id' => (string) Str::ulid(),
                'lot_id' => $billed['lot_id'],
                'successor_lot_id' => $billedEarnedLotId,
                'consumed_amount' => $billed['amount'],
            ];
        }

        foreach ($edges as $edge) {
            DB::table('contract_consideration_transition_lots')->insert(
                $owner + [
                    'id' => $edge['id'],
                    'transition_id' => $transitionId,
                    'lot_id' => $edge['lot_id'],
                    'successor_lot_id' => $edge['successor_lot_id'],
                    'consumed_amount' => $edge['consumed_amount'],
                    'currency' => 'SAR',
                    'created_at' => $now,
                ],
            );
        }

        return [
            'transition_id' => $transitionId,
            'earned_lot_id' => $earnedLotId,
            'billed_earned_lot_id' => $billedEarnedLotId,
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
