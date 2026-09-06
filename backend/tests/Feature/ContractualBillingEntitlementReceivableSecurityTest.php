<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableSecurityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_runtime_role_has_narrow_link_privileges_and_no_helper_execute(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            self::assertTrue((bool) DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) allowed',
                [$role, 'public.entitlement_receivable_links', $privilege],
            )->allowed);
        }

        self::assertFalse((bool) DB::selectOne(
            'SELECT has_table_privilege(?, ?, ?) allowed',
            [$role, 'public.entitlement_receivable_links', 'DELETE'],
        )->allowed);

        foreach ([
            'public.enforce_entitlement_receivable_link_history()',
            'public.guard_entitlement_linked_receivable_cancellation()',
            'public.guard_linked_entitlement_reversal()',
            'public.validate_entitlement_receivable_link_state(text,text,text)',
            'public.check_entitlement_receivable_link_final_state()',
        ] as $function) {
            self::assertFalse((bool) DB::selectOne(
                'SELECT has_function_privilege(?, ?, ?) allowed',
                [$role, $function, 'EXECUTE'],
            )->allowed, "Runtime role can execute protected function [{$function}].");
        }

        self::assertNull(DB::selectOne(
            "SELECT to_regprocedure('public.receivable_has_entitlement_link(text,text)') AS oid",
        )->oid);

        $owner = DB::selectOne(<<<'SQL'
            SELECT pg_catalog.pg_get_userbyid(relowner) AS name
            FROM pg_catalog.pg_class
            WHERE oid = 'public.entitlement_receivable_links'::regclass
            SQL)->name;

        self::assertNotSame($role, $owner);
    }

    public function test_runtime_role_can_execute_valid_unlinked_receivable_cancellation_without_helper_execute(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(RecognizeReceivableAction::class)->execute(
            $tenantId,
            $actor,
            [
                'recognition_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'contract_id' => $contractId,
                'collection_id' => null,
                'currency' => 'SAR',
                'recognized_amount' => '10.00',
                'due_date' => '2026-09-01',
                'recognized_at' => '2026-09-05T00:00:00+00:00',
            ],
        );

        $this->asRuntimeRole(function () use ($tenantId, $actorId, $receivableId): void {
            DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actorId,
                    'cancellation_reason' => 'Runtime unlinked cancellation proof',
                    'updated_at' => now(),
                ]);
        });

        self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'));
    }

    public function test_runtime_role_can_commit_coherent_linked_compound_correction_through_security_definer_guards(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute(
            $tenantId,
            $entitlementId,
            $actor,
            ['receivable_establishment_operation_id' => (string) Str::ulid()],
        );
        $correctionOperation = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();

        $this->asRuntimeRole(function () use (
            $tenantId,
            $actorId,
            $scheduleId,
            $entitlementId,
            $receivableId,
            $correctionOperation,
            $reversalOperation,
        ): void {
            $now = now();

            DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where('entitlement_id', $entitlementId)
                ->update([
                    'source_correction_operation_id' => $correctionOperation,
                    'updated_at' => $now,
                ]);

            DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                    'cancelled_by' => $actorId,
                    'cancellation_reason' => 'Runtime linked correction proof',
                    'updated_at' => $now,
                ]);

            DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $reversalOperation,
                    'reversed_by' => $actorId,
                    'reversed_at' => $now,
                    'reversal_reason' => 'Runtime linked correction proof',
                    'source_correction_operation_id' => $correctionOperation,
                    'source_rescission_reference' => 'ERL-IMPL-001',
                    'updated_at' => $now,
                ]);

            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $scheduleId)
                ->update([
                    'status' => 'cancelled',
                    'source_correction_operation_id' => $correctionOperation,
                    'source_corrected_by' => $actorId,
                    'source_corrected_at' => $now,
                    'source_correction_reason' => 'Runtime linked correction proof',
                    'source_correction_reference' => 'ERL-IMPL-001',
                    'updated_at' => $now,
                ]);
        });

        self::assertSame('cancelled', DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('status'));
        self::assertSame('reversed', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'));
        self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertSame(
            $correctionOperation,
            DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'),
        );
    }

    public function test_runtime_role_cannot_directly_execute_protected_integrity_helper(): void
    {
        $caught = null;

        try {
            $this->asRuntimeRole(function (): void {
                DB::select("SELECT public.validate_entitlement_receivable_link_state('x','y','z')");
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame('42501', (string) ($caught->errorInfo[0] ?? ''));
    }

    /** @return array{string,int,string,string} */
    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        DB::table('tenants')->where('id', $tenantId)->update([
            'timezone' => 'Asia/Riyadh',
            'currency' => 'SAR',
            'updated_at' => now(),
        ]);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'reserved');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id);
        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            ['total_amount' => '1000.00', 'currency' => 'SAR'],
        );

        return [$tenantId, $actor->id, (string) $customer->id, (string) $contract->id];
    }

    /** @return array{string,string,string} */
    private function effectiveEntitlement(string $tenantId, int $actorId, string $contractId): array
    {
        $actor = User::query()->findOrFail($actorId);
        $scheduleId = app(CreateContractualBillingSchedule::class)->execute(
            $tenantId,
            $actor,
            ['contract_id' => $contractId, 'schedule_operation_id' => (string) Str::ulid()],
        );
        $obligationId = app(SaveDraftContractualBillingObligation::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => (string) Str::ulid(),
                'amount' => '1000.00',
                'contractual_due_date' => '2026-09-01',
                'contractual_reference' => 'ERL runtime security fixture',
            ],
        );
        app(FinalizeContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            ['finalization_operation_id' => (string) Str::ulid()],
        );
        $entitlementId = app(ActivateContractualBillingEntitlement::class)->execute(
            $tenantId,
            $obligationId,
            $actor,
            ['billing_entitlement_operation_id' => (string) Str::ulid()],
        );

        return [$scheduleId, $obligationId, $entitlementId];
    }

    private function asRuntimeRole(callable $callback): mixed
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new \RuntimeException('Invalid ACCOUNTING_RUNTIME_DB_ROLE.');
        }
        $identifier = '"'.str_replace('"', '""', $role).'"';

        return DB::transaction(function () use ($identifier, $callback): mixed {
            DB::statement("SET LOCAL ROLE {$identifier}");

            return $callback();
        });
    }
}
