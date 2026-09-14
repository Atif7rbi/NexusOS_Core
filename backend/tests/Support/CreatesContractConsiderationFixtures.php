<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesContractConsiderationFixtures
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    protected function considerationContext(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'sold');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id, 'converted');
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actor->id, 'active', ['total_amount' => '1000.00']);

        return ['tenant_id' => $tenantId, 'actor' => $actor, 'contract_id' => (string) $contract->id,
            'customer_id' => (string) $customer->id, 'reservation_id' => (string) $reservation->id,
            'unit_id' => (string) $unit->id, 'operation_id' => (string) Str::ulid()];
    }

    protected function adoptionInput(array $context): array
    {
        return ['contract_id' => $context['contract_id'], 'coordination_adoption_operation_id' => $context['operation_id']];
    }

    protected function adopt(array $context): array
    {
        return app(AdoptContractConsideration::class)->execute($context['tenant_id'], $context['actor'], $this->adoptionInput($context));
    }

    protected function handoverSource(array $context): array
    {
        $evidenceId = (string) Str::ulid();
        $sourceId = (string) Str::ulid();
        $parents = array_intersect_key($context, array_flip(['tenant_id', 'contract_id', 'reservation_id', 'unit_id', 'customer_id']));
        DB::table('unit_handover_evidence')->insert($parents + [
            'id' => $evidenceId, 'handover_evidence_operation_id' => (string) Str::ulid(),
            'handover_evidence_reference' => 'CC/HANDOVER/'.$evidenceId,
            'readiness_reference' => 'CC/READY/'.$evidenceId, 'readiness_effective_date' => '2026-08-19',
            'acceptance_basis' => 'explicit_customer_acceptance', 'customer_acceptance_reference' => 'CC/ACCEPT/'.$evidenceId,
            'customer_acceptance_effective_date' => '2026-08-20', 'effective_date' => '2026-08-20',
            'recorded_by' => $context['actor']->id, 'recorded_at' => now(), 'status' => 'effective',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('unit_handover_acceptances')->insert($parents + [
            'id' => $sourceId, 'handover_evidence_id' => $evidenceId, 'handover_acceptance_operation_id' => (string) Str::ulid(),
            'performance_date' => '2026-08-20', 'performance_amount' => '1000.00', 'currency' => 'SAR',
            'status' => 'effective', 'created_by' => $context['actor']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $sourceId, 'evidence_id' => $evidenceId, 'source_type' => 'UNIT_HANDOVER_ACCEPTANCE',
            'economic_date' => '2026-08-20', 'amount' => '1000.00', 'semantic_precedence' => 10];
    }

    protected function billingObligations(array $context, array $amounts = ['400.00', '600.00'], string $date = '2026-08-21'): array
    {
        $schedule = app(CreateContractualBillingSchedule::class)->execute($context['tenant_id'], $context['actor'], [
            'contract_id' => $context['contract_id'], 'schedule_operation_id' => (string) Str::ulid(),
        ]);
        $ids = [];
        foreach ($amounts as $amount) {
            $ids[] = app(SaveDraftContractualBillingObligation::class)->execute($context['tenant_id'], $schedule, $context['actor'], [
                'obligation_operation_id' => (string) Str::ulid(), 'amount' => $amount,
                'contractual_due_date' => $date, 'contractual_reference' => 'CC/OBLIGATION/'.Str::ulid(),
            ]);
        }
        app(FinalizeContractualBillingSchedule::class)->execute($context['tenant_id'], $schedule, $context['actor'], [
            'finalization_operation_id' => (string) Str::ulid(),
        ]);

        return $ids;
    }

    /** Direct SQL fixture only: application source integration is deliberately bypassed. */
    protected function billingSource(array $context, string $obligationId): array
    {
        $obligation = DB::table('contractual_billing_obligations')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $obligationId)
            ->first();

        if ($obligation === null) {
            throw new \LogicException('Missing Contractual Billing Obligation fixture.');
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('contractual_billing_entitlements')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'billing_entitlement_operation_id' => (string) Str::ulid(),
            'schedule_id' => (string) $obligation->schedule_id,
            'obligation_id' => $obligationId,
            'contract_id' => $context['contract_id'],
            'customer_id' => $context['customer_id'],
            'amount' => (string) $obligation->amount,
            'currency' => (string) $obligation->currency,
            'economic_date' => (string) $obligation->contractual_due_date,
            'effective_at' => $now,
            'status' => 'effective',
            'recognized_by' => $context['actor']->id,
            'recognized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'source_type' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
            'economic_date' => (string) $obligation->contractual_due_date,
            'amount' => (string) $obligation->amount, 'semantic_precedence' => 20];
    }

    /** Direct SQL fixture only: application source integration is deliberately absent. */
    protected function transition(array $context, array $adoption, array $source, string $predecessorId,
        string $semanticPosition, array $overrides = []): array
    {
        $transitionId = (string) Str::ulid();
        $lotId = (string) Str::ulid();
        $edgeId = (string) Str::ulid();
        $owner = ['tenant_id' => $context['tenant_id'], 'contract_id' => $context['contract_id'], 'position_id' => $adoption['position_id']];
        DB::table('contract_consideration_transitions')->insert(array_replace($owner + [
            'id' => $transitionId, 'transition_operation_id' => (string) Str::ulid(),
            'source_type' => $source['source_type'], 'source_id' => $source['id'], 'economic_date' => $source['economic_date'],
            'semantic_precedence' => $source['semantic_precedence'], 'transition_amount' => $source['amount'], 'currency' => 'SAR',
            'status' => 'effective', 'created_by' => $context['actor']->id, 'created_at' => now(),
        ], $overrides));
        DB::table('contract_consideration_lots')->insert($owner + [
            'id' => $lotId, 'transition_id' => $transitionId, 'lot_kind' => 'TRANSITION_OUTPUT',
            'semantic_position' => $semanticPosition, 'amount' => $source['amount'], 'currency' => 'SAR', 'created_at' => now(),
        ]);
        DB::table('contract_consideration_transition_lots')->insert($owner + [
            'id' => $edgeId, 'transition_id' => $transitionId, 'lot_id' => $predecessorId, 'successor_lot_id' => $lotId,
            'consumed_amount' => $source['amount'], 'currency' => 'SAR', 'created_at' => now(),
        ]);

        return ['transition_id' => $transitionId, 'lot_id' => $lotId, 'edge_id' => $edgeId];
    }

    protected function reverseHandover(array $context, array $source, ?array $graph, array $transitionOverrides = []): void
    {
        $operation = (string) Str::ulid();
        $facts = ['status' => 'reversed', 'reversal_operation_id' => $operation, 'reversal_reason' => 'Correct source evidence',
            'reversal_reference' => 'CC/CORRECTION/'.$operation, 'reversed_by' => $context['actor']->id, 'reversed_at' => now()];
        if ($graph !== null) {
            DB::table('contract_consideration_transitions')->where('id', $graph['transition_id'])
                ->update(array_replace($facts, ['reversal_source_operation_id' => $operation], $transitionOverrides));
        }
        DB::table('unit_handover_acceptances')->where('id', $source['id'])->update($facts + ['updated_at' => now()]);
        DB::table('unit_handover_evidence')->where('id', $source['evidence_id'])->update($facts + ['updated_at' => now()]);
    }

    protected function reverseBilling(array $context, array $source, array $graph, array $transitionOverrides = []): void
    {
        $entitlement = DB::table('contractual_billing_entitlements')->where('id', $source['id'])->first();
        if ($entitlement === null) {
            throw new \LogicException('Missing Contractual Billing Entitlement fixture.');
        }

        $sourceCorrection = (string) Str::ulid();
        $reversalOperation = (string) Str::ulid();
        $reason = 'Correct billing source';
        $reference = 'CC/CORRECTION/'.$sourceCorrection;
        $reversedAt = now();
        DB::table('contract_consideration_transitions')->where('id', $graph['transition_id'])->update(array_replace([
            'status' => 'reversed', 'reversal_operation_id' => $reversalOperation,
            'reversal_source_operation_id' => $sourceCorrection, 'reversal_reason' => $reason,
            'reversal_reference' => $reference, 'reversed_by' => $context['actor']->id, 'reversed_at' => $reversedAt,
        ], $transitionOverrides));
        DB::table('contractual_billing_entitlements')->where('id', $source['id'])->update([
            'status' => 'reversed', 'reversal_operation_id' => $reversalOperation,
            'source_correction_operation_id' => $sourceCorrection, 'reversal_reason' => $reason,
            'source_rescission_reference' => $reference, 'reversed_by' => $context['actor']->id,
            'reversed_at' => $reversedAt, 'updated_at' => $reversedAt,
        ]);
        DB::table('contractual_billing_schedules')->where('id', $entitlement->schedule_id)->update([
            'status' => 'cancelled', 'source_correction_operation_id' => $sourceCorrection,
            'source_corrected_by' => $context['actor']->id, 'source_corrected_at' => $reversedAt,
            'source_correction_reason' => $reason, 'source_correction_reference' => $reference,
            'updated_at' => $reversedAt,
        ]);
    }

    protected function assertConsiderationSqlRejected(callable $callback, array $states = ['23514', '23503', '23505', '55000', '42501']): void
    {
        DB::beginTransaction();
        try {
            $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('PostgreSQL accepted inconsistent consideration history.');
        } catch (QueryException $exception) {
            self::assertContains($exception->errorInfo[0], $states, $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
