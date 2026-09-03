<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateSuccessorContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Actions\SupersedeFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingSuccessorCorrectionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_successor_correction_atomically_supersedes_source_reverses_entitlement_and_finalizes_successor(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$sourceId, $sourceObligationId] =
            $this->finalizedSource($tenantId, $actor, $contractId);

        $entitlementId = app(
            ActivateContractualBillingEntitlement::class,
        )->execute(
            $tenantId,
            $sourceObligationId,
            $actor,
            [
                'billing_entitlement_operation_id' => (string) Str::ulid(),
            ],
        );

        $successorId = $this->successor(
            $tenantId,
            $actor,
            $sourceId,
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
                    'contractual_reference' => 'Corrected successor obligation',
                ],
            );

        $correctionOperation = (string) Str::ulid();
        $finalizationOperation = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();

        $result = app(
            SupersedeFinalizedContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $sourceId,
            $successorId,
            $actor,
            [
                'source_correction_operation_id' => $correctionOperation,
                'successor_finalization_operation_id' => $finalizationOperation,
                'source_correction_reason' => 'Correct contractual billing dates',
                'source_correction_reference' => 'CBS-SUCCESSOR-1',
                'entitlement_reversals' => [
                    $entitlementId => $reversalOperation,
                ],
            ],
        );

        self::assertSame($successorId, $result);

        self::assertSame(
            'superseded',
            DB::table('contractual_billing_schedules')
                ->where('id', $sourceId)
                ->value('status'),
        );

        $successor = DB::table('contractual_billing_schedules')
            ->where('id', $successorId)
            ->first();

        self::assertNotNull($successor);
        self::assertSame('finalized', $successor->status);
        self::assertSame($sourceId, $successor->replaces_schedule_id);
        self::assertSame(
            $finalizationOperation,
            $successor->finalization_operation_id,
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
    }

    public function test_successor_supersession_same_facts_replays(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$sourceId] =
            $this->finalizedSource($tenantId, $actor, $contractId);

        $successorId = $this->successor(
            $tenantId,
            $actor,
            $sourceId,
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
                    'contractual_reference' => 'Successor replay',
                ],
            );

        $input = [
            'source_correction_operation_id' => (string) Str::ulid(),
            'successor_finalization_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'Correct source',
            'entitlement_reversals' => [],
        ];

        $action = app(
            SupersedeFinalizedContractualBillingSchedule::class,
        );

        $first = $action->execute(
            $tenantId,
            $sourceId,
            $successorId,
            $actor,
            $input,
        );

        $second = $action->execute(
            $tenantId,
            $sourceId,
            $successorId,
            $actor,
            $input,
        );

        self::assertSame($successorId, $first);
        self::assertSame($first, $second);
    }

    public function test_incomplete_successor_rolls_back_without_superseding_source(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$sourceId] =
            $this->finalizedSource($tenantId, $actor, $contractId);

        $successorId = $this->successor(
            $tenantId,
            $actor,
            $sourceId,
        );

        app(SaveDraftContractualBillingObligation::class)
            ->execute(
                $tenantId,
                $successorId,
                $actor,
                [
                    'obligation_operation_id' => (string) Str::ulid(),
                    'amount' => '900.00',
                    'contractual_due_date' => '2026-10-01',
                    'contractual_reference' => 'Incomplete successor',
                ],
            );

        try {
            app(SupersedeFinalizedContractualBillingSchedule::class)
                ->execute(
                    $tenantId,
                    $sourceId,
                    $successorId,
                    $actor,
                    [
                        'source_correction_operation_id' => (string) Str::ulid(),
                        'successor_finalization_operation_id' => (string) Str::ulid(),
                        'source_correction_reason' => 'Invalid correction',
                        'entitlement_reversals' => [],
                    ],
                );

            self::fail('Expected incomplete successor rejection.');
        } catch (ContractualBillingConflict $exception) {
            self::assertSame(
                'Successor total must equal Contract total_amount.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'finalized',
            DB::table('contractual_billing_schedules')
                ->where('id', $sourceId)
                ->value('status'),
        );

        self::assertSame(
            'draft',
            DB::table('contractual_billing_schedules')
                ->where('id', $successorId)
                ->value('status'),
        );
    }

    public function test_only_one_live_successor_candidate_is_allowed_by_application(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        [$sourceId] =
            $this->finalizedSource($tenantId, $actor, $contractId);

        $this->successor($tenantId, $actor, $sourceId);

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Source Schedule already has a live successor candidate.',
        );

        $this->successor($tenantId, $actor, $sourceId);
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

        return [$tenantId, $actor, (string) $contract->id];
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
                'contractual_reference' => 'Source',
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

    private function successor(
        string $tenantId,
        User $actor,
        string $sourceId,
    ): string {
        return app(
            CreateSuccessorContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $sourceId,
            $actor,
            [
                'schedule_operation_id' => (string) Str::ulid(),
            ],
        );
    }
}
