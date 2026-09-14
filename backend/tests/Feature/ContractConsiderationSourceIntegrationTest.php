<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Actions\ReverseUnitHandoverPerformanceSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationSourceIntegrationTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_adopted_billing_activation_atomically_creates_source_derived_transition_and_replays(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations($context, ['1000.00'], '2026-08-20')[0];
        $adoption = $this->adopt($context);
        $billingOperation = (string) Str::ulid();
        $transitionOperation = (string) Str::ulid();

        $input = [
            'billing_entitlement_operation_id' => $billingOperation,
            'contract_consideration_transition_operation_id' => $transitionOperation,
            // Hostile derived movement facts must be ignored.
            'economic_date' => '2099-12-31',
            'transition_amount' => '1.00',
            'currency' => 'USD',
            'semantic_precedence' => 999,
            'source_type' => 'UNIT_HANDOVER_ACCEPTANCE',
        ];

        $action = app(ActivateContractualBillingEntitlement::class);
        $first = $action->execute($context['tenant_id'], $obligationId, $context['actor'], $input);
        $second = $action->execute($context['tenant_id'], $obligationId, $context['actor'], $input);

        self::assertSame($first, $second);

        $transition = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $context['tenant_id'])
            ->where('source_type', 'CONTRACTUAL_BILLING_ENTITLEMENT')
            ->where('source_id', $first)
            ->first();

        self::assertNotNull($transition);
        self::assertSame($adoption['position_id'], $transition->position_id);
        self::assertSame($transitionOperation, $transition->transition_operation_id);
        self::assertSame('2026-08-20', (string) $transition->economic_date);
        self::assertSame(20, (int) $transition->semantic_precedence);
        self::assertSame('1000.00', (string) $transition->transition_amount);
        self::assertSame('SAR', $transition->currency);
        self::assertSame('effective', $transition->status);

        $output = DB::table('contract_consideration_lots')
            ->where('tenant_id', $context['tenant_id'])
            ->where('transition_id', $transition->id)
            ->first();

        self::assertNotNull($output);
        self::assertSame('BILLED_UNEARNED', $output->semantic_position);
        self::assertSame('1000.00', (string) $output->amount);
    }

    public function test_adopted_source_requires_transition_operation_and_rolls_back_source_atomically(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations($context, ['1000.00'], '2026-08-20')[0];
        $this->adopt($context);

        try {
            app(ActivateContractualBillingEntitlement::class)->execute(
                $context['tenant_id'],
                $obligationId,
                $context['actor'],
                ['billing_entitlement_operation_id' => (string) Str::ulid()],
            );
            $this->fail('Adopted Billing source accepted missing Consideration operation identity.');
        } catch (ContractConsiderationValidationFailed) {
            self::assertSame(
                0,
                DB::table('contractual_billing_entitlements')
                    ->where('tenant_id', $context['tenant_id'])
                    ->count(),
            );
            self::assertSame(
                0,
                DB::table('contract_consideration_transitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->count(),
            );
        }
    }

    public function test_adopted_source_replay_cannot_change_transition_operation_identity(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations($context, ['1000.00'], '2026-08-20')[0];
        $this->adopt($context);
        $billingOperation = (string) Str::ulid();

        $action = app(ActivateContractualBillingEntitlement::class);
        $action->execute($context['tenant_id'], $obligationId, $context['actor'], [
            'billing_entitlement_operation_id' => $billingOperation,
            'contract_consideration_transition_operation_id' => (string) Str::ulid(),
        ]);

        $this->expectException(ContractConsiderationConflict::class);
        $this->expectExceptionMessage('different operation identity');

        $action->execute($context['tenant_id'], $obligationId, $context['actor'], [
            'billing_entitlement_operation_id' => $billingOperation,
            'contract_consideration_transition_operation_id' => (string) Str::ulid(),
        ]);
    }

    public function test_billing_then_handover_consumes_exact_positions_and_preserves_split_truth(): void
    {
        $context = $this->considerationContext();
        $obligationIds = $this->billingObligations($context, ['400.00', '600.00'], '2026-08-20');
        $this->adopt($context);

        $billingId = app(ActivateContractualBillingEntitlement::class)->execute(
            $context['tenant_id'],
            $obligationIds[0],
            $context['actor'],
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $evidenceId = $this->recordApplicationEvidence($context, '2026-08-21');
        $acceptanceId = app(CreateUnitHandoverAcceptance::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $billingTransition = DB::table('contract_consideration_transitions')
            ->where('source_id', $billingId)
            ->first();
        $handoverTransition = DB::table('contract_consideration_transitions')
            ->where('source_id', $acceptanceId)
            ->first();

        self::assertNotNull($billingTransition);
        self::assertNotNull($handoverTransition);

        $outputs = DB::table('contract_consideration_lots')
            ->where('transition_id', $handoverTransition->id)
            ->orderBy('semantic_position')
            ->pluck('amount', 'semantic_position')
            ->map(static fn ($amount): string => (string) $amount)
            ->all();

        self::assertSame([
            'BILLED_EARNED' => '400.00',
            'EARNED_UNBILLED' => '600.00',
        ], $outputs);

        $edges = DB::table('contract_consideration_transition_lots as edge')
            ->join('contract_consideration_lots as predecessor', function ($join): void {
                $join->on('predecessor.tenant_id', '=', 'edge.tenant_id')
                    ->on('predecessor.id', '=', 'edge.lot_id');
            })
            ->where('edge.transition_id', $handoverTransition->id)
            ->pluck('edge.consumed_amount', 'predecessor.semantic_position')
            ->map(static fn ($amount): string => (string) $amount)
            ->all();

        self::assertSame('600.00', $edges['UNPERFORMED_UNBILLED'] ?? null);
        self::assertSame('400.00', $edges['BILLED_UNEARNED'] ?? null);
    }

    public function test_handover_then_partial_billing_moves_only_billed_earned_amount(): void
    {
        $context = $this->considerationContext();
        $obligationIds = $this->billingObligations($context, ['400.00', '600.00'], '2026-08-21');
        $this->adopt($context);

        $evidenceId = $this->recordApplicationEvidence($context, '2026-08-20');
        $acceptanceId = app(CreateUnitHandoverAcceptance::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $billingId = app(ActivateContractualBillingEntitlement::class)->execute(
            $context['tenant_id'],
            $obligationIds[0],
            $context['actor'],
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $handoverTransition = DB::table('contract_consideration_transitions')
            ->where('source_id', $acceptanceId)
            ->first();
        $billingTransition = DB::table('contract_consideration_transitions')
            ->where('source_id', $billingId)
            ->first();

        self::assertNotNull($handoverTransition);
        self::assertNotNull($billingTransition);

        $output = DB::table('contract_consideration_lots')
            ->where('transition_id', $billingTransition->id)
            ->first();

        self::assertNotNull($output);
        self::assertSame('BILLED_EARNED', $output->semantic_position);
        self::assertSame('400.00', (string) $output->amount);

        $earnedLot = DB::table('contract_consideration_lots')
            ->where('transition_id', $handoverTransition->id)
            ->where('semantic_position', 'EARNED_UNBILLED')
            ->first();

        self::assertNotNull($earnedLot);
        self::assertSame('1000.00', (string) $earnedLot->amount);
        self::assertSame(
            '400.00',
            (string) DB::table('contract_consideration_transition_lots')
                ->where('lot_id', $earnedLot->id)
                ->sum('consumed_amount'),
        );
    }

    public function test_handover_reversal_atomically_reverses_consideration_with_exact_source_provenance(): void
    {
        $context = $this->considerationContext();
        $this->adopt($context);
        $evidenceId = $this->recordApplicationEvidence($context, '2026-08-20');
        $acceptanceId = app(CreateUnitHandoverAcceptance::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $reversalOperation = (string) Str::ulid();
        app(ReverseUnitHandoverPerformanceSource::class)->execute(
            $context['tenant_id'],
            $acceptanceId,
            $context['actor'],
            [
                'reversal_operation_id' => $reversalOperation,
                'reversal_reason' => 'Correct accepted evidence',
                'reversal_reference' => 'CC/REV/'.$reversalOperation,
            ],
        );

        $source = DB::table('unit_handover_acceptances')->where('id', $acceptanceId)->first();
        $transition = DB::table('contract_consideration_transitions')->where('source_id', $acceptanceId)->first();

        self::assertNotNull($source);
        self::assertNotNull($transition);
        self::assertSame('reversed', $source->status);
        self::assertSame('reversed', $transition->status);
        self::assertSame($source->reversal_operation_id, $transition->reversal_operation_id);
        self::assertSame($source->reversal_operation_id, $transition->reversal_source_operation_id);
        self::assertSame($source->reversal_reason, $transition->reversal_reason);
        self::assertSame($source->reversal_reference, $transition->reversal_reference);
        self::assertSame($source->reversed_by, $transition->reversed_by);
        self::assertSame((string) $source->reversed_at, (string) $transition->reversed_at);
    }

    public function test_billing_correction_reverses_all_consideration_transitions_successor_first(): void
    {
        $context = $this->considerationContext();
        $obligationIds = $this->billingObligations($context, ['400.00', '600.00'], '2026-08-20');
        $this->adopt($context);
        $entitlements = [];
        $reversals = [];

        foreach ($obligationIds as $obligationId) {
            $entitlementId = app(ActivateContractualBillingEntitlement::class)->execute(
                $context['tenant_id'],
                $obligationId,
                $context['actor'],
                [
                    'billing_entitlement_operation_id' => (string) Str::ulid(),
                    'contract_consideration_transition_operation_id' => (string) Str::ulid(),
                ],
            );
            $entitlements[] = $entitlementId;
            $reversals[$entitlementId] = (string) Str::ulid();
        }

        $scheduleId = (string) DB::table('contractual_billing_entitlements')
            ->where('id', $entitlements[0])
            ->value('schedule_id');
        $sourceCorrection = (string) Str::ulid();

        app(CorrectFinalizedContractualBillingSchedule::class)->execute(
            $context['tenant_id'],
            $scheduleId,
            $context['actor'],
            [
                'source_correction_operation_id' => $sourceCorrection,
                'source_correction_reason' => 'Correct billing schedule',
                'source_correction_reference' => 'CC/CORRECTION/'.$sourceCorrection,
                'entitlement_reversals' => $reversals,
            ],
        );

        $rows = DB::table('contract_consideration_transitions')
            ->where('tenant_id', $context['tenant_id'])
            ->where('source_type', 'CONTRACTUAL_BILLING_ENTITLEMENT')
            ->orderBy('source_id')
            ->get();

        self::assertCount(2, $rows);
        foreach ($rows as $transition) {
            self::assertSame('reversed', $transition->status);
            self::assertSame($sourceCorrection, $transition->reversal_source_operation_id);
            self::assertSame($reversals[(string) $transition->source_id], $transition->reversal_operation_id);
            self::assertSame('Correct billing schedule', $transition->reversal_reason);
            self::assertSame('CC/CORRECTION/'.$sourceCorrection, $transition->reversal_reference);
        }
    }

    private function recordApplicationEvidence(array $context, string $acceptanceDate): string
    {
        $operationId = (string) Str::ulid();

        return app(RecordUnitHandoverEvidence::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'handover_evidence_operation_id' => $operationId,
                'handover_evidence_reference' => 'CC/HANDOVER/'.$operationId,
                'readiness_reference' => 'CC/READY/'.$operationId,
                'readiness_effective_date' => '2026-08-19',
                'customer_acceptance_reference' => 'CC/ACCEPT/'.$operationId,
                'customer_acceptance_effective_date' => $acceptanceDate,
            ],
        );
    }
}
