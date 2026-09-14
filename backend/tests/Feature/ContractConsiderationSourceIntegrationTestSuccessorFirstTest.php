<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationSourceIntegrationTestSuccessorFirstTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_billing_correction_cannot_reverse_predecessor_while_later_handover_is_effective_and_rolls_back_atomically(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-20',
        )[0];

        $this->adopt($context);

        $entitlementId = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $context['tenant_id'],
            $obligationId,
            $context['actor'],
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $evidenceOperation = (string) Str::ulid();
        $evidenceId = app(RecordUnitHandoverEvidence::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'handover_evidence_operation_id' => $evidenceOperation,
                'handover_evidence_reference' => 'CC/HANDOVER/'.$evidenceOperation,
                'readiness_reference' => 'CC/READY/'.$evidenceOperation,
                'readiness_effective_date' => '2026-08-19',
                'customer_acceptance_reference' => 'CC/ACCEPT/'.$evidenceOperation,
                'customer_acceptance_effective_date' => '2026-08-21',
            ],
        );

        $acceptanceId = app(CreateUnitHandoverAcceptance::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
                'contract_consideration_transition_operation_id' => (string) Str::ulid(),
            ],
        );

        $scheduleId = (string) DB::table('contractual_billing_entitlements')
            ->where('id', $entitlementId)
            ->value('schedule_id');

        $sourceCorrection = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();

        try {
            app(CorrectFinalizedContractualBillingSchedule::class)->execute(
                $context['tenant_id'],
                $scheduleId,
                $context['actor'],
                [
                    'source_correction_operation_id' => $sourceCorrection,
                    'source_correction_reason' => 'Attempt invalid predecessor correction',
                    'source_correction_reference' => 'CC/CORRECTION/'.$sourceCorrection,
                    'entitlement_reversals' => [
                        $entitlementId => $reversalOperation,
                    ],
                ],
            );

            $this->fail(
                'Billing correction reversed a predecessor while later Handover remained effective.',
            );
        } catch (ContractualBillingValidationFailed) {
            $entitlement = DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->first();
            $billingTransition = DB::table('contract_consideration_transitions')
                ->where('source_type', 'CONTRACTUAL_BILLING_ENTITLEMENT')
                ->where('source_id', $entitlementId)
                ->first();
            $acceptance = DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->first();
            $handoverTransition = DB::table('contract_consideration_transitions')
                ->where('source_type', 'UNIT_HANDOVER_ACCEPTANCE')
                ->where('source_id', $acceptanceId)
                ->first();
            $schedule = DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->first();

            self::assertNotNull($entitlement);
            self::assertNotNull($billingTransition);
            self::assertNotNull($acceptance);
            self::assertNotNull($handoverTransition);
            self::assertNotNull($schedule);

            self::assertSame('effective', $entitlement->status);
            self::assertNull($entitlement->reversal_operation_id);
            self::assertSame('effective', $billingTransition->status);
            self::assertNull($billingTransition->reversal_operation_id);
            self::assertSame('effective', $acceptance->status);
            self::assertSame('effective', $handoverTransition->status);
            self::assertSame('finalized', $schedule->status);
            self::assertNull($schedule->source_correction_operation_id);
        }
    }
}
