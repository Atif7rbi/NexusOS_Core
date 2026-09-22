<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizePerformanceAccounting;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ReceivableArRecognitionSecurityTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_runtime_role_cannot_forge_receivable_ar_provenance_without_exact_owner(): void
    {
        [$context, $recognition, $origin] = $this->recognizedContext();
        $allocation = DB::table(
            'accounting_position_origin_journal_line_allocations',
        )
            ->where('tenant_id', $context['tenant_id'])
            ->where('origin_id', $origin->id)
            ->first();

        self::assertNotNull($allocation);

        $fakeRecognitionId = (string) Str::ulid();
        $fakeOriginId = (string) Str::ulid();
        $caught = null;

        try {
            $this->asRuntimeRole(function () use (
                $context,
                $origin,
                $allocation,
                $fakeRecognitionId,
                $fakeOriginId,
            ): void {
                DB::table('accounting_position_origins')->insert([
                    'id' => $fakeOriginId,
                    'tenant_id' => $context['tenant_id'],
                    'contract_id' => $context['contract_id'],
                    'position_type' => $origin->position_type,
                    'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
                    'origin_recognition_id' => $fakeRecognitionId,
                    'origin_journal_entry_id' => $origin->origin_journal_entry_id,
                    'account_id' => $origin->account_id,
                    'economic_source_type' => $origin->economic_source_type,
                    'economic_source_id' => $origin->economic_source_id,
                    'consideration_transition_id' => $origin->consideration_transition_id,
                    'consideration_lot_id' => $origin->consideration_lot_id,
                    'economic_leg_identity' => 'AR:FORGED:'.$fakeRecognitionId,
                    'origin_amount' => $origin->origin_amount,
                    'currency' => 'SAR',
                    'accounting_date' => $origin->accounting_date,
                    'status' => 'effective',
                    'created_at' => now(),
                    'reversal_origin_operation_id' => null,
                    'reversed_at' => null,
                ]);

                DB::table(
                    'accounting_position_origin_journal_line_allocations',
                )->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $context['tenant_id'],
                    'contract_id' => $context['contract_id'],
                    'origin_id' => $fakeOriginId,
                    'journal_entry_id' => $allocation->journal_entry_id,
                    'journal_line_id' => $allocation->journal_line_id,
                    'amount' => $origin->origin_amount,
                    'currency' => 'SAR',
                    'economic_leg_identity' => 'AR:FORGED:'.$fakeRecognitionId,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($recognition);
        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame(
            '23503',
            (string) ($caught->errorInfo[0] ?? ''),
            $caught->getMessage(),
        );
    }

    public function test_runtime_role_cannot_mutate_canonical_receivable_ar_history(): void
    {
        [$context, $recognition] = $this->recognizedContext();
        $caught = null;

        try {
            $this->asRuntimeRole(function () use (
                $context,
                $recognition,
            ): void {
                DB::table('receivable_ar_recognitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('id', $recognition->id)
                    ->update([
                        'receivable_amount' => '999.00',
                    ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame(
            '55000',
            (string) ($caught->errorInfo[0] ?? ''),
            $caught->getMessage(),
        );

        self::assertSame(
            '1000.00',
            DB::table('receivable_ar_recognitions')
                ->where('id', $recognition->id)
                ->value('receivable_amount'),
        );
    }

    public function test_direct_sql_exact_reversal_cannot_commit_while_recognition_remains_posted(): void
    {
        [$context, $recognition] = $this->recognizedContext();

        $this->assertDirectSqlRejected(function () use (
            $context,
            $recognition,
        ): void {
            $this->createDirectReversalJournal(
                $context,
                $recognition,
                'Forged direct reversal',
            );
        });
    }

    public function test_direct_sql_standalone_recognition_reversal_cannot_commit_without_source_reversal(): void
    {
        [$context, $recognition, $origin] = $this->recognizedContext();

        $this->assertDirectSqlRejected(function () use (
            $context,
            $recognition,
            $origin,
        ): void {
            $operationId = (string) Str::ulid();
            $reversedAt = now();
            $reversalJournalId = $this->createDirectReversalJournal(
                $context,
                $recognition,
                'Standalone AR reversal',
            );

            DB::table('accounting_position_origins')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $origin->id)
                ->update([
                    'status' => 'reversed',
                    'reversal_origin_operation_id' => $operationId,
                    'reversed_at' => $reversedAt,
                ]);

            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $recognition->id)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $operationId,
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reversed_by' => $context['actor']->id,
                    'reversed_at' => $reversedAt,
                ]);
        });
    }

    public function test_direct_sql_rejects_receivable_ar_consumption_with_noncanonical_edge_identity(): void
    {
        $fixture = $this->unrecognizedAssetBillingContext();

        $this->assertDirectSqlRejected(function () use ($fixture): void {
            $this->insertDirectAssetRecognitionWithWrongEdgeIdentity(
                $fixture,
            );
        });
    }

    public function test_direct_sql_rejects_split_receivable_ar_journal_grammar(): void
    {
        foreach (['split_ar', 'split_liability'] as $variant) {
            $fixture = $this->unrecognizedEarlyBillingContext();

            $this->assertDirectSqlRejected(function () use (
                $fixture,
                $variant,
            ): void {
                $this->insertDirectEarlyBillingRecognition(
                    $fixture,
                    $variant,
                );
            });
        }
    }

    private function recognizedContext(): array
    {
        $fixture = $this->unrecognizedEarlyBillingContext();

        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'contractual_billing_entitlement_id' =>
                    $fixture['source']['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();
        $origin = DB::table('accounting_position_origins')
            ->where(
                'origin_recognition_type',
                'RECEIVABLE_AR_RECOGNITION',
            )
            ->where('origin_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertNotNull($origin);

        return [$fixture['context'], $recognition, $origin];
    }

    private function unrecognizedAssetBillingContext(): array
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);

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

        $ar = $this->account(
            $context,
            'AR-SEC-ASSET-CTRL',
            'asset',
            'current_asset',
        );
        $asset = $this->account(
            $context,
            'AR-SEC-ASSET-CA',
            'asset',
            'current_asset',
        );
        $liability = $this->account(
            $context,
            'AR-SEC-ASSET-CL',
            'liability',
            'current_liability',
        );
        $revenue = $this->account(
            $context,
            'AR-SEC-ASSET-REV',
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
            $asset,
            $liability,
        );
        app(ConfigureAccountingRecognitionPolicies::class)->performance(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $revenue,
            $asset,
            $liability,
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
            ->where('tenant_id', $context['tenant_id'])
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

        [$source, $billingGraph] = DB::transaction(function () use (
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

        $receivableId = app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' =>
                    (string) Str::ulid(),
            ],
        );

        $entitlement = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $source['id'])
            ->first();
        $arPolicy = DB::table('receivable_ar_policies')
            ->where('tenant_id', $context['tenant_id'])
            ->where('status', 'active')
            ->first();
        $counterpartPolicy = DB::table(
            'receivable_ar_counterpart_policies',
        )
            ->where('tenant_id', $context['tenant_id'])
            ->where('status', 'active')
            ->first();

        self::assertNotNull($entitlement);
        self::assertNotNull($arPolicy);
        self::assertNotNull($counterpartPolicy);

        return [
            'context' => $context,
            'source' => $source,
            'billing_graph' => $billingGraph,
            'performance_origin' => $performanceOrigin,
            'receivable_id' => $receivableId,
            'entitlement' => $entitlement,
            'ar_policy' => $arPolicy,
            'counterpart_policy' => $counterpartPolicy,
            'accounts' => [
                'ar' => $ar,
                'asset' => $asset,
                'liability' => $liability,
            ],
        ];
    }

    private function unrecognizedEarlyBillingContext(): array
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);

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

        $ar = $this->account(
            $context,
            'AR-SEC-CTRL',
            'asset',
            'current_asset',
        );
        $asset = $this->account(
            $context,
            'AR-SEC-ASSET',
            'asset',
            'current_asset',
        );
        $liability = $this->account(
            $context,
            'AR-SEC-LIAB',
            'liability',
            'current_liability',
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
            $asset,
            $liability,
        );

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

        $entitlement = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $source['id'])
            ->first();
        $arPolicy = DB::table('receivable_ar_policies')
            ->where('tenant_id', $context['tenant_id'])
            ->where('status', 'active')
            ->first();
        $counterpartPolicy = DB::table(
            'receivable_ar_counterpart_policies',
        )
            ->where('tenant_id', $context['tenant_id'])
            ->where('status', 'active')
            ->first();

        self::assertNotNull($entitlement);
        self::assertNotNull($arPolicy);
        self::assertNotNull($counterpartPolicy);

        return [
            'context' => $context,
            'source' => $source,
            'graph' => $graph,
            'receivable_id' => $receivableId,
            'entitlement' => $entitlement,
            'ar_policy' => $arPolicy,
            'counterpart_policy' => $counterpartPolicy,
            'accounts' => [
                'ar' => $ar,
                'asset' => $asset,
                'liability' => $liability,
            ],
        ];
    }

    private function insertDirectAssetRecognitionWithWrongEdgeIdentity(
        array $fixture,
    ): void {
        $context = $fixture['context'];
        $recognitionId = (string) Str::ulid();

        [$journalId, $lineIds] = $this->createDirectBusinessJournal(
            $context,
            $recognitionId,
            (string) $fixture['entitlement']->economic_date,
            [
                [$fixture['accounts']['ar'], '1000.00', '0.00'],
                [$fixture['accounts']['asset'], '0.00', '1000.00'],
            ],
        );

        $now = now();

        DB::table('receivable_ar_recognitions')->insert([
            'id' => $recognitionId,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'contractual_billing_entitlement_id' =>
                $fixture['source']['id'],
            'receivable_id' => $fixture['receivable_id'],
            'billing_consideration_transition_id' =>
                $fixture['billing_graph']['transition_id'],
            'recognition_kind' => 'original',
            'receivable_ar_operation_id' => (string) Str::ulid(),
            'receivable_amount' => '1000.00',
            'contract_asset_release_amount' => '1000.00',
            'contract_liability_creation_amount' => '0.00',
            'currency' => 'SAR',
            'accounting_date' => $fixture['entitlement']->economic_date,
            'receivable_ar_policy_id' => $fixture['ar_policy']->id,
            'receivable_ar_policy_version' =>
                $fixture['ar_policy']->policy_version,
            'counterpart_policy_id' =>
                $fixture['counterpart_policy']->id,
            'counterpart_policy_version' =>
                $fixture['counterpart_policy']->policy_version,
            'ar_control_account_id' => $fixture['accounts']['ar'],
            'counterpart_contract_asset_account_id' =>
                $fixture['accounts']['asset'],
            'contract_liability_account_id' => null,
            'journal_entry_id' => $journalId,
            'status' => 'posted',
            'created_by' => $context['actor']->id,
            'created_at' => $now,
        ]);

        $consumptionId = (string) Str::ulid();
        $wrongLeg = 'AR:CONSUME:'.
            $fixture['billing_graph']['transition_id'].':FORGED';

        DB::table('accounting_position_consumptions')->insert([
            'id' => $consumptionId,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'origin_id' => $fixture['performance_origin']->id,
            'consuming_recognition_type' =>
                'RECEIVABLE_AR_RECOGNITION',
            'consuming_recognition_id' => $recognitionId,
            'consuming_journal_entry_id' => $journalId,
            'consideration_transition_id' =>
                $fixture['billing_graph']['transition_id'],
            'consideration_lot_id' =>
                $fixture['billing_graph']['lot_id'],
            'economic_leg_identity' => $wrongLeg,
            'amount' => '1000.00',
            'currency' => 'SAR',
            'status' => 'effective',
            'created_at' => $now,
        ]);

        DB::table(
            'accounting_position_consumption_journal_line_allocations',
        )->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'consumption_id' => $consumptionId,
            'journal_entry_id' => $journalId,
            'journal_line_id' => $lineIds[1],
            'amount' => '1000.00',
            'currency' => 'SAR',
            'economic_leg_identity' => $wrongLeg,
            'created_at' => $now,
        ]);
    }

    private function insertDirectEarlyBillingRecognition(
        array $fixture,
        string $variant,
    ): void {
        $context = $fixture['context'];
        $recognitionId = (string) Str::ulid();

        $lines = $variant === 'split_ar'
            ? [
                [$fixture['accounts']['ar'], '500.00', '0.00'],
                [$fixture['accounts']['ar'], '500.00', '0.00'],
                [$fixture['accounts']['liability'], '0.00', '1000.00'],
            ]
            : [
                [$fixture['accounts']['ar'], '1000.00', '0.00'],
                [$fixture['accounts']['liability'], '0.00', '500.00'],
                [$fixture['accounts']['liability'], '0.00', '500.00'],
            ];

        [$journalId, $lineIds] = $this->createDirectBusinessJournal(
            $context,
            $recognitionId,
            (string) $fixture['entitlement']->economic_date,
            $lines,
        );

        $now = now();

        DB::table('receivable_ar_recognitions')->insert([
            'id' => $recognitionId,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'contractual_billing_entitlement_id' =>
                $fixture['source']['id'],
            'receivable_id' => $fixture['receivable_id'],
            'billing_consideration_transition_id' =>
                $fixture['graph']['transition_id'],
            'recognition_kind' => 'original',
            'receivable_ar_operation_id' => (string) Str::ulid(),
            'receivable_amount' => '1000.00',
            'contract_asset_release_amount' => '0.00',
            'contract_liability_creation_amount' => '1000.00',
            'currency' => 'SAR',
            'accounting_date' => $fixture['entitlement']->economic_date,
            'receivable_ar_policy_id' => $fixture['ar_policy']->id,
            'receivable_ar_policy_version' =>
                $fixture['ar_policy']->policy_version,
            'counterpart_policy_id' =>
                $fixture['counterpart_policy']->id,
            'counterpart_policy_version' =>
                $fixture['counterpart_policy']->policy_version,
            'ar_control_account_id' => $fixture['accounts']['ar'],
            'counterpart_contract_asset_account_id' =>
                $fixture['accounts']['asset'],
            'contract_liability_account_id' =>
                $fixture['accounts']['liability'],
            'journal_entry_id' => $journalId,
            'status' => 'posted',
            'created_by' => $context['actor']->id,
            'created_at' => $now,
        ]);

        $originId = (string) Str::ulid();
        $leg = 'AR:ORIGIN:'.$fixture['graph']['transition_id'].':'.
            $fixture['graph']['lot_id'];

        DB::table('accounting_position_origins')->insert([
            'id' => $originId,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'position_type' => 'CONTRACT_LIABILITY',
            'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
            'origin_recognition_id' => $recognitionId,
            'origin_journal_entry_id' => $journalId,
            'account_id' => $fixture['accounts']['liability'],
            'economic_source_type' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
            'economic_source_id' => $fixture['source']['id'],
            'consideration_transition_id' =>
                $fixture['graph']['transition_id'],
            'consideration_lot_id' => $fixture['graph']['lot_id'],
            'economic_leg_identity' => $leg,
            'origin_amount' => '1000.00',
            'currency' => 'SAR',
            'accounting_date' => $fixture['entitlement']->economic_date,
            'status' => 'effective',
            'created_at' => $now,
        ]);

        foreach ($lineIds as $index => $lineId) {
            if ($lines[$index][0] !== $fixture['accounts']['liability']) {
                continue;
            }

            DB::table(
                'accounting_position_origin_journal_line_allocations',
            )->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'contract_id' => $context['contract_id'],
                'origin_id' => $originId,
                'journal_entry_id' => $journalId,
                'journal_line_id' => $lineId,
                'amount' => $lines[$index][2],
                'currency' => 'SAR',
                'economic_leg_identity' => $leg,
                'created_at' => $now,
            ]);
        }
    }

    private function createDirectBusinessJournal(
        array $context,
        string $recognitionId,
        string $entryDate,
        array $lines,
    ): array {
        $id = (string) Str::ulid();
        $at = now();

        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'entry_date' => $entryDate,
            'description' => 'Direct SQL Receivable AR fixture',
            'status' => 'draft',
            'origin' => 'business',
            'source_type' => 'receivable_ar_recognition',
            'source_id' => $recognitionId,
            'created_by' => $context['actor']->id,
            'updated_by' => $context['actor']->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $lineIds = [];

        foreach ($lines as $index => [$accountId, $debit, $credit]) {
            $lineId = (string) Str::ulid();
            $lineIds[$index] = $lineId;

            DB::table('journal_lines')->insert([
                'id' => $lineId,
                'tenant_id' => $context['tenant_id'],
                'journal_entry_id' => $id,
                'line_number' => $index + 1,
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => 'Direct SQL Receivable AR fixture line',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $period = DB::table('accounting_periods')
            ->where('tenant_id', $context['tenant_id'])
            ->whereDate('start_date', '<=', $entryDate)
            ->whereDate('end_date', '>=', $entryDate)
            ->first();

        self::assertNotNull($period);

        $sequence = ((int) DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_number_year', 2026)
            ->max('journal_sequence_number')) + 1;
        $number = 'JRN-2026-'.str_pad(
            (string) $sequence,
            max(3, strlen((string) $sequence)),
            '0',
            STR_PAD_LEFT,
        );

        DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $id)
            ->update([
                'status' => 'posted',
                'accounting_period_id' => $period->id,
                'journal_number' => $number,
                'journal_number_year' => 2026,
                'journal_sequence_number' => $sequence,
                'posted_by' => $context['actor']->id,
                'posted_at' => $at,
                'updated_by' => $context['actor']->id,
                'updated_at' => $at,
            ]);

        return [$id, $lineIds];
    }

    private function assertDirectSqlRejected(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail(
                'PostgreSQL accepted inconsistent Receivable AR final state.',
            );
        } catch (QueryException $exception) {
            self::assertContains(
                (string) ($exception->errorInfo[0] ?? ''),
                ['23514', '23503', '55000'],
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function createDirectReversalJournal(
        array $context,
        object $recognition,
        string $reason,
    ): string {
        $target = DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $recognition->journal_entry_id)
            ->first();

        self::assertNotNull($target);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $target->id)
            ->orderBy('line_number')
            ->get();

        self::assertGreaterThanOrEqual(2, $lines->count());

        $id = (string) Str::ulid();
        $at = now();

        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'entry_date' => $target->entry_date,
            'description' => 'Direct SQL reversal fixture',
            'status' => 'draft',
            'origin' => 'reversal',
            'source_type' => 'journal_entry',
            'source_id' => $target->id,
            'created_by' => $context['actor']->id,
            'updated_by' => $context['actor']->id,
            'created_at' => $at,
            'updated_at' => $at,
            'reverses_journal_entry_id' => $target->id,
            'reversal_reason' => $reason,
        ]);

        foreach ($lines as $line) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'journal_entry_id' => $id,
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => $line->memo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $sequence = ((int) DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_number_year', 2026)
            ->max('journal_sequence_number')) + 1;
        $number = 'JRN-2026-'.str_pad(
            (string) $sequence,
            max(3, strlen((string) $sequence)),
            '0',
            STR_PAD_LEFT,
        );

        DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $id)
            ->update([
                'status' => 'posted',
                'accounting_period_id' => $target->accounting_period_id,
                'journal_number' => $number,
                'journal_number_year' => 2026,
                'journal_sequence_number' => $sequence,
                'posted_by' => $context['actor']->id,
                'posted_at' => $at,
                'updated_by' => $context['actor']->id,
                'updated_at' => $at,
            ]);

        return $id;
    }

    private function account(
        array $context,
        string $code,
        string $type,
        string $classification,
    ): string {
        return app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => $code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );
    }

    private function asRuntimeRole(callable $callback): mixed
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new \RuntimeException(
                'Invalid ACCOUNTING_RUNTIME_DB_ROLE.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $role).'"';

        return DB::transaction(function () use (
            $identifier,
            $callback,
        ): mixed {
            DB::statement("SET LOCAL ROLE {$identifier}");

            $result = $callback();

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }
}
