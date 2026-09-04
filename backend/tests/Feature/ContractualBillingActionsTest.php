<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\RemoveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingAccessDenied;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingActionsTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_schedule_creation_same_operation_same_facts_replays(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        $operationId = (string) Str::ulid();

        $input = [
            'contract_id' => $contractId,
            'schedule_operation_id' => $operationId,
        ];

        $action = app(CreateContractualBillingSchedule::class);

        $first = $action->execute(
            $tenantId,
            $actor,
            $input,
        );

        $second = $action->execute(
            $tenantId,
            $actor,
            $input,
        );

        self::assertSame($first, $second);

        self::assertSame(
            1,
            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('schedule_operation_id', $operationId)
                ->count(),
        );

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $first)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('draft', $schedule->status);
        self::assertSame($contractId, $schedule->contract_id);
        self::assertSame(
            'fixed_date_unconditional_full_schedule',
            $schedule->billing_model,
        );
    }

    public function test_schedule_operation_reuse_with_different_contract_conflicts(): void
    {
        [$tenantId, $actor, , $firstContractId] = $this->context();

        [, , , $secondContractId] = $this->secondContract(
            $tenantId,
            $actor,
        );

        $operationId = (string) Str::ulid();

        $action = app(CreateContractualBillingSchedule::class);

        $action->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $firstContractId,
                'schedule_operation_id' => $operationId,
            ],
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Schedule operation identity was reused with different facts.',
        );

        $action->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $secondContractId,
                'schedule_operation_id' => $operationId,
            ],
        );
    }

    public function test_draft_obligation_create_edit_and_same_facts_replay(): void
    {
        [$tenantId, $actor, $customerId, $contractId] = $this->context();

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
        );

        $operationId = (string) Str::ulid();

        $action = app(SaveDraftContractualBillingObligation::class);

        $first = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => $operationId,
                'amount' => '400.00',
                'contractual_due_date' => '2026-09-10',
                'contractual_reference' => 'First billing line',
            ],
        );

        $replay = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => $operationId,
                'amount' => '400.00',
                'contractual_due_date' => '2026-09-10',
                'contractual_reference' => 'First billing line',
            ],
        );

        self::assertSame($first, $replay);

        $edited = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_id' => $first,
                'obligation_operation_id' => $operationId,
                'amount' => '450.00',
                'contractual_due_date' => '2026-09-15',
                'contractual_reference' => 'Edited billing line',
            ],
        );

        self::assertSame($first, $edited);

        $row = DB::table('contractual_billing_obligations')
            ->where('id', $first)
            ->first();

        self::assertNotNull($row);
        self::assertSame($customerId, $row->customer_id);
        self::assertSame('450.00', (string) $row->amount);
        self::assertSame(
            '2026-09-15',
            (string) $row->contractual_due_date,
        );
        self::assertSame(
            'Edited billing line',
            $row->contractual_reference,
        );
        self::assertSame('included', $row->draft_membership_status);
    }

    public function test_removed_draft_obligation_is_irreversible_and_removal_replays_same_facts(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
        );

        $obligationOperationId = (string) Str::ulid();

        $save = app(SaveDraftContractualBillingObligation::class);

        $obligationId = $save->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => $obligationOperationId,
                'amount' => '1000.00',
                'contractual_due_date' => '2026-09-10',
                'contractual_reference' => 'Removal fixture',
            ],
        );

        $removalOperationId = (string) Str::ulid();

        $remove = app(
            RemoveDraftContractualBillingObligation::class,
        );

        $remove->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'removal_operation_id' => $removalOperationId,
                'removal_reason' => 'Draft correction',
            ],
        );

        $remove->execute(
            $tenantId,
            $obligationId,
            $actor,
            [
                'removal_operation_id' => $removalOperationId,
                'removal_reason' => 'Draft correction',
            ],
        );

        $removed = DB::table('contractual_billing_obligations')
            ->where('id', $obligationId)
            ->first();

        self::assertNotNull($removed);
        self::assertSame(
            'removed',
            $removed->draft_membership_status,
        );
        self::assertSame(
            $removalOperationId,
            $removed->removal_operation_id,
        );
        self::assertSame(
            'Draft correction',
            $removed->removal_reason,
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Removed draft Obligations cannot be edited.',
        );

        $save->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_id' => $obligationId,
                'obligation_operation_id' => $obligationOperationId,
                'amount' => '1000.00',
                'contractual_due_date' => '2026-09-20',
                'contractual_reference' => 'Illegal resurrection',
            ],
        );
    }

    public function test_finalization_snapshots_timezone_and_is_same_operation_replay(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'timezone' => 'Asia/Riyadh',
                'updated_at' => now(),
            ]);

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
        );

        app(SaveDraftContractualBillingObligation::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'obligation_operation_id' => (string) Str::ulid(),
                    'amount' => '1000.00',
                    'contractual_due_date' => '2026-09-10',
                    'contractual_reference' => 'Full contractual amount',
                ],
            );

        $operationId = (string) Str::ulid();

        $action = app(FinalizeContractualBillingSchedule::class);

        $first = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'finalization_operation_id' => $operationId,
            ],
        );

        $second = $action->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'finalization_operation_id' => $operationId,
            ],
        );

        self::assertSame($scheduleId, $first);
        self::assertSame($scheduleId, $second);

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('finalized', $schedule->status);
        self::assertSame(
            'Asia/Riyadh',
            $schedule->contractual_timezone,
        );
        self::assertSame(
            $operationId,
            $schedule->finalization_operation_id,
        );
        self::assertSame($actor->id, $schedule->finalized_by);
    }

    public function test_finalization_rejects_incomplete_contract_capacity_without_mutating_schedule(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        $scheduleId = $this->schedule(
            $tenantId,
            $actor,
            $contractId,
        );

        app(SaveDraftContractualBillingObligation::class)
            ->execute(
                $tenantId,
                $scheduleId,
                $actor,
                [
                    'obligation_operation_id' => (string) Str::ulid(),
                    'amount' => '900.00',
                    'contractual_due_date' => '2026-09-10',
                    'contractual_reference' => 'Incomplete contractual capacity',
                ],
            );

        try {
            app(FinalizeContractualBillingSchedule::class)
                ->execute(
                    $tenantId,
                    $scheduleId,
                    $actor,
                    [
                        'finalization_operation_id' => (string) Str::ulid(),
                    ],
                );

            self::fail(
                'Expected incomplete Schedule finalization to fail.',
            );
        } catch (ContractualBillingConflict $exception) {
            self::assertSame(
                'Schedule total must equal Contract total_amount.',
                $exception->getMessage(),
            );
        }

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('draft', $schedule->status);
        self::assertNull($schedule->finalization_operation_id);
        self::assertNull($schedule->contractual_timezone);
    }

    public function test_transactional_authorization_rejects_paused_membership_before_billing_mutation(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();

        DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->update([
                'status' => TenantUser::STATUS_PAUSED,
                'updated_at' => now(),
            ]);

        $this->expectException(ContractualBillingAccessDenied::class);

        app(CreateContractualBillingSchedule::class)
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

    /**
     * @return array{string, User, string, string}
     */
    private function secondContract(
        string $tenantId,
        User $actor,
    ): array {
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
}
