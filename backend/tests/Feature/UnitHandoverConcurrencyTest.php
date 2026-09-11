<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\UnitHandover\Actions\CreateUnitHandoverAcceptance;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use App\Modules\UnitHandover\Exceptions\UnitHandoverAccessDenied;
use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class UnitHandoverConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_h1_same_evidence_operation_converges_to_one_historical_truth(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $operationId = (string) Str::ulid();
        $reference = 'HANDOVER/H1-'.Str::ulid();

        $barrier = $this->barrierDirectory('h1');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h1_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $workerA = $this->startWorker(
            $this->evidencePayload(
                applicationName: 'uh_h1_worker_a',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                contractId: $contractId,
                operationId: $operationId,
                reference: $reference,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h1_worker_a',
            'uh_h1_contract_holder',
        );

        $workerB = $this->startWorker(
            $this->evidencePayload(
                applicationName: 'uh_h1_worker_b',
                tenantId: $tenantId,
                actorId: (int) $actorB->id,
                contractId: $contractId,
                operationId: $operationId,
                reference: $reference,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h1_worker_b',
            'uh_h1_worker_a',
        );

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $resultA['ok'],
            json_encode($resultA),
        );

        self::assertTrue(
            $resultB['ok'],
            json_encode($resultB),
        );

        self::assertSame(
            $resultA['evidence_id'],
            $resultB['evidence_id'],
        );

        self::assertSame(
            1,
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_operation_id',
                    $operationId,
                )
                ->count(),
        );

        self::assertSame(
            1,
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_reference',
                    $reference,
                )
                ->count(),
        );
    }

    public function test_h2_competing_operations_for_same_external_identity_have_one_winner(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $operationA = (string) Str::ulid();
        $operationB = (string) Str::ulid();

        $reference = 'HANDOVER/H2-'.Str::ulid();

        $barrier = $this->barrierDirectory('h2');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h2_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $workerA = $this->startWorker(
            $this->evidencePayload(
                applicationName: 'uh_h2_worker_a',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                contractId: $contractId,
                operationId: $operationA,
                reference: $reference,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h2_worker_a',
            'uh_h2_contract_holder',
        );

        $workerB = $this->startWorker(
            $this->evidencePayload(
                applicationName: 'uh_h2_worker_b',
                tenantId: $tenantId,
                actorId: (int) $actorB->id,
                contractId: $contractId,
                operationId: $operationB,
                reference: $reference,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h2_worker_b',
            'uh_h2_worker_a',
        );

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $resultA['ok'],
            json_encode($resultA),
        );

        self::assertFalse(
            $resultB['ok'],
            json_encode($resultB),
        );

        self::assertSame(
            UnitHandoverConflict::class,
            $resultB['class'],
        );

        self::assertSame(
            1,
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_reference',
                    $reference,
                )
                ->count(),
        );

        $row = DB::table('unit_handover_evidence')
            ->where('tenant_id', $tenantId)
            ->where(
                'handover_evidence_reference',
                $reference,
            )
            ->first();

        self::assertNotNull($row);

        self::assertSame(
            $operationA,
            $row->handover_evidence_operation_id,
        );

        self::assertSame(
            $resultA['evidence_id'],
            $row->id,
        );

        self::assertSame(
            0,
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_operation_id',
                    $operationB,
                )
                ->count(),
        );
    }

    public function test_h3_same_acceptance_operation_converges_to_one_historical_truth(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H3-'.Str::ulid(),
        );

        $operationId = (string) Str::ulid();

        $barrier = $this->barrierDirectory('h3');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h3_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $workerA = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h3_worker_a',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: $operationId,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h3_worker_a',
            'uh_h3_contract_holder',
        );

        $workerB = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h3_worker_b',
                tenantId: $tenantId,
                actorId: (int) $actorB->id,
                evidenceId: $evidenceId,
                operationId: $operationId,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h3_worker_b',
            'uh_h3_worker_a',
        );

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $resultA['ok'],
            json_encode($resultA),
        );

        self::assertTrue(
            $resultB['ok'],
            json_encode($resultB),
        );

        self::assertSame(
            $resultA['acceptance_id'],
            $resultB['acceptance_id'],
        );

        self::assertSame(
            1,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_acceptance_operation_id',
                    $operationId,
                )
                ->count(),
        );

        self::assertSame(
            1,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_id',
                    $evidenceId,
                )
                ->count(),
        );

        $row = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where(
                'handover_acceptance_operation_id',
                $operationId,
            )
            ->first();

        self::assertNotNull($row);

        self::assertSame(
            $resultA['acceptance_id'],
            $row->id,
        );

        self::assertSame(
            'effective',
            $row->status,
        );
    }

    public function test_h4_competing_acceptances_for_one_contract_have_one_winner(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceA = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H4-A-'.Str::ulid(),
        );

        $evidenceB = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H4-B-'.Str::ulid(),
        );

        $operationA = (string) Str::ulid();
        $operationB = (string) Str::ulid();

        $barrier = $this->barrierDirectory('h4');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h4_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $workerA = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h4_worker_a',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceA,
                operationId: $operationA,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h4_worker_a',
            'uh_h4_contract_holder',
        );

        $workerB = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h4_worker_b',
                tenantId: $tenantId,
                actorId: (int) $actorB->id,
                evidenceId: $evidenceB,
                operationId: $operationB,
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h4_worker_b',
            'uh_h4_worker_a',
        );

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $resultA['ok'],
            json_encode($resultA),
        );

        self::assertFalse(
            $resultB['ok'],
            json_encode($resultB),
        );

        self::assertSame(
            UnitHandoverConflict::class,
            $resultB['class'],
        );

        self::assertSame(
            1,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->where('status', 'effective')
                ->count(),
        );

        $winner = DB::table('unit_handover_acceptances')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->where('status', 'effective')
            ->first();

        self::assertNotNull($winner);

        self::assertSame(
            $evidenceA,
            $winner->handover_evidence_id,
        );

        self::assertSame(
            $operationA,
            $winner->handover_acceptance_operation_id,
        );

        self::assertSame(
            $resultA['acceptance_id'],
            $winner->id,
        );

        self::assertSame(
            0,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_id',
                    $evidenceB,
                )
                ->count(),
        );

        self::assertSame(
            0,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_acceptance_operation_id',
                    $operationB,
                )
                ->count(),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceB)
                ->value('status'),
        );
    }

    public function test_h5_acceptance_serializes_against_contract_economic_mutation(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H5-'.Str::ulid(),
        );

        $barrier = $this->barrierDirectory('h5');

        $mutator = $this->startWorker([
            'action' => 'hold_contract_then_update_total',
            'application_name' => 'uh_h5_contract_mutator',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'total_amount' => '999999.00',
            'ready_file' => $barrier.'/mutator_ready',
            'release_file' => $barrier.'/mutator_release',
        ]);

        $this->waitForFiles([
            $barrier.'/mutator_ready',
        ]);

        $acceptance = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h5_acceptance',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: (string) Str::ulid(),
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h5_acceptance',
            'uh_h5_contract_mutator',
        );

        touch($barrier.'/mutator_release');

        $mutatorResult =
            $this->finishWorker($mutator);

        $acceptanceResult =
            $this->finishWorker($acceptance);

        self::assertFalse(
            $mutatorResult['ok'],
            json_encode($mutatorResult),
        );

        self::assertSame(
            '55000',
            $mutatorResult['sqlstate'],
        );

        self::assertTrue(
            $acceptanceResult['ok'],
            json_encode($acceptanceResult),
        );

        $contract = DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->first();

        self::assertNotNull($contract);

        self::assertSame(
            '450000.00',
            (string) $contract->total_amount,
        );

        self::assertSame(
            'SAR',
            $contract->currency,
        );

        $acceptanceRow =
            DB::table('unit_handover_acceptances')
                ->where(
                    'id',
                    $acceptanceResult['acceptance_id'],
                )
                ->first();

        self::assertNotNull($acceptanceRow);

        self::assertSame(
            '450000.00',
            (string) $acceptanceRow->performance_amount,
        );

        self::assertSame(
            'SAR',
            $acceptanceRow->currency,
        );

        /*
         * Currency is independently immutable at PostgreSQL level.
         * H5 therefore races the mutable economic amount while also
         * proving currency cannot be rewritten after construction.
         */
        $currencyState = null;

        try {
            DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->update([
                    'currency' => 'USD',
                    'updated_at' => now(),
                ]);
        } catch (QueryException $exception) {
            $currencyState = (string) (
                $exception->errorInfo[0] ?? ''
            );
        }

        self::assertNotNull(
            $currencyState,
        );

        self::assertSame(
            'P0001',
            $currencyState,
        );

        self::assertSame(
            'SAR',
            DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->value('currency'),
        );
    }

    public function test_h6_contract_cancellation_wins_before_acceptance_and_acceptance_fails_closed(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H6-'.Str::ulid(),
        );

        $reservationId = (string) DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->value('reservation_id');

        $unitId = (string) DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservationId)
            ->value('unit_id');

        $barrier = $this->barrierDirectory('h6');

        $cancellation = $this->startWorker([
            'action' => 'hold_contract_then_cancel',
            'application_name' => 'uh_h6_contract_cancel',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'actor_id' => (int) $actorB->id,
            'ready_file' => $barrier.'/cancel_ready',
            'release_file' => $barrier.'/cancel_release',
        ]);

        $this->waitForFiles([
            $barrier.'/cancel_ready',
        ]);

        $acceptance = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h6_acceptance',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: (string) Str::ulid(),
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h6_acceptance',
            'uh_h6_contract_cancel',
        );

        touch($barrier.'/cancel_release');

        $cancellationResult =
            $this->finishWorker($cancellation);

        $acceptanceResult =
            $this->finishWorker($acceptance);

        self::assertTrue(
            $cancellationResult['ok'],
            json_encode($cancellationResult),
        );

        self::assertFalse(
            $acceptanceResult['ok'],
            json_encode($acceptanceResult),
        );

        self::assertSame(
            UnitHandoverConflict::class,
            $acceptanceResult['class'],
        );

        self::assertSame(
            'cancelled',
            DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->value('status'),
        );

        self::assertSame(
            'available',
            DB::table('units')
                ->where('tenant_id', $tenantId)
                ->where('id', $unitId)
                ->value('status'),
        );

        self::assertSame(
            0,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->count(),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->value('status'),
        );
    }

    public function test_h7_unit_archive_wins_before_acceptance_and_acceptance_fails_closed(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H7-'.Str::ulid(),
        );

        $reservationId = (string) DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->value('reservation_id');

        $unitId = (string) DB::table('reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $reservationId)
            ->value('unit_id');

        $barrier = $this->barrierDirectory('h7');

        $archive = $this->startWorker([
            'action' => 'hold_unit_then_archive',
            'application_name' => 'uh_h7_unit_archive',
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'actor_id' => (int) $actorB->id,
            'ready_file' => $barrier.'/archive_ready',
            'release_file' => $barrier.'/archive_release',
        ]);

        $this->waitForFiles([
            $barrier.'/archive_ready',
        ]);

        $acceptance = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h7_acceptance',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: (string) Str::ulid(),
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h7_acceptance',
            'uh_h7_unit_archive',
        );

        touch($barrier.'/archive_release');

        $archiveResult =
            $this->finishWorker($archive);

        $acceptanceResult =
            $this->finishWorker($acceptance);

        self::assertTrue(
            $archiveResult['ok'],
            json_encode($archiveResult),
        );

        self::assertFalse(
            $acceptanceResult['ok'],
            json_encode($acceptanceResult),
        );

        self::assertSame(
            UnitHandoverConflict::class,
            $acceptanceResult['class'],
        );

        self::assertNotNull(
            DB::table('units')
                ->where('tenant_id', $tenantId)
                ->where('id', $unitId)
                ->value('archived_at'),
        );

        self::assertSame(
            'sold',
            DB::table('units')
                ->where('tenant_id', $tenantId)
                ->where('id', $unitId)
                ->value('status'),
        );

        self::assertSame(
            0,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->count(),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->value('status'),
        );
    }

    public function test_h8_acceptance_and_billing_activation_serialize_and_both_commit(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H8-'.Str::ulid(),
        );

        $obligationId = $this->billingObligation(
            $tenantId,
            $actorA,
            $contractId,
        );

        $barrier = $this->barrierDirectory('h8');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h8_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $acceptance = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h8_acceptance',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: (string) Str::ulid(),
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h8_acceptance',
            'uh_h8_contract_holder',
        );

        $billing = $this->startWorker([
            'action' => 'activate_billing_entitlement',
            'application_name' => 'uh_h8_billing',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorB->id,
            'obligation_id' => $obligationId,
            'operation_id' => (string) Str::ulid(),
        ]);

        $this->waitForWorkerBlockedBy(
            'uh_h8_billing',
            'uh_h8_acceptance',
        );

        touch($barrier.'/holder_release');

        $holderResult =
            $this->finishWorker($holder);
        $acceptanceResult =
            $this->finishWorker($acceptance);
        $billingResult =
            $this->finishWorker($billing);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $acceptanceResult['ok'],
            json_encode($acceptanceResult),
        );

        self::assertTrue(
            $billingResult['ok'],
            json_encode($billingResult),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_acceptances')
                ->where(
                    'id',
                    $acceptanceResult['acceptance_id'],
                )
                ->value('status'),
        );

        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where(
                    'id',
                    $billingResult['entitlement_id'],
                )
                ->value('status'),
        );

        self::assertSame(
            $contractId,
            DB::table('contractual_billing_entitlements')
                ->where(
                    'id',
                    $billingResult['entitlement_id'],
                )
                ->value('contract_id'),
        );
    }

    public function test_h9_performance_reversal_and_billing_activation_serialize_and_both_commit(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H9-'.Str::ulid(),
        );

        $acceptanceId = $this->recordAcceptance(
            $tenantId,
            $actorA,
            $evidenceId,
        );

        $obligationId = $this->billingObligation(
            $tenantId,
            $actorA,
            $contractId,
        );

        $reversalOperation = (string) Str::ulid();

        $barrier = $this->barrierDirectory('h9');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'uh_h9_contract_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([
            $barrier.'/holder_ready',
        ]);

        $reversal = $this->startWorker([
            'action' => 'reverse_performance_source',
            'application_name' => 'uh_h9_reversal',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'acceptance_id' => $acceptanceId,
            'operation_id' => $reversalOperation,
            'reason' => 'H9 correction',
            'reference' => 'H9/REVERSAL',
        ]);

        $this->waitForWorkerBlockedBy(
            'uh_h9_reversal',
            'uh_h9_contract_holder',
        );

        $billing = $this->startWorker([
            'action' => 'activate_billing_entitlement',
            'application_name' => 'uh_h9_billing',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorB->id,
            'obligation_id' => $obligationId,
            'operation_id' => (string) Str::ulid(),
        ]);

        $this->waitForWorkerBlockedBy(
            'uh_h9_billing',
            'uh_h9_reversal',
        );

        touch($barrier.'/holder_release');

        $holderResult =
            $this->finishWorker($holder);
        $reversalResult =
            $this->finishWorker($reversal);
        $billingResult =
            $this->finishWorker($billing);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertTrue(
            $reversalResult['ok'],
            json_encode($reversalResult),
        );

        self::assertTrue(
            $billingResult['ok'],
            json_encode($billingResult),
        );

        self::assertSame(
            'reversed',
            DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->value('status'),
        );

        self::assertSame(
            'reversed',
            DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->value('status'),
        );

        self::assertSame(
            $reversalOperation,
            DB::table('unit_handover_acceptances')
                ->where('id', $acceptanceId)
                ->value('reversal_operation_id'),
        );

        self::assertSame(
            $reversalOperation,
            DB::table('unit_handover_evidence')
                ->where('id', $evidenceId)
                ->value('reversal_operation_id'),
        );

        self::assertSame(
            'effective',
            DB::table('contractual_billing_entitlements')
                ->where(
                    'id',
                    $billingResult['entitlement_id'],
                )
                ->value('status'),
        );
    }

    public function test_h10_direct_evidence_reversal_loses_against_source_aware_reversal(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H10-'.Str::ulid(),
        );

        $acceptanceId = $this->recordAcceptance(
            $tenantId,
            $actorA,
            $evidenceId,
        );

        $directOperation = (string) Str::ulid();
        $sourceOperation = (string) Str::ulid();

        $barrier = $this->barrierDirectory('h10');

        $direct = $this->startWorker([
            'action' => 'hold_evidence_then_reverse_direct',
            'application_name' => 'uh_h10_direct_evidence',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorB->id,
            'evidence_id' => $evidenceId,
            'operation_id' => $directOperation,
            'reason' => 'H10 direct invalid reversal',
            'reference' => 'H10/DIRECT',
            'ready_file' => $barrier.'/direct_ready',
            'release_file' => $barrier.'/direct_release',
        ]);

        $this->waitForFiles([
            $barrier.'/direct_ready',
        ]);

        $sourceAware = $this->startWorker([
            'action' => 'reverse_performance_source',
            'application_name' => 'uh_h10_source_reversal',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'acceptance_id' => $acceptanceId,
            'operation_id' => $sourceOperation,
            'reason' => 'H10 source correction',
            'reference' => 'H10/SOURCE',
        ]);

        $this->waitForWorkerBlockedBy(
            'uh_h10_source_reversal',
            'uh_h10_direct_evidence',
        );

        touch($barrier.'/direct_release');

        $directResult =
            $this->finishWorker($direct);

        $sourceResult =
            $this->finishWorker($sourceAware);

        self::assertFalse(
            $directResult['ok'],
            json_encode($directResult),
        );

        self::assertSame(
            '23514',
            $directResult['sqlstate'],
        );

        self::assertTrue(
            $sourceResult['ok'],
            json_encode($sourceResult),
        );

        $acceptance = DB::table(
            'unit_handover_acceptances',
        )
            ->where('id', $acceptanceId)
            ->first();

        $evidence = DB::table(
            'unit_handover_evidence',
        )
            ->where('id', $evidenceId)
            ->first();

        self::assertNotNull($acceptance);
        self::assertNotNull($evidence);

        self::assertSame(
            'reversed',
            $acceptance->status,
        );

        self::assertSame(
            'reversed',
            $evidence->status,
        );

        self::assertSame(
            $sourceOperation,
            $acceptance->reversal_operation_id,
        );

        self::assertSame(
            $sourceOperation,
            $evidence->reversal_operation_id,
        );

        self::assertNotSame(
            $directOperation,
            $evidence->reversal_operation_id,
        );
    }

    public function test_h11_membership_authority_mutation_wins_before_acceptance_and_acceptance_fails_closed(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H11-'.Str::ulid(),
        );

        $barrier = $this->barrierDirectory('h11');

        $authorityMutation = $this->startWorker([
            'action' => 'hold_membership_then_pause',
            'application_name' => 'uh_h11_membership_mutation',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'ready_file' => $barrier.'/membership_ready',
            'release_file' => $barrier.'/membership_release',
        ]);

        $this->waitForFiles([
            $barrier.'/membership_ready',
        ]);

        $acceptance = $this->startWorker(
            $this->acceptancePayload(
                applicationName: 'uh_h11_acceptance',
                tenantId: $tenantId,
                actorId: (int) $actorA->id,
                evidenceId: $evidenceId,
                operationId: (string) Str::ulid(),
            ),
        );

        $this->waitForWorkerBlockedBy(
            'uh_h11_acceptance',
            'uh_h11_membership_mutation',
        );

        touch($barrier.'/membership_release');

        $mutationResult =
            $this->finishWorker($authorityMutation);

        $acceptanceResult =
            $this->finishWorker($acceptance);

        self::assertTrue(
            $mutationResult['ok'],
            json_encode($mutationResult),
        );

        self::assertFalse(
            $acceptanceResult['ok'],
            json_encode($acceptanceResult),
        );

        self::assertSame(
            UnitHandoverAccessDenied::class,
            $acceptanceResult['class'],
        );

        self::assertSame(
            'paused',
            DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $actorA->id)
                ->value('status'),
        );

        self::assertSame(
            0,
            DB::table('unit_handover_acceptances')
                ->where('tenant_id', $tenantId)
                ->where(
                    'handover_evidence_id',
                    $evidenceId,
                )
                ->count(),
        );

        self::assertSame(
            'effective',
            DB::table('unit_handover_evidence')
                ->where('tenant_id', $tenantId)
                ->where('id', $evidenceId)
                ->value('status'),
        );
    }

    public function test_h12_membership_authority_mutation_wins_before_reversal_and_reversal_fails_closed(): void
    {
        [
            $tenantId,
            $actorA,
            $actorB,
            $contractId,
        ] = $this->context();

        $evidenceId = $this->recordEvidence(
            $tenantId,
            $actorA,
            $contractId,
            'HANDOVER/H12-'.Str::ulid(),
        );

        $acceptanceId = $this->recordAcceptance(
            $tenantId,
            $actorA,
            $evidenceId,
        );

        $reversalOperation = (string) Str::ulid();

        $barrier = $this->barrierDirectory('h12');

        $authorityMutation = $this->startWorker([
            'action' => 'hold_membership_then_pause',
            'application_name' => 'uh_h12_membership_mutation',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'ready_file' => $barrier.'/membership_ready',
            'release_file' => $barrier.'/membership_release',
        ]);

        $this->waitForFiles([
            $barrier.'/membership_ready',
        ]);

        $reversal = $this->startWorker([
            'action' => 'reverse_performance_source',
            'application_name' => 'uh_h12_reversal',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'acceptance_id' => $acceptanceId,
            'operation_id' => $reversalOperation,
            'reason' => 'H12 authority race',
            'reference' => 'H12/AUTHORITY',
        ]);

        $this->waitForWorkerBlockedBy(
            'uh_h12_reversal',
            'uh_h12_membership_mutation',
        );

        touch($barrier.'/membership_release');

        $mutationResult =
            $this->finishWorker($authorityMutation);

        $reversalResult =
            $this->finishWorker($reversal);

        self::assertTrue(
            $mutationResult['ok'],
            json_encode($mutationResult),
        );

        self::assertFalse(
            $reversalResult['ok'],
            json_encode($reversalResult),
        );

        self::assertSame(
            UnitHandoverAccessDenied::class,
            $reversalResult['class'],
        );

        self::assertSame(
            'paused',
            DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $actorA->id)
                ->value('status'),
        );

        $acceptance = DB::table(
            'unit_handover_acceptances',
        )
            ->where('id', $acceptanceId)
            ->first();

        $evidence = DB::table(
            'unit_handover_evidence',
        )
            ->where('id', $evidenceId)
            ->first();

        self::assertNotNull($acceptance);
        self::assertNotNull($evidence);

        self::assertSame(
            'effective',
            $acceptance->status,
        );

        self::assertSame(
            'effective',
            $evidence->status,
        );

        self::assertNull(
            $acceptance->reversal_operation_id,
        );

        self::assertNull(
            $evidence->reversal_operation_id,
        );

        self::assertNotSame(
            $reversalOperation,
            $acceptance->reversal_operation_id,
        );

        self::assertNotSame(
            $reversalOperation,
            $evidence->reversal_operation_id,
        );
    }

    /**
     * @return array{
     *   string,
     *   User,
     *   User,
     *   string
     * }
     */
    private function context(): array
    {
        $actorA = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        $tenantId = $this->integrityTenantId(
            $actorA,
        );

        $tenant = Tenant::query()
            ->findOrFail($tenantId);

        $actorB = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        TenantUser::factory()
            ->forTenant($tenant)
            ->forUser($actorB)
            ->active()
            ->create();

        $project = $this->createIntegrityProject(
            $tenantId,
            $actorA->id,
        );

        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actorA->id,
            'sold',
        );

        $customer = $this->createIntegrityCustomer(
            $tenantId,
            $actorA->id,
        );

        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actorA->id,
            'converted',
        );

        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actorA->id,
            'active',
            [
                'total_amount' => '450000.00',
                'currency' => 'SAR',
            ],
        );

        return [
            $tenantId,
            $actorA,
            $actorB,
            (string) $contract->id,
        ];
    }

    private function recordAcceptance(
        string $tenantId,
        User $actor,
        string $evidenceId,
    ): string {
        return app(
            CreateUnitHandoverAcceptance::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'handover_evidence_id' => $evidenceId,
                'handover_acceptance_operation_id' => (string) Str::ulid(),
            ],
        );
    }

    private function billingObligation(
        string $tenantId,
        User $actor,
        string $contractId,
    ): string {
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
                'amount' => '450000.00',
                'contractual_due_date' => '2026-09-01',
                'contractual_reference' => 'Unit Handover concurrency',
            ],
        );

        app(
            FinalizeContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $scheduleId,
            $actor,
            [
                'finalization_operation_id' => (string) Str::ulid(),
            ],
        );

        return $obligationId;
    }

    private function recordEvidence(
        string $tenantId,
        User $actor,
        string $contractId,
        string $reference,
    ): string {
        return app(
            RecordUnitHandoverEvidence::class,
        )->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => $reference,
                'readiness_reference' => 'READINESS/CONCURRENCY',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'ACCEPTANCE/CONCURRENCY',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptancePayload(
        string $applicationName,
        string $tenantId,
        int $actorId,
        string $evidenceId,
        string $operationId,
    ): array {
        return [
            'action' => 'create_acceptance',
            'application_name' => $applicationName,
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'evidence_id' => $evidenceId,
            'operation_id' => $operationId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidencePayload(
        string $applicationName,
        string $tenantId,
        int $actorId,
        string $contractId,
        string $operationId,
        string $reference,
    ): array {
        return [
            'action' => 'record_evidence',
            'application_name' => $applicationName,
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'contract_id' => $contractId,
            'operation_id' => $operationId,
            'evidence_reference' => $reference,
            'readiness_reference' => 'READINESS/CONCURRENCY',
            'readiness_effective_date' => '2026-09-01',
            'customer_acceptance_reference' => 'ACCEPTANCE/CONCURRENCY',
            'customer_acceptance_effective_date' => '2026-09-02',
        ];
    }

    private function barrierDirectory(
        string $suffix,
    ): string {
        $path = sys_get_temp_dir()
            .'/nexusos_unit_handover_'
            .$suffix.'_'.Str::ulid();

        mkdir($path, 0700, true);

        return $path;
    }

    /**
     * @param  array<int,string>  $files
     */
    private function waitForFiles(
        array $files,
        int $timeoutMs = 5000,
    ): void {
        $started = hrtime(true);

        while (true) {
            $ready = true;

            foreach ($files as $file) {
                if (! is_file($file)) {
                    $ready = false;

                    break;
                }
            }

            if ($ready) {
                return;
            }

            usleep(10_000);

            if (
                (hrtime(true) - $started)
                / 1_000_000 > $timeoutMs
            ) {
                self::fail(
                    'Timed out waiting for Unit Handover worker barrier.',
                );
            }
        }
    }

    private function waitForWorkerBlockedBy(
        string $waitingApplication,
        string $blockingApplication,
        int $timeoutMs = 5000,
    ): void {
        $started = hrtime(true);

        while (true) {
            $activity = DB::selectOne(
                <<<'SQL'
                    SELECT
                      waiter.wait_event_type,
                      EXISTS (
                        SELECT 1
                        FROM pg_catalog.unnest(
                          pg_catalog.pg_blocking_pids(
                            waiter.pid
                          )
                        ) AS blocking(pid)
                        JOIN pg_catalog.pg_stat_activity blocker
                          ON blocker.pid = blocking.pid
                        WHERE blocker.application_name = ?
                      ) AS blocked_by_expected
                    FROM pg_catalog.pg_stat_activity waiter
                    WHERE waiter.application_name = ?
                      AND waiter.pid <> pg_backend_pid()
                    ORDER BY waiter.backend_start DESC
                    LIMIT 1
                    SQL,
                [
                    $blockingApplication,
                    $waitingApplication,
                ],
            );

            if (
                $activity !== null
                && $activity->wait_event_type === 'Lock'
                && (bool) $activity->blocked_by_expected
            ) {
                return;
            }

            usleep(10_000);

            if (
                (hrtime(true) - $started)
                / 1_000_000 > $timeoutMs
            ) {
                self::fail(
                    "Timed out waiting for worker [{$waitingApplication}] to block on PostgreSQL worker [{$blockingApplication}].",
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function databasePayload(): array
    {
        $connection = config(
            'database.connections.'
            .DB::getDefaultConnection(),
        );

        return array_intersect_key(
            $connection,
            array_flip([
                'host',
                'port',
                'database',
                'username',
                'password',
            ]),
        );
    }

    /**
     * @return array{resource,array<int,resource>}
     */
    private function startWorker(
        array $payload,
    ): array {
        $process = proc_open(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/unit_handover_worker.php',
                ),
                base64_encode(json_encode(
                    $payload + [
                        'database' => $this->databasePayload(),
                    ],
                    JSON_THROW_ON_ERROR,
                )),
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /**
     * @param  array{resource,array<int,resource>}  $worker
     * @return array<string,mixed>
     */
    private function finishWorker(
        array $worker,
    ): array {
        [$process, $pipes] = $worker;

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(
            0,
            proc_close($process),
            $stderr,
        );

        return json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
