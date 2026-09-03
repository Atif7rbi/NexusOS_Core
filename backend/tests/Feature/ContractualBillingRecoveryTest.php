<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateSuccessorContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Actions\SupersedeFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Support\ContractualBillingRecoveryResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingRecoveryTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_absent_entitlement_operation_is_retryable(): void
    {
        [$tenantId] = $this->context();

        $result = app(
            ContractualBillingRecoveryResolver::class,
        )->resolveEntitlementActivation(
            $tenantId,
            (string) Str::ulid(),
            (string) Str::ulid(),
        );

        self::assertSame('retryable', $result['status']);
        self::assertNull($result['entitlement_id']);
    }

    public function test_committed_entitlement_operation_resolves(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [, $obligationId] =
            $this->finalizedSource(
                $tenantId,
                $actor,
                $contractId,
            );

        $operationId = (string) Str::ulid();

        $entitlementId = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => $operationId,
            ],
        );

        $result = app(
            ContractualBillingRecoveryResolver::class,
        )->resolveEntitlementActivation(
            $tenantId,
            $operationId,
            $obligationId,
        );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $entitlementId,
            $result['entitlement_id'],
        );
    }

    public function test_committed_source_cancellation_resolves_exact_mapping(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$scheduleId, $obligationId] =
            $this->finalizedSource(
                $tenantId,
                $actor,
                $contractId,
            );

        $entitlementId = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
            ],
        );

        $correction = (string) Str::ulid();
        $reversal = (string) Str::ulid();

        app(CorrectFinalizedContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'source_correction_operation_id' => $correction,
                    'source_correction_reason' => 'Recovery cancellation',
                    'source_correction_reference' => 'CBS-RECOVERY-CANCEL',
                    'entitlement_reversals' => [
                        $entitlementId => $reversal,
                    ],
                ],
            );

        $result = app(
            ContractualBillingRecoveryResolver::class,
        )->resolveSourceCancellation(
            $tenantId,
            $scheduleId,
            $correction,
            'Recovery cancellation',
            'CBS-RECOVERY-CANCEL',
            [$entitlementId => $reversal],
        );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $scheduleId,
            $result['schedule_id'],
        );
    }

    public function test_source_cancellation_wrong_mapping_fails_closed(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$scheduleId, $obligationId] =
            $this->finalizedSource(
                $tenantId,
                $actor,
                $contractId,
            );

        $entitlementId = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
            ],
        );

        $correction = (string) Str::ulid();

        app(CorrectFinalizedContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'source_correction_operation_id' => $correction,
                    'source_correction_reason' => 'Recovery mismatch',
                    'source_correction_reference' => 'CBS-RECOVERY-MISMATCH',
                    'entitlement_reversals' => [
                        $entitlementId => (string) Str::ulid(),
                    ],
                ],
            );

        $this->expectException(
            ContractualBillingConflict::class,
        );

        app(ContractualBillingRecoveryResolver::class)
            ->resolveSourceCancellation(
                $tenantId,
                $scheduleId,
                $correction,
                'Recovery mismatch',
                'CBS-RECOVERY-MISMATCH',
                [$entitlementId => (string) Str::ulid()],
            );
    }

    public function test_committed_supersession_resolves(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$sourceId] =
            $this->finalizedSource(
                $tenantId,
                $actor,
                $contractId,
            );

        $successorId = app(
            CreateSuccessorContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $sourceId,
            $actor,
            [
                'schedule_operation_id' => (string) Str::ulid(),
            ],
        );

        app(SaveDraftContractualBillingObligation::class)
            ->execute(
                $tenantId,
                $successorId,
                $actor,
                [
                    'obligation_operation_id' => (string) Str::ulid(),
                    'amount' => '1000.00',
                    'contractual_due_date' => '2026-10-01',
                    'contractual_reference' => 'Recovery successor',
                ],
            );

        $correction = (string) Str::ulid();
        $finalization = (string) Str::ulid();

        app(
            SupersedeFinalizedContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $sourceId,
            $successorId,
            $actor,
            [
                'source_correction_operation_id' => $correction,
                'successor_finalization_operation_id' => $finalization,
                'source_correction_reason' => 'Recovery successor',
                'entitlement_reversals' => [],
            ],
        );

        $result = app(
            ContractualBillingRecoveryResolver::class,
        )->resolveSupersession(
            $tenantId,
            $sourceId,
            $successorId,
            $correction,
            $finalization,
            'Recovery successor',
            null,
            [],
        );

        self::assertSame('committed', $result['status']);
        self::assertSame(
            $sourceId,
            $result['source_schedule_id'],
        );
        self::assertSame(
            $successorId,
            $result['successor_schedule_id'],
        );
    }

    /**
     * @return array{string,User,string}
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

        $project = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
        );

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
            (string) $contract->id,
        ];
    }

    /**
     * @return array{string,string}
     */
    private function finalizedSource(
        string $tenantId,
        User $actor,
        string $contractId,
    ): array {
        $scheduleId = app(
            CreateContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'schedule_operation_id' => (string) Str::ulid(),
            ],
        );

        $obligationId = app(
            SaveDraftContractualBillingObligation::class,
        )->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => (string) Str::ulid(),
                'amount' => '1000.00',
                'contractual_due_date' => '2026-09-01',
                'contractual_reference' => 'Recovery source',
            ],
        );

        app(FinalizeContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'finalization_operation_id' => (string) Str::ulid(),
                ],
            );

        return [$scheduleId, $obligationId];
    }
}
