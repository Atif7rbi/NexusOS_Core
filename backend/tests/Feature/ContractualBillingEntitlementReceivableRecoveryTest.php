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
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Support\EntitlementReceivableRecoveryResolver;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableRecoveryTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_absent_establishment_is_retryable(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $result = app(EntitlementReceivableRecoveryResolver::class)
            ->resolveEstablishment(
                $tenantId,
                $entitlementId,
                (string) Str::ulid(),
            );

        self::assertSame('retryable', $result['status']);
        self::assertNull($result['receivable_id']);
    }

    public function test_committed_establishment_resolves_complete_pair(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $operationId = (string) Str::ulid();
        $receivableId = app(EstablishEntitlementReceivable::class)
            ->execute(
                $tenantId,
                $entitlementId,
                $actor,
                [
                    'receivable_establishment_operation_id' => $operationId,
                ],
            );

        $result = app(EntitlementReceivableRecoveryResolver::class)
            ->resolveEstablishment(
                $tenantId,
                $entitlementId,
                $operationId,
            );

        self::assertSame('committed', $result['status']);
        self::assertSame($receivableId, $result['receivable_id']);
    }

    public function test_receivable_without_link_fails_closed(): void
    {
        [$tenantId, $actor, $customerId, $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $operationId = (string) Str::ulid();

        app(RecognizeReceivableAction::class)->execute(
            $tenantId,
            $actor,
            [
                'recognition_operation_id' => $operationId,
                'customer_id' => $customerId,
                'contract_id' => $contractId,
                'collection_id' => null,
                'currency' => 'SAR',
                'recognized_amount' => '1000.00',
                'due_date' => '2026-09-01',
                'recognized_at' => '2026-09-05T00:00:00+00:00',
            ],
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Receivable establishment recovery found partial or contradictory durable truth.',
        );

        app(EntitlementReceivableRecoveryResolver::class)
            ->resolveEstablishment(
                $tenantId,
                $entitlementId,
                $operationId,
            );
    }

    public function test_original_establishment_remains_committed_after_source_correction(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $operationId = (string) Str::ulid();
        $receivableId = app(EstablishEntitlementReceivable::class)
            ->execute(
                $tenantId,
                $entitlementId,
                $actor,
                [
                    'receivable_establishment_operation_id' => $operationId,
                ],
            );

        app(CorrectFinalizedContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'source_correction_operation_id' => (string) Str::ulid(),
                'source_correction_reason' => 'Recovery historical correction',
                'source_correction_reference' => 'ERL-RECOVERY-HISTORY',
                'entitlement_reversals' => [
                    $entitlementId => (string) Str::ulid(),
                ],
            ],
        );

        $result = app(EntitlementReceivableRecoveryResolver::class)
            ->resolveEstablishment(
                $tenantId,
                $entitlementId,
                $operationId,
            );

        self::assertSame('committed', $result['status']);
        self::assertSame($receivableId, $result['receivable_id']);
    }

    /**
     * @return array{string,User,string,string}
     */
    private function context(): array
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $tenantId = $this->integrityTenantId($actor);

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'timezone' => 'Asia/Riyadh',
                'currency' => 'SAR',
                'updated_at' => now(),
            ]);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actor->id,
            'reserved',
        );
        $customer = $this->createIntegrityCustomer(
            $tenantId,
            $actor->id,
        );
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actor->id,
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            [
                'total_amount' => '1000.00',
                'currency' => 'SAR',
            ],
        );

        return [
            $tenantId,
            $actor,
            (string) $customer->id,
            (string) $contract->id,
        ];
    }

    /**
     * @return array{string,string,string}
     */
    private function effectiveEntitlement(
        string $tenantId,
        User $actor,
        string $contractId,
    ): array {
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
                    'contractual_reference' => 'ERL recovery fixture',
                ],
            );

        app(FinalizeContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'finalization_operation_id' => (string) Str::ulid(),
            ],
        );

        $entitlementId = app(ActivateContractualBillingEntitlement::class)
            ->execute(
                $tenantId,
                $obligationId,
                $actor,
                [
                    'billing_entitlement_operation_id' => (string) Str::ulid(),
                ],
            );

        return [$scheduleId, $obligationId, $entitlementId];
    }
}
