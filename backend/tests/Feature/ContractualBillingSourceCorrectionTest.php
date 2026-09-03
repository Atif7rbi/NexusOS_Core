<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingSourceCorrectionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_finalized_source_cancellation_reverses_exact_entitlement_set_atomically(): void
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

        $correctionOperation = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();

        $result = app(
            CorrectFinalizedContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'source_correction_operation_id' => $correctionOperation,
                'source_correction_reason' => 'Contractual source rescinded',
                'source_correction_reference' => 'CBS-CORRECTION-1',
                'entitlement_reversals' => [
                    $entitlementId => $reversalOperation,
                ],
            ],
        );

        self::assertSame($scheduleId, $result);

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('cancelled', $schedule->status);
        self::assertSame(
            $correctionOperation,
            $schedule->source_correction_operation_id,
        );

        $entitlement = DB::table(
            'contractual_billing_entitlements',
        )
            ->where('id', $entitlementId)
            ->first();

        self::assertNotNull($entitlement);
        self::assertSame('reversed', $entitlement->status);
        self::assertSame(
            $reversalOperation,
            $entitlement->reversal_operation_id,
        );
        self::assertSame(
            $correctionOperation,
            $entitlement->source_correction_operation_id,
        );
        self::assertSame(
            'CBS-CORRECTION-1',
            $entitlement->source_rescission_reference,
        );
    }

    public function test_source_cancellation_same_operation_same_facts_and_mapping_replays(): void
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

        $input = [
            'source_correction_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'Source corrected',
            'source_correction_reference' => 'CBS-CORRECTION-REPLAY',
            'entitlement_reversals' => [
                $entitlementId => (string) Str::ulid(),
            ],
        ];

        $action = app(
            CorrectFinalizedContractualBillingSchedule::class,
        );

        $first = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            $input,
        );

        $second = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            $input,
        );

        self::assertSame($scheduleId, $first);
        self::assertSame($first, $second);
    }

    public function test_source_cancellation_rejects_missing_entitlement_mapping_without_partial_mutation(): void
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

        try {
            app(CorrectFinalizedContractualBillingSchedule::class)
                ->execute(
                    $tenantId,
                    $scheduleId,
                    $actor,
                    [
                        'source_correction_operation_id' => (string) Str::ulid(),
                        'source_correction_reason' => 'Incomplete mapping attempt',
                        'source_correction_reference' => 'CBS-CORRECTION-BAD',
                        'entitlement_reversals' => [],
                    ],
                );

            self::fail(
                'Expected exact Entitlement mapping validation failure.',
            );
        } catch (ContractualBillingConflict $exception) {
            self::assertSame(
                'Entitlement reversal mapping must exactly match the locked effective Entitlement set.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'finalized',
            DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->value('status'),
        );

        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('status'),
        );
    }

    public function test_source_correction_replay_with_different_mapping_conflicts(): void
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

        $operationId = (string) Str::ulid();

        $action = app(
            CorrectFinalizedContractualBillingSchedule::class,
        );

        $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'source_correction_operation_id' => $operationId,
                'source_correction_reason' => 'Correction',
                'source_correction_reference' => 'CBS-REF',
                'entitlement_reversals' => [
                    $entitlementId => (string) Str::ulid(),
                ],
            ],
        );

        $this->expectException(
            ContractualBillingConflict::class,
        );
        $this->expectExceptionMessage(
            'Source correction replay used a different Entitlement reversal mapping.',
        );

        $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'source_correction_operation_id' => $operationId,
                'source_correction_reason' => 'Correction',
                'source_correction_reference' => 'CBS-REF',
                'entitlement_reversals' => [
                    $entitlementId => (string) Str::ulid(),
                ],
            ],
        );
    }

    public function test_finalized_source_without_entitlements_can_be_cancelled_with_empty_mapping(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$scheduleId] = $this->finalizedSource(
            $tenantId,
            $actor,
            $contractId,
        );

        app(CorrectFinalizedContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'source_correction_operation_id' => (string) Str::ulid(),
                    'source_correction_reason' => 'Source withdrawn before entitlement',
                    'entitlement_reversals' => [],
                ],
            );

        self::assertSame(
            'cancelled',
            DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->value('status'),
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
                'contractual_reference' => 'Source correction fixture',
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
