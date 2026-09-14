<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationAccessDenied;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use App\Modules\UnitHandover\Actions\RecordUnitHandoverEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractConsiderationAdoptionConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_cc13_same_adoption_operation_concurrently_converges_to_one_committed_adoption(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $operationId = (string) Str::ulid();
        $barrier = $this->barrierDirectory('cc13');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'cc13_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);

        $this->waitForFiles([$barrier.'/holder_ready']);

        $workerA = $this->startWorker($this->adoptionPayload(
            'cc13_adopt_a',
            $tenantId,
            (int) $actorA->id,
            $contractId,
            $operationId,
        ));
        $this->waitForWorkerBlockedBy('cc13_adopt_a', 'cc13_holder');

        $workerB = $this->startWorker($this->adoptionPayload(
            'cc13_adopt_b',
            $tenantId,
            (int) $actorB->id,
            $contractId,
            $operationId,
        ));
        $this->waitForWorkerBlockedBy('cc13_adopt_b', 'cc13_adopt_a');

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue($holderResult['ok'], json_encode($holderResult));
        self::assertTrue($resultA['ok'], json_encode($resultA));
        self::assertTrue($resultB['ok'], json_encode($resultB));
        self::assertSame($resultA['adoption_id'], $resultB['adoption_id']);
        $this->assertExactAdoptedState($tenantId, $contractId, $operationId, '450000.00');
    }

    public function test_cc14_competing_adoption_operations_for_same_contract_have_one_winner(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $operationA = (string) Str::ulid();
        $operationB = (string) Str::ulid();
        $barrier = $this->barrierDirectory('cc14');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'cc14_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);
        $this->waitForFiles([$barrier.'/holder_ready']);

        $workerA = $this->startWorker($this->adoptionPayload(
            'cc14_adopt_a',
            $tenantId,
            (int) $actorA->id,
            $contractId,
            $operationA,
        ));
        $this->waitForWorkerBlockedBy('cc14_adopt_a', 'cc14_holder');

        $workerB = $this->startWorker($this->adoptionPayload(
            'cc14_adopt_b',
            $tenantId,
            (int) $actorB->id,
            $contractId,
            $operationB,
        ));
        $this->waitForWorkerBlockedBy('cc14_adopt_b', 'cc14_adopt_a');

        touch($barrier.'/holder_release');

        $holderResult = $this->finishWorker($holder);
        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        self::assertTrue($holderResult['ok'], json_encode($holderResult));
        self::assertTrue($resultA['ok'], json_encode($resultA));
        self::assertFalse($resultB['ok'], json_encode($resultB));
        self::assertSame(ContractConsiderationConflict::class, $resultB['class']);
        $this->assertExactAdoptedState($tenantId, $contractId, $operationA, '450000.00');
    }

    public function test_cc15_first_billing_entitlement_winning_contract_lock_makes_clean_adoption_fail_closed(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $obligationId = $this->billingObligation($tenantId, $actorA, $contractId);
        $barrier = $this->barrierDirectory('cc15');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'cc15_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);
        $this->waitForFiles([$barrier.'/holder_ready']);

        $billing = $this->startWorker([
            'action' => 'activate_billing_entitlement',
            'application_name' => 'cc15_billing',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'obligation_id' => $obligationId,
            'operation_id' => (string) Str::ulid(),
        ]);
        $this->waitForWorkerBlockedBy('cc15_billing', 'cc15_holder');

        $adoption = $this->startWorker($this->adoptionPayload(
            'cc15_adopt',
            $tenantId,
            (int) $actorB->id,
            $contractId,
            (string) Str::ulid(),
        ));
        $this->waitForWorkerBlockedBy('cc15_adopt', 'cc15_billing');

        touch($barrier.'/holder_release');

        self::assertTrue($this->finishWorker($holder)['ok']);
        $billingResult = $this->finishWorker($billing);
        $adoptionResult = $this->finishWorker($adoption);

        self::assertTrue($billingResult['ok'], json_encode($billingResult));
        self::assertFalse($adoptionResult['ok'], json_encode($adoptionResult));
        self::assertSame(ContractConsiderationConflict::class, $adoptionResult['class']);
        self::assertSame('effective', DB::table('contractual_billing_entitlements')->where('id', $billingResult['entitlement_id'])->value('status'));
        $this->assertNoAdoptionState($tenantId, $contractId);
    }

    public function test_cc16_first_handover_acceptance_winning_contract_lock_makes_clean_adoption_fail_closed(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $evidenceId = $this->recordEvidence($tenantId, $actorA, $contractId);
        $barrier = $this->barrierDirectory('cc16');

        $holder = $this->startWorker([
            'action' => 'hold_contract',
            'application_name' => 'cc16_holder',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'ready_file' => $barrier.'/holder_ready',
            'release_file' => $barrier.'/holder_release',
        ]);
        $this->waitForFiles([$barrier.'/holder_ready']);

        $acceptance = $this->startWorker([
            'action' => 'create_acceptance',
            'application_name' => 'cc16_acceptance',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'evidence_id' => $evidenceId,
            'operation_id' => (string) Str::ulid(),
        ]);
        $this->waitForWorkerBlockedBy('cc16_acceptance', 'cc16_holder');

        $adoption = $this->startWorker($this->adoptionPayload(
            'cc16_adopt',
            $tenantId,
            (int) $actorB->id,
            $contractId,
            (string) Str::ulid(),
        ));
        $this->waitForWorkerBlockedBy('cc16_adopt', 'cc16_acceptance');

        touch($barrier.'/holder_release');

        self::assertTrue($this->finishWorker($holder)['ok']);
        $acceptanceResult = $this->finishWorker($acceptance);
        $adoptionResult = $this->finishWorker($adoption);

        self::assertTrue($acceptanceResult['ok'], json_encode($acceptanceResult));
        self::assertFalse($adoptionResult['ok'], json_encode($adoptionResult));
        self::assertSame(ContractConsiderationConflict::class, $adoptionResult['class']);
        self::assertSame('effective', DB::table('unit_handover_acceptances')->where('id', $acceptanceResult['acceptance_id'])->value('status'));
        $this->assertNoAdoptionState($tenantId, $contractId);
    }

    public function test_cc17_contract_capacity_mutation_winning_lock_is_snapshotted_by_later_adoption(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $barrier = $this->barrierDirectory('cc17');

        $mutation = $this->startWorker([
            'action' => 'hold_contract_then_update_total',
            'application_name' => 'cc17_mutation',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'total_amount' => '460000.00',
            'ready_file' => $barrier.'/mutation_ready',
            'release_file' => $barrier.'/mutation_release',
        ]);
        $this->waitForFiles([$barrier.'/mutation_ready']);

        $operationId = (string) Str::ulid();
        $adoption = $this->startWorker($this->adoptionPayload(
            'cc17_adopt',
            $tenantId,
            (int) $actorB->id,
            $contractId,
            $operationId,
        ));
        $this->waitForWorkerBlockedBy('cc17_adopt', 'cc17_mutation');

        touch($barrier.'/mutation_release');

        $mutationResult = $this->finishWorker($mutation);
        $adoptionResult = $this->finishWorker($adoption);

        self::assertTrue($mutationResult['ok'], json_encode($mutationResult));
        self::assertTrue($adoptionResult['ok'], json_encode($adoptionResult));
        self::assertSame('460000.00', (string) DB::table('contracts')->where('id', $contractId)->value('total_amount'));
        $this->assertExactAdoptedState($tenantId, $contractId, $operationId, '460000.00');
    }

    public function test_cc18_authority_mutation_winning_membership_lock_makes_adoption_fail_closed(): void
    {
        [$tenantId, $actorA, $actorB, $contractId] = $this->context();
        $barrier = $this->barrierDirectory('cc18');

        $mutation = $this->startWorker([
            'action' => 'hold_membership_then_pause',
            'application_name' => 'cc18_authority',
            'tenant_id' => $tenantId,
            'actor_id' => (int) $actorA->id,
            'ready_file' => $barrier.'/authority_ready',
            'release_file' => $barrier.'/authority_release',
        ]);
        $this->waitForFiles([$barrier.'/authority_ready']);

        $adoption = $this->startWorker($this->adoptionPayload(
            'cc18_adopt',
            $tenantId,
            (int) $actorA->id,
            $contractId,
            (string) Str::ulid(),
        ));
        $this->waitForWorkerBlockedBy('cc18_adopt', 'cc18_authority');

        touch($barrier.'/authority_release');

        $mutationResult = $this->finishWorker($mutation);
        $adoptionResult = $this->finishWorker($adoption);

        self::assertTrue($mutationResult['ok'], json_encode($mutationResult));
        self::assertFalse($adoptionResult['ok'], json_encode($adoptionResult));
        self::assertSame(ContractConsiderationAccessDenied::class, $adoptionResult['class']);
        self::assertSame('paused', DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $actorA->id)->value('status'));
        $this->assertNoAdoptionState($tenantId, $contractId);
    }

    /** @return array{string,User,User,string} */
    private function context(): array
    {
        $actorA = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actorA);
        $tenant = Tenant::query()->findOrFail($tenantId);

        $actorB = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        TenantUser::factory()->forTenant($tenant)->forUser($actorB)->active()->create();

        $project = $this->createIntegrityProject($tenantId, $actorA->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actorA->id, 'sold');
        $customer = $this->createIntegrityCustomer($tenantId, $actorA->id);
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
            ['total_amount' => '450000.00', 'currency' => 'SAR'],
        );

        return [$tenantId, $actorA, $actorB, (string) $contract->id];
    }

    private function billingObligation(string $tenantId, User $actor, string $contractId): string
    {
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
                'contractual_reference' => 'CCA concurrency',
            ],
        );

        app(FinalizeContractualBillingSchedule::class)->execute(
            $tenantId,
            $scheduleId,
            $actor,
            ['finalization_operation_id' => (string) Str::ulid()],
        );

        return $obligationId;
    }

    private function recordEvidence(string $tenantId, User $actor, string $contractId): string
    {
        return app(RecordUnitHandoverEvidence::class)->execute(
            $tenantId,
            $actor,
            [
                'contract_id' => $contractId,
                'handover_evidence_operation_id' => (string) Str::ulid(),
                'handover_evidence_reference' => 'CCA/CC16/'.Str::ulid(),
                'readiness_reference' => 'CCA/CC16/READY',
                'readiness_effective_date' => '2026-09-01',
                'customer_acceptance_reference' => 'CCA/CC16/ACCEPT',
                'customer_acceptance_effective_date' => '2026-09-02',
            ],
        );
    }

    /** @return array<string,mixed> */
    private function adoptionPayload(
        string $applicationName,
        string $tenantId,
        int $actorId,
        string $contractId,
        string $operationId,
    ): array {
        return [
            'action' => 'adopt',
            'application_name' => $applicationName,
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'contract_id' => $contractId,
            'operation_id' => $operationId,
        ];
    }

    private function assertExactAdoptedState(
        string $tenantId,
        string $contractId,
        string $operationId,
        string $amount,
    ): void {
        $adoption = DB::table('contract_consideration_adoptions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->first();

        self::assertNotNull($adoption);
        self::assertSame($operationId, $adoption->coordination_adoption_operation_id);
        self::assertSame($amount, (string) $adoption->consideration_amount);

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->first();

        self::assertNotNull($position);
        self::assertSame($adoption->id, $position->adoption_id);
        self::assertSame($amount, (string) $position->consideration_amount);
        self::assertSame($amount, (string) $position->source_contract_total_snapshot);

        $genesis = DB::table('contract_consideration_lots')
            ->where('tenant_id', $tenantId)
            ->where('position_root_id', $position->id)
            ->first();

        self::assertNotNull($genesis);
        self::assertSame('GENESIS', $genesis->lot_kind);
        self::assertSame('UNPERFORMED_UNBILLED', $genesis->semantic_position);
        self::assertSame($amount, (string) $genesis->amount);
    }

    private function assertNoAdoptionState(string $tenantId, string $contractId): void
    {
        self::assertSame(0, DB::table('contract_consideration_adoptions')->where('tenant_id', $tenantId)->where('contract_id', $contractId)->count());
        self::assertSame(0, DB::table('contract_consideration_positions')->where('tenant_id', $tenantId)->where('contract_id', $contractId)->count());
        self::assertSame(0, DB::table('contract_consideration_lots')->where('tenant_id', $tenantId)->where('contract_id', $contractId)->count());
    }

    private function barrierDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir().'/nexusos_contract_consideration_'.$suffix.'_'.Str::ulid();
        mkdir($path, 0700, true);

        return $path;
    }

    /** @param array<int,string> $files */
    private function waitForFiles(array $files, int $timeoutMs = 5000): void
    {
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

            if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                self::fail('Timed out waiting for Contract Consideration worker barrier.');
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
                          pg_catalog.pg_blocking_pids(waiter.pid)
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
                [$blockingApplication, $waitingApplication],
            );

            if (
                $activity !== null
                && $activity->wait_event_type === 'Lock'
                && (bool) $activity->blocked_by_expected
            ) {
                return;
            }

            usleep(10_000);

            if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                self::fail(
                    "Timed out waiting for worker [{$waitingApplication}] to block on [{$blockingApplication}].",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function databasePayload(): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());

        return array_intersect_key(
            $connection,
            array_flip(['host', 'port', 'database', 'username', 'password']),
        );
    }

    /** @return array{resource,array<int,resource>} */
    private function startWorker(array $payload): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/contract_consideration_worker.php'),
                base64_encode(json_encode(
                    $payload + ['database' => $this->databasePayload()],
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
     * @param array{resource,array<int,resource>} $worker
     * @return array<string,mixed>
     */
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
