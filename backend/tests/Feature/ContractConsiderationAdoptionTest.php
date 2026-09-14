<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use App\Modules\Contracts\Models\Contract;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractConsiderationAdoptionTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_clean_contract_adoption_atomically_creates_adoption_position_and_genesis(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();
        $operationId = (string) Str::ulid();

        $adoptionId = app(AdoptContractConsideration::class)->execute(
            $tenantId,
            $contractId,
            $actor,
            ['coordination_adoption_operation_id' => $operationId],
        );

        $adoption = DB::table('contract_consideration_adoptions')
            ->where('id', $adoptionId)
            ->first();

        self::assertNotNull($adoption);
        self::assertSame($tenantId, $adoption->tenant_id);
        self::assertSame($contractId, $adoption->contract_id);
        self::assertSame($operationId, $adoption->coordination_adoption_operation_id);
        self::assertSame('450000.00', (string) $adoption->consideration_amount);
        self::assertSame('SAR', $adoption->currency);
        self::assertSame('CLEAN_NO_PRIOR_SUPPORTED_SOURCES', $adoption->adoption_basis);
        self::assertSame('CONTRACT_CONSIDERATION_V1', $adoption->coordination_scope_version);
        self::assertSame('ADOPTED', $adoption->status);

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->first();

        self::assertNotNull($position);
        self::assertSame($adoptionId, $position->adoption_id);
        self::assertSame('450000.00', (string) $position->consideration_amount);
        self::assertSame('450000.00', (string) $position->source_contract_total_snapshot);
        self::assertSame('SAR', $position->currency);

        $genesis = DB::table('contract_consideration_lots')
            ->where('tenant_id', $tenantId)
            ->where('position_root_id', $position->id)
            ->first();

        self::assertNotNull($genesis);
        self::assertSame('GENESIS', $genesis->lot_kind);
        self::assertSame('UNPERFORMED_UNBILLED', $genesis->semantic_position);
        self::assertSame('450000.00', (string) $genesis->amount);
        self::assertSame('SAR', $genesis->currency);
    }

    public function test_same_operation_replays_exact_committed_adoption_without_new_rows(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();
        $operationId = (string) Str::ulid();

        $first = $this->adopt($tenantId, $actor, $contractId, $operationId);
        $second = $this->adopt($tenantId, $actor, $contractId, $operationId);

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('contract_consideration_adoptions')->where('contract_id', $contractId)->count());
        self::assertSame(1, DB::table('contract_consideration_positions')->where('contract_id', $contractId)->count());
        self::assertSame(1, DB::table('contract_consideration_lots')->where('contract_id', $contractId)->count());
    }

    public function test_different_operation_against_adopted_contract_fails_closed(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());

        $this->expectException(ContractConsiderationConflict::class);

        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());
    }

    public function test_same_operation_cannot_be_reused_for_different_contract(): void
    {
        [$tenantId, $actor, $contractA] = $this->context();
        $contractB = $this->secondContract($tenantId, $actor);
        $operationId = (string) Str::ulid();

        $this->adopt($tenantId, $actor, $contractA, $operationId);

        $this->expectException(ContractConsiderationConflict::class);

        $this->adopt($tenantId, $actor, $contractB, $operationId);
    }

    public function test_clean_adoption_rejects_any_prior_billing_entitlement_history(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();
        $this->activateBillingEntitlement($tenantId, $actor, $contractId);

        $this->expectException(ContractConsiderationConflict::class);

        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());
    }

    public function test_clean_adoption_rejects_any_prior_handover_acceptance_history(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();
        $evidenceId = app(RecordUnitHandoverEvidence::class)->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'CCA/ADOPTION/'.Str::ulid(),
                'readiness_reference' => 'CCA/READY',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'CCA/ACCEPT',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );

        app(CreateUnitHandoverAcceptance::class)->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );

        $this->expectException(ContractConsiderationConflict::class);

        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());
    }

    public function test_non_sar_contract_is_not_eligible_for_v1_adoption(): void
    {
        [$tenantId, $actor] = $this->context();
        $contractId = $this->usdContract($tenantId, $actor);

        $this->expectException(ContractConsiderationConflict::class);

        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());
    }

    public function test_contract_capacity_cannot_change_after_adoption(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();
        $this->adopt($tenantId, $actor, $contractId, (string) Str::ulid());

        try {
            DB::table('contracts')
                ->where('id', $contractId)
                ->update([
                    'total_amount' => '460000.00',
                    'updated_at' => now(),
                ]);

            self::fail('Expected PostgreSQL to reject adopted Contract capacity mutation.');
        } catch (QueryException $exception) {
            self::assertSame('55000', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    public function test_adoption_operation_id_must_be_caller_supplied_ulid(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        $this->expectException(ContractConsiderationValidationFailed::class);

        app(AdoptContractConsideration::class)->execute(
            $tenantId,
            $contractId,
            $actor,
            ['coordination_adoption_operation_id' => 'not-a-ulid'],
        );
    }

    private function adopt(
        string $tenantId,
        User $actor,
        string $contractId,
        string $operationId,
    ): string {
        return app(AdoptContractConsideration::class)->execute(
            $tenantId,
            $contractId,
            $actor,
            ['coordination_adoption_operation_id' => $operationId],
        );
    }

    /** @return array{string,User,string} */
    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);

        return [$tenantId, $actor, $this->secondContract($tenantId, $actor)];
    }

    private function secondContract(string $tenantId, User $actor): string
    {
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'sold');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actor->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            ['total_amount' => '450000.00'],
        );

        return (string) $contract->id;
    }

    private function usdContract(string $tenantId, User $actor): string
    {
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'sold');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actor->id,
            'converted',
        );

        $contract = new Contract([
            'tenant_id' => $tenantId,
            'reservation_id' => (string) $reservation->id,
            'status' => 'active',
            'total_amount' => '450000.00',
            'activated_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $contract->currency = 'USD';
        $contract->save();

        return (string) $contract->id;
    }

    private function activateBillingEntitlement(
        string $tenantId,
        User $actor,
        string $contractId,
    ): string {
        $scheduleId = app(CreateContractualBillingSchedule::class)->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'schedule_operation_id' => (string) Str::ulid(),
            ],
        );

        $obligationId = app(SaveDraftContractualBillingObligation::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'obligation_operation_id' => (string) Str::ulid(),
                'amount' => '450000.00',
                'contractual_due_date' => '2026-09-01',
                'contractual_reference' => 'CCA adoption history test',
            ],
        );

        app(FinalizeContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            ['finalization_operation_id' => (string) Str::ulid()],
        );

        return app(ActivateContractualBillingEntitlement::class)->execute(
            $tenantId,
            $obligationId,
            $actor,
            ['billing_entitlement_operation_id' => (string) Str::ulid()],
        );
    }
}
