<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\ActivateContractualBillingEntitlement;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
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

    public function test_c5_internal_primitive_has_no_second_authorization_path(): void
    {
        $source = file_get_contents(base_path('app/Modules/ContractualBilling/Support/CancelLinkedReceivablePrimitive.php'));
        self::assertIsString($source);

        self::assertStringNotContainsString('ReceivablesAuthorization', $source);
        self::assertStringNotContainsString('ContractualBillingAuthorization', $source);
        self::assertStringNotContainsString('authorizeTransactional', $source);
        self::assertStringNotContainsString("DB::table('tenant_users')", $source);
        self::assertStringNotContainsString("DB::table('users')", $source);
        self::assertStringNotContainsString("DB::table('tenants')", $source);
        self::assertStringContainsString('DB::transactionLevel() < 1', $source);
        self::assertStringContainsString('EffectivePaymentAllocationGuard', $source);
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
