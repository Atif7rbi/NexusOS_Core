<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_link_schema_has_historical_uniqueness_and_deferred_final_state_guards(): void
    {
        foreach ([
            'entitlement_receivable_links_entitlement_history_unique',
            'entitlement_receivable_links_receivable_history_unique',
            'entitlement_receivable_links_operation_unique',
        ] as $constraint) {
            self::assertNotNull(DB::selectOne(
                'SELECT 1 FROM pg_catalog.pg_constraint WHERE conname = ?',
                [$constraint],
            ), "Missing PostgreSQL constraint [{$constraint}].");
        }

        $guards = DB::select(<<<'SQL'
            SELECT tgname, tgdeferrable, tginitdeferred
            FROM pg_catalog.pg_trigger
            WHERE tgname IN (
                'entitlement_receivable_links_final_state',
                'contractual_billing_entitlements_receivable_final_state',
                'receivables_entitlement_final_state',
                'contractual_billing_schedules_receivable_final_state'
            )
            ORDER BY tgname
            SQL);

        self::assertCount(4, $guards);

        foreach ($guards as $guard) {
            self::assertTrue($guard->tgdeferrable);
            self::assertTrue($guard->tginitdeferred);
        }
    }

    public function test_direct_sql_link_rejects_mismatched_receivable_canonical_facts(): void
    {
        [$tenantId, $actor, $customerId, $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        $receivableId = $this->insertReceivable(
            $tenantId,
            $actor->id,
            $customerId,
            $contractId,
            amount: '999.00',
        );

        $this->assertRejected(function () use (
            $tenantId,
            $actor,
            $entitlementId,
            $receivableId,
        ): void {
            DB::table('entitlement_receivable_links')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'entitlement_id' => $entitlementId,
                'receivable_id' => $receivableId,
                'receivable_establishment_operation_id' => DB::table('receivables')
                    ->where('id', $receivableId)
                    ->value('recognition_operation_id'),
                'source_correction_operation_id' => null,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_link_identity_and_deletion_are_immutable(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        app(EstablishEntitlementReceivable::class)->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );

        $linkId = (string) DB::table('entitlement_receivable_links')
            ->where('entitlement_id', $entitlementId)
            ->value('id');

        $this->assertRejected(
            fn () => DB::table('entitlement_receivable_links')
                ->where('id', $linkId)
                ->update([
                    'receivable_establishment_operation_id' => (string) Str::ulid(),
                    'updated_at' => now(),
                ]),
        );

        $this->assertRejected(
            fn () => DB::table('entitlement_receivable_links')
                ->where('id', $linkId)
                ->delete(),
        );
    }

    public function test_direct_sql_cannot_cancel_linked_receivable_without_source_correction(): void
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

        $this->assertRejected(
            fn () => DB::table('receivables')
                ->where('id', $receivableId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancellation_reason' => 'Illegal direct cancellation',
                    'updated_at' => now(),
                ]),
        );
    }

    public function test_deferred_guard_rejects_partial_link_correction_evidence(): void
    {
        [$tenantId, $actor, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement(
            $tenantId,
            $actor,
            $contractId,
        );

        app(EstablishEntitlementReceivable::class)->execute(
            $tenantId,
            $entitlementId,
            $actor,
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->assertRejectedDeferred(function () use ($entitlementId): void {
            DB::table('entitlement_receivable_links')
                ->where('entitlement_id', $entitlementId)
                ->update([
                    'source_correction_operation_id' => (string) Str::ulid(),
                    'updated_at' => now(),
                ]);
        });
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
                    'contractual_reference' => 'ERL integrity fixture',
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

    private function insertReceivable(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $contractId,
        string $amount,
    ): string {
        $id = (string) Str::ulid();

        DB::table('receivables')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'recognition_operation_id' => (string) Str::ulid(),
            'customer_id' => $customerId,
            'contract_id' => $contractId,
            'collection_id' => null,
            'currency' => 'SAR',
            'recognized_amount' => $amount,
            'due_date' => '2026-09-01',
            'status' => 'recognized',
            'recognized_at' => now(),
            'recognized_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assertRejected(callable $mutation): void
    {
        DB::beginTransaction();

        try {
            $mutation();
            self::fail('Invalid Entitlement Receivable direct SQL was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    private function assertRejectedDeferred(callable $mutation): void
    {
        DB::beginTransaction();

        try {
            $mutation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('Invalid Entitlement Receivable deferred state was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
