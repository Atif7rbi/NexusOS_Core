<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableAllocationGuardTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_effective_allocation_blocks_compound_source_correction_without_partial_mutation(): void
    {
        [$tenantId, $actor, $customerId, $contractId] = $this->context();

        $scheduleId = app(CreateContractualBillingSchedule::class)->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'schedule_operation_id' => (string) Str::ulid(),
            ],
        );

        $obligationId = app(SaveDraftContractualBillingObligation::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'obligation_operation_id' => (string) Str::ulid(),
                    'amount' => '1000.00',
                    'contractual_due_date' => '2026-09-01',
                    'contractual_reference' => 'Allocation guard fixture',
                ],
            );

        app(FinalizeContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            ['finalization_operation_id' => (string) Str::ulid()],
        );

        $entitlementId = app(ActivateContractualBillingEntitlement::class)
            ->execute(
                $tenantId,
                $obligationId,
                $actor,
                ['billing_entitlement_operation_id' => (string) Str::ulid()],
            );

        $receivableId = app(EstablishEntitlementReceivable::class)->execute(
            $tenantId,
            $entitlementId,
            $actor,
            ['receivable_establishment_operation_id' => (string) Str::ulid()],
        );

        $paymentId = app(RecordPaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'amount' => '1000.00',
                'currency' => 'SAR',
                'received_on' => '2026-09-05',
            ],
        );

        app(AllocatePaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '1000.00',
            ],
        );

        try {
            app(CorrectFinalizedContractualBillingSchedule::class)->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'source_correction_operation_id' => (string) Str::ulid(),
                    'source_correction_reason' => 'Must remain blocked',
                    'source_correction_reference' => 'ERL-ALLOC-BLOCK',
                    'entitlement_reversals' => [
                        $entitlementId => (string) Str::ulid(),
                    ],
                ],
            );

            self::fail('Source correction was accepted with an effective Payment Allocation.');
        } catch (PaymentsConflict) {
            self::assertTrue(true);
        }

        self::assertSame('finalized', DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('status'));
        self::assertSame('effective', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'));
        self::assertSame('recognized', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertNull(DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
    }

    /** @return array{string,User,string,string} */
    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        DB::table('tenants')->where('id', $tenantId)->update(['timezone' => 'Asia/Riyadh', 'currency' => 'SAR', 'updated_at' => now()]);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'reserved');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id);
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actor->id, 'active', [
            'total_amount' => '1000.00',
            'currency' => 'SAR',
        ]);

        return [$tenantId, $actor, (string) $customer->id, (string) $contract->id];
    }
}
