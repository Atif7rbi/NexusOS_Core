<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CancelDraftContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementAndDraftCancellationTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_entitlement_activation_derives_canonical_facts_and_replays(): void
    {
        [$tenantId, $actor, $customerId, $contractId] =
            $this->context();

        [$scheduleId, $obligationId] = $this->finalizedSource(
            $tenantId,
            $actor,
            $contractId,
            '2026-09-01',
        );

        $operationId = (string) Str::ulid();

        $action = app(
            ActivateContractualBillingEntitlement::class,
        );

        $first = $action->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => $operationId,
            ],
        );

        $second = $action->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => $operationId,
            ],
        );

        self::assertSame($first, $second);

        $row = DB::table('contractual_billing_entitlements')
            ->where('id', $first)
            ->first();

        self::assertNotNull($row);
        self::assertSame($scheduleId, $row->schedule_id);
        self::assertSame($obligationId, $row->obligation_id);
        self::assertSame($contractId, $row->contract_id);
        self::assertSame($customerId, $row->customer_id);
        self::assertSame('1000.00', (string) $row->amount);
        self::assertSame('SAR', $row->currency);
        self::assertSame('2026-09-01', (string) $row->economic_date);
        self::assertSame('effective', $row->status);
    }

    public function test_second_operation_cannot_create_second_historical_entitlement(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        [, $obligationId] = $this->finalizedSource(
            $tenantId,
            $actor,
            $contractId,
            '2026-09-01',
        );

        $action = app(
            ActivateContractualBillingEntitlement::class,
        );

        $action->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Obligation already has historical Entitlement truth.',
        );

        $action->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_entitlement_cannot_activate_before_contractual_due_date(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        [, $obligationId] = $this->finalizedSource(
            $tenantId,
            $actor,
            $contractId,
            '2099-01-01',
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Entitlement cannot activate before contractual due date.',
        );

        app(ActivateContractualBillingEntitlement::class)
            ->execute(
                $tenantId,
                $obligationId,
                $actor,
                [
                    'billing_entitlement_operation_id' => (string) Str::ulid(),
                ],
            );
    }

    public function test_draft_schedule_cancellation_replays_and_preserves_obligation_history(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
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
                'contractual_reference' => 'Draft cancellation',
            ],
        );

        $operationId = (string) Str::ulid();

        $input = [
            'draft_cancellation_operation_id' => $operationId,
            'draft_cancellation_reason' => 'Draft source withdrawn',
            'draft_cancellation_reference' => 'CBS-DRAFT-VOID-1',
        ];

        $action = app(
            CancelDraftContractualBillingSchedule::class,
        );

        self::assertSame(
            $scheduleId,
            $action->execute(
                $tenantId,
                $scheduleId,
                $actor,
                $input,
            ),
        );

        self::assertSame(
            $scheduleId,
            $action->execute(
                $tenantId,
                $scheduleId,
                $actor,
                $input,
            ),
        );

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('cancelled', $schedule->status);
        self::assertSame(
            $operationId,
            $schedule->draft_cancellation_operation_id,
        );

        self::assertTrue(
            DB::table('contractual_billing_obligations')
                ->where('id', $obligationId)
                ->exists(),
        );
    }

    public function test_cancelled_draft_cannot_be_finalized(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
        );

        app(CancelDraftContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'draft_cancellation_operation_id' => (string) Str::ulid(),
                    'draft_cancellation_reason' => 'Withdrawn',
                ],
            );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Only a draft Schedule can be finalized.',
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
    }

    /**
     * @return array{string, User, string, string}
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
            (string) $customer->id,
            (string) $contract->id,
        ];
    }

    private function schedule(
        string $tenantId,
        User $actor,
        string $contractId,
    ): string {
        return app(CreateContractualBillingSchedule::class)
            ->execute(
                $tenantId,
                $actor,
                [
                    'contract_id' => $contractId,
                    'schedule_operation_id' => (string) Str::ulid(),
                ],
            );
    }

    /**
     * @return array{string,string}
     */
    private function finalizedSource(
        string $tenantId,
        User $actor,
        string $contractId,
        string $dueDate,
    ): array {
        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
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
                'contractual_due_date' => $dueDate,
                'contractual_reference' => 'Entitlement fixture',
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
