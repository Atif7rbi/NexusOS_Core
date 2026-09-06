<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CorrectFinalizedContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingAccessDenied;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_establishment_derives_exact_receivable_facts_and_replays(): void
    {
        [$tenantId, $actor, $customerId, $contractId] = $this->context();
        [$scheduleId, $obligationId, $entitlementId] =
            $this->effectiveEntitlement(
                $tenantId,
                $actor,
                $contractId,
            );

        $operationId = (string) Str::ulid();
        $action = app(EstablishEntitlementReceivable::class);

        $first = $action->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => $operationId,
            ],
        );

        $second = $action->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => $operationId,
            ],
        );

        self::assertSame($first, $second);

        $receivable = DB::table('receivables')
            ->where('id', $first)
            ->first();
        $link = DB::table('entitlement_receivable_links')
            ->where('entitlement_id', $entitlementId)
            ->first();

        self::assertNotNull($receivable);
        self::assertNotNull($link);
        self::assertSame($operationId, $receivable->recognition_operation_id);
        self::assertSame($operationId, $link->receivable_establishment_operation_id);
        self::assertSame($tenantId, $receivable->tenant_id);
        self::assertSame($customerId, $receivable->customer_id);
        self::assertSame($contractId, $receivable->contract_id);
        self::assertNull($receivable->collection_id);
        self::assertSame('1000.00', (string) $receivable->recognized_amount);
        self::assertSame('SAR', $receivable->currency);
        self::assertSame('2026-09-01', (string) $receivable->due_date);
        self::assertSame('recognized', $receivable->status);
        self::assertSame($entitlementId, $link->entitlement_id);
        self::assertSame($first, $link->receivable_id);
        self::assertNull($link->source_correction_operation_id);

        self::assertSame(
            $scheduleId,
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('schedule_id'),
        );
        self::assertSame(
            $obligationId,
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('obligation_id'),
        );
    }

    public function test_historically_consumed_entitlement_rejects_second_operation(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $action = app(EstablishEntitlementReceivable::class);

        $action->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(ContractualBillingConflict::class);
        $this->expectExceptionMessage(
            'Receivable establishment operation conflicts with historical Entitlement consumption.',
        );

        $action->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_accountant_receivables_authority_cannot_consume_entitlement(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $accountant = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_ACCOUNTANT,
        ]);

        TenantUser::factory()
            ->forTenant(Tenant::query()->findOrFail($tenantId))
            ->forUser($accountant)
            ->active()
            ->create();

        $this->expectException(ContractualBillingAccessDenied::class);

        app(EstablishEntitlementReceivable::class)->execute(
            $tenantId,
            $entitlementId,
            $accountant,
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    public function test_ordinary_cancellation_rejects_entitlement_backed_receivable(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $receivableId = app(EstablishEntitlementReceivable::class)
            ->execute(
                $tenantId,
                $entitlementId,
                $actor,
                [
                    'receivable_establishment_operation_id' => (string) Str::ulid(),
                ],
            );

        $this->expectException(ReceivablesConflict::class);
        $this->expectExceptionMessage(
            'Entitlement-backed Receivable can only be cancelled by Contractual Billing source correction.',
        );

        app(CancelReceivableAction::class)->execute(
            $tenantId,
            $receivableId,
            $actor,
            '2026-09-05T00:00:00+00:00',
            'Invalid standalone cancellation',
        );
    }

    public function test_source_correction_cancels_linked_receivable_and_historical_establishment_still_replays(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $establishmentOperation = (string) Str::ulid();
        $establish = app(EstablishEntitlementReceivable::class);

        $receivableId = $establish->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => $establishmentOperation,
            ],
        );

        $correctionOperation = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();

        app(CorrectFinalizedContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'source_correction_operation_id' => $correctionOperation,
                'source_correction_reason' => 'Contractual source rescinded',
                'source_correction_reference' => 'ERL-CORRECTION-1',
                'entitlement_reversals' => [
                    $entitlementId => $reversalOperation,
                ],
            ],
        );

        self::assertSame(
            'reversed',
            DB::table('contractual_billing_entitlements')
                ->where('id', $entitlementId)
                ->value('status'),
        );
        self::assertSame(
            'cancelled',
            DB::table('receivables')
                ->where('id', $receivableId)
                ->value('status'),
        );
        self::assertSame(
            $correctionOperation,
            DB::table('entitlement_receivable_links')
                ->where('entitlement_id', $entitlementId)
                ->value('source_correction_operation_id'),
        );

        $replay = $establish->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => $establishmentOperation,
            ],
        );

        self::assertSame($receivableId, $replay);
        self::assertSame(
            1,
            DB::table('entitlement_receivable_links')
                ->where('entitlement_id', $entitlementId)
                ->count(),
        );
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
     * @return array{string,string,string}
     */
    private function effectiveEntitlement(
        string $tenantId,
        User $actor,
        string $contractId,
    ): array {
        $scheduleId = app(CreateContractualBillingSchedule::class)
            ->execute(
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
                    'contractual_reference' => 'Entitlement Receivable fixture',
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
