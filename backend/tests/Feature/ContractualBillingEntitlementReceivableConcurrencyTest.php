<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\Payments\Actions\RecordPaymentAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingEntitlementReceivableConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    /** @var array<int,string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_c1_same_entitlement_same_operation_resolves_one_receivable_and_link(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $operationId = (string) Str::ulid();
        $barrier = $this->temporaryFile('erl-c1-start-');

        $payload = [
            'action' => 'establish_entitlement_receivable_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'entitlement_id' => $entitlementId,
            'operation_id' => $operationId,
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($payload);
        $second = $this->startWorker($payload);
        touch($barrier);

        $results = [$this->finishWorker($first), $this->finishWorker($second)];

        self::assertSame(2, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)), json_encode($results));
        self::assertSame(1, count(array_unique([$results[0]['id'], $results[1]['id']])), json_encode($results));
        self::assertSame(1, DB::table('entitlement_receivable_links')->where('tenant_id', $tenantId)->where('entitlement_id', $entitlementId)->count());
        self::assertSame(1, DB::table('receivables')->where('tenant_id', $tenantId)->where('recognition_operation_id', $operationId)->count());
    }

    public function test_c2_same_entitlement_different_operations_leave_one_historical_consumption(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $barrier = $this->temporaryFile('erl-c2-start-');
        $base = [
            'action' => 'establish_entitlement_receivable_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'entitlement_id' => $entitlementId,
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($base + ['operation_id' => (string) Str::ulid()]);
        $second = $this->startWorker($base + ['operation_id' => (string) Str::ulid()]);
        touch($barrier);
        $results = [$this->finishWorker($first), $this->finishWorker($second)];

        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)), json_encode($results));
        self::assertSame(1, DB::table('entitlement_receivable_links')->where('tenant_id', $tenantId)->where('entitlement_id', $entitlementId)->count(), json_encode($results));
    }

    public function test_c3_establishment_vs_source_correction_never_commits_incoherent_pair(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $barrier = $this->temporaryFile('erl-c3-start-');

        $establishment = $this->startWorker([
            'action' => 'establish_entitlement_receivable_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'entitlement_id' => $entitlementId,
            'operation_id' => (string) Str::ulid(),
            'start_barrier' => $barrier,
        ]);

        $correction = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'ERL C3 race',
            'source_correction_reference' => 'ERL-C3',
            'entitlement_reversals' => [$entitlementId => (string) Str::ulid()],
            'start_barrier' => $barrier,
        ]);

        touch($barrier);
        $results = [$this->finishWorker($establishment), $this->finishWorker($correction)];

        $entitlement = DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->first();
        $link = DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->first();

        if ($entitlement->status === 'reversed') {
            if ($link !== null) {
                self::assertSame('cancelled', DB::table('receivables')->where('id', $link->receivable_id)->value('status'), json_encode($results));
                self::assertNotNull($link->source_correction_operation_id);
            }
        } else {
            self::assertSame('effective', $entitlement->status);
            if ($link !== null) {
                self::assertSame('recognized', DB::table('receivables')->where('id', $link->receivable_id)->value('status'), json_encode($results));
                self::assertNull($link->source_correction_operation_id);
            }
        }
    }

    public function test_c4_source_correction_vs_ordinary_cancellation_is_deterministic(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $barrier = $this->temporaryFile('erl-c4-start-');

        $ordinary = $this->startWorker([
            'action' => 'ordinary_cancel_receivable_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'receivable_id' => $receivableId,
            'cancelled_at' => '2026-09-05T00:00:00+00:00',
            'reason' => 'Ordinary race',
            'start_barrier' => $barrier,
        ]);
        $correctionOperation = (string) Str::ulid();
        $correction = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => $correctionOperation,
            'source_correction_reason' => 'Authoritative race',
            'source_correction_reference' => 'ERL-C4',
            'entitlement_reversals' => [$entitlementId => (string) Str::ulid()],
            'start_barrier' => $barrier,
        ]);

        touch($barrier);
        $results = [$this->finishWorker($ordinary), $this->finishWorker($correction)];

        self::assertSame('reversed', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'), json_encode($results));
        self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'), json_encode($results));
        self::assertSame($correctionOperation, DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'), json_encode($results));
        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)), json_encode($results));
    }

    public function test_c5_static_primitive_contract_supplements_runtime_lock_proof(): void
    {
        $source = file_get_contents(base_path('app/Modules/ContractualBilling/Support/CancelLinkedReceivablePrimitive.php'));
        self::assertIsString($source);

        self::assertStringNotContainsString('use App\\Modules\\Receivables\\Support\\ReceivablesAuthorization;', $source);
        self::assertStringNotContainsString('app(ReceivablesAuthorization::class)', $source);
        self::assertStringNotContainsString('app(ContractualBillingAuthorization::class)', $source);
        self::assertStringNotContainsString('authorizeTransactional(', $source);
        self::assertStringNotContainsString("DB::table('tenant_users')", $source);
        self::assertStringNotContainsString("DB::table('users')", $source);
        self::assertStringNotContainsString("DB::table('tenants')", $source);
        self::assertStringContainsString('DB::transactionLevel() < 1', $source);
        self::assertStringContainsString('EffectivePaymentAllocationGuard', $source);
    }

    public function test_c6_source_correction_vs_payment_allocation_creation_preserves_one_valid_economic_state(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $paymentId = $this->payment($tenantId, $actor, $customerId);
        $barrier = $this->temporaryFile('erl-c6-start-');
        $correctionOperation = (string) Str::ulid();

        $allocation = $this->startWorker([
            'action' => 'allocate_payment_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_operation_id' => (string) Str::ulid(),
            'amount' => '100.00',
            'start_barrier' => $barrier,
        ]);
        $correction = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => $correctionOperation,
            'source_correction_reason' => 'Allocation race',
            'source_correction_reference' => 'ERL-C6',
            'entitlement_reversals' => [$entitlementId => (string) Str::ulid()],
            'start_barrier' => $barrier,
        ]);

        touch($barrier);
        $results = [$this->finishWorker($allocation), $this->finishWorker($correction)];
        $effectiveAllocation = DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('receivable_id', $receivableId)->where('status', 'effective')->exists();
        $entitlementStatus = (string) DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status');

        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)), json_encode($results));
        self::assertFalse($effectiveAllocation && $entitlementStatus === 'reversed', json_encode($results));

        if ($effectiveAllocation) {
            self::assertSame('effective', $entitlementStatus);
            self::assertSame('finalized', DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('status'));
            self::assertSame('recognized', DB::table('receivables')->where('id', $receivableId)->value('status'));
            self::assertNull(DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
        } else {
            self::assertSame('reversed', $entitlementStatus);
            self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'));
            self::assertSame($correctionOperation, DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
        }
    }

    public function test_c7_membership_revocation_vs_establishment_reauthorizes_before_source_locks(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $ready = $this->temporaryFile('erl-c7-ready-');
        $release = $this->temporaryFile('erl-c7-release-');
        $operation = (string) Str::ulid();

        $mutation = $this->startWorker([
            'action' => 'mutate_membership_status',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'status' => 'suspended',
            'ready_file' => $ready,
            'release_file' => $release,
            'application_name' => 'erl_c7_mutation',
        ]);
        $this->waitForFile($ready);

        $establishment = $this->startWorker([
            'action' => 'establish_entitlement_receivable_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'entitlement_id' => $entitlementId,
            'operation_id' => $operation,
            'application_name' => 'erl_c7_establish',
        ]);
        $this->waitForWorkerLock('erl_c7_establish');
        touch($release);

        $results = [$this->finishWorker($mutation), $this->finishWorker($establishment)];

        self::assertTrue($results[0]['ok'], json_encode($results));
        self::assertFalse($results[1]['ok'], json_encode($results));
        self::assertSame(0, DB::table('receivables')->where('tenant_id', $tenantId)->where('recognition_operation_id', $operation)->count());
        self::assertSame(0, DB::table('entitlement_receivable_links')->where('tenant_id', $tenantId)->where('entitlement_id', $entitlementId)->count());
        self::assertSame('effective', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'));
    }

    public function test_c8_source_correction_vs_membership_revocation_has_no_partial_mutation(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $ready = $this->temporaryFile('erl-c8-ready-');
        $release = $this->temporaryFile('erl-c8-release-');

        $mutation = $this->startWorker([
            'action' => 'mutate_membership_status',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'status' => 'suspended',
            'ready_file' => $ready,
            'release_file' => $release,
            'application_name' => 'erl_c8_mutation',
        ]);
        $this->waitForFile($ready);

        $correction = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'Authority revocation race',
            'source_correction_reference' => 'ERL-C8',
            'entitlement_reversals' => [$entitlementId => (string) Str::ulid()],
            'application_name' => 'erl_c8_correction',
        ]);
        $this->waitForWorkerLock('erl_c8_correction');
        touch($release);

        $results = [$this->finishWorker($mutation), $this->finishWorker($correction)];

        self::assertTrue($results[0]['ok'], json_encode($results));
        self::assertFalse($results[1]['ok'], json_encode($results));
        self::assertSame('finalized', DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('status'));
        self::assertSame('effective', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'));
        self::assertSame('recognized', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertNull(DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
    }

    public function test_c9_source_correction_same_operation_concurrent_replay_resolves_one_terminal_truth(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $barrier = $this->temporaryFile('erl-c9-start-');
        $operation = (string) Str::ulid();
        $reversal = (string) Str::ulid();
        $payload = [
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => $operation,
            'source_correction_reason' => 'Concurrent replay',
            'source_correction_reference' => 'ERL-C9',
            'entitlement_reversals' => [$entitlementId => $reversal],
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($payload);
        $second = $this->startWorker($payload);
        touch($barrier);
        $results = [$this->finishWorker($first), $this->finishWorker($second)];

        self::assertSame(2, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)), json_encode($results));
        self::assertSame('cancelled', DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('status'));
        self::assertSame($operation, DB::table('contractual_billing_schedules')->where('id', $scheduleId)->value('source_correction_operation_id'));
        self::assertSame('reversed', DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('status'));
        self::assertSame($reversal, DB::table('contractual_billing_entitlements')->where('id', $entitlementId)->value('reversal_operation_id'));
        self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertSame($operation, DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
    }

    public function test_c10_correction_waiting_on_link_has_not_locked_receivable_and_replay_remains_coherent(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [$scheduleId, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $ready = $this->temporaryFile('erl-c10-ready-');
        $release = $this->temporaryFile('erl-c10-release-');
        $operation = (string) Str::ulid();
        $reversal = (string) Str::ulid();
        $payload = [
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => $operation,
            'source_correction_reason' => 'Link order proof',
            'source_correction_reference' => 'ERL-C10',
            'entitlement_reversals' => [$entitlementId => $reversal],
        ];

        $holder = $this->startWorker([
            'action' => 'hold_link',
            'tenant_id' => $tenantId,
            'receivable_id' => $receivableId,
            'ready_file' => $ready,
            'release_file' => $release,
            'application_name' => 'erl_c10_link_holder',
        ]);
        $this->waitForFile($ready);

        $correction = $this->startWorker($payload + ['application_name' => 'erl_c10_correction']);
        $this->waitForWorkerLock('erl_c10_correction');

        $probe = $this->finishWorker($this->startWorker([
            'action' => 'probe_receivable_nowait',
            'tenant_id' => $tenantId,
            'receivable_id' => $receivableId,
        ]));
        self::assertTrue($probe['ok'], json_encode($probe));

        touch($release);
        $holderResult = $this->finishWorker($holder);
        $correctionResult = $this->finishWorker($correction);
        $replayResult = $this->finishWorker($this->startWorker($payload));

        self::assertTrue($holderResult['ok'], json_encode($holderResult));
        self::assertTrue($correctionResult['ok'], json_encode($correctionResult));
        self::assertTrue($replayResult['ok'], json_encode($replayResult));
        self::assertSame('cancelled', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertSame($operation, DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
    }

    public function test_c11_internal_primitive_does_not_reacquire_authorization_rows_at_runtime(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();
        [, , $entitlementId] = $this->effectiveEntitlement($tenantId, $actorId, $contractId);
        $actor = User::query()->findOrFail($actorId);
        $receivableId = app(EstablishEntitlementReceivable::class)->execute($tenantId, $entitlementId, $actor, ['receivable_establishment_operation_id' => (string) Str::ulid()]);
        $ready = $this->temporaryFile('erl-c11-ready-');
        $release = $this->temporaryFile('erl-c11-release-');

        $holder = $this->startWorker([
            'action' => 'hold_authorization_rows_no_key_update',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'ready_file' => $ready,
            'release_file' => $release,
            'application_name' => 'erl_c11_auth_holder',
        ]);
        $this->waitForFile($ready);

        $primitiveResult = $this->finishWorker($this->startWorker([
            'action' => 'primitive_cancel_rollback',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'receivable_id' => $receivableId,
            'source_correction_operation_id' => (string) Str::ulid(),
            'application_name' => 'erl_c11_primitive',
        ]));
        touch($release);
        $holderResult = $this->finishWorker($holder);

        self::assertTrue($primitiveResult['ok'], json_encode($primitiveResult));
        self::assertTrue($holderResult['ok'], json_encode($holderResult));
        self::assertSame('recognized', DB::table('receivables')->where('id', $receivableId)->value('status'));
        self::assertNull(DB::table('entitlement_receivable_links')->where('entitlement_id', $entitlementId)->value('source_correction_operation_id'));
    }

    /** @return array{string,int,string,string} */
    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        DB::table('tenants')->where('id', $tenantId)->update(['timezone' => 'Asia/Riyadh', 'currency' => 'SAR', 'updated_at' => now()]);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'reserved');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id);
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actor->id, 'active', ['total_amount' => '1000.00', 'currency' => 'SAR']);

        return [$tenantId, $actor->id, (string) $customer->id, (string) $contract->id];
    }

    /** @return array{string,string,string} */
    private function effectiveEntitlement(string $tenantId, int $actorId, string $contractId): array
    {
        $actor = User::query()->findOrFail($actorId);
        $scheduleId = app(CreateContractualBillingSchedule::class)->execute($tenantId, $actor, ['contract_id' => $contractId, 'schedule_operation_id' => (string) Str::ulid()]);
        $obligationId = app(SaveDraftContractualBillingObligation::class)->execute($tenantId, $scheduleId, $actor, [
            'obligation_operation_id' => (string) Str::ulid(), 'amount' => '1000.00', 'contractual_due_date' => '2026-09-01', 'contractual_reference' => 'ERL concurrency fixture',
        ]);
        app(FinalizeContractualBillingSchedule::class)->execute($tenantId, $scheduleId, $actor, ['finalization_operation_id' => (string) Str::ulid()]);
        $entitlementId = app(ActivateContractualBillingEntitlement::class)->execute($tenantId, $obligationId, $actor, ['billing_entitlement_operation_id' => (string) Str::ulid()]);

        return [$scheduleId, $obligationId, $entitlementId];
    }

    private function payment(string $tenantId, User $actor, string $customerId): string
    {
        return app(RecordPaymentAction::class)->execute($tenantId, $actor, [
            'customer_id' => $customerId,
            'payment_operation_id' => (string) Str::ulid(),
            'amount' => '100.00',
            'currency' => 'SAR',
            'received_on' => CarbonImmutable::now('UTC')->subDay()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'ERL-C6',
        ]);
    }

    private function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate ERL synchronization file.');
        }
        unlink($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function waitForFile(string $path, int $timeoutMs = 5000): void
    {
        $started = hrtime(true);
        while (! is_file($path)) {
            usleep(10_000);
            if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                self::fail("Timed out waiting for worker barrier [{$path}].");
            }
        }
    }

    private function waitForWorkerLock(string $applicationName, int $timeoutMs = 5000): void
    {
        $started = hrtime(true);
        while (true) {
            $activity = DB::selectOne(
                'SELECT wait_event_type FROM pg_catalog.pg_stat_activity WHERE application_name = ? AND pid <> pg_backend_pid() ORDER BY backend_start DESC LIMIT 1',
                [$applicationName],
            );
            if ($activity !== null && $activity->wait_event_type === 'Lock') {
                return;
            }
            usleep(10_000);
            if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                self::fail("Timed out waiting for worker [{$applicationName}] to block on a PostgreSQL lock.");
            }
        }
    }

    private function databasePayload(): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());

        return array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password']));
    }

    private function startWorker(array $payload): array
    {
        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/contractual_billing_worker.php'),
            base64_encode(json_encode($payload + ['database' => $this->databasePayload()], JSON_THROW_ON_ERROR)),
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function finishWorker(array $worker): array
    {
        [$process, $pipes] = $worker;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }
}
