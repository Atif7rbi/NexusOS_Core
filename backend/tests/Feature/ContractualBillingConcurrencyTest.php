<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractualBilling\Actions\CreateContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\CreateSuccessorContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\FinalizeContractualBillingSchedule;
use App\Modules\ContractualBilling\Actions\SaveDraftContractualBillingObligation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractualBillingConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    /** @var array<int, string> */
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

    public function test_c1_finalization_fails_fast_before_waiting_on_locked_tenant(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId] = $this->draftScheduleWithObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $holderReady = $this->temporaryFile('cbs-c1-holder-ready-');
        $holderRelease = $this->temporaryFile('cbs-c1-holder-release-');

        $holder = $this->startWorker([
            'action' => 'hold_tenant',
            'tenant_id' => $tenantId,
            'ready_file' => $holderReady,
            'release_file' => $holderRelease,
        ]);

        $this->waitForFiles([$holderReady]);

        $finalizerResult = $this->runWorker([
            'action' => 'finalize_schedule',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'timezone' => 'Asia/Riyadh',
            'finalization_operation_id' => (string) Str::ulid(),
        ]);

        self::assertFalse(
            $finalizerResult['ok'],
            json_encode($finalizerResult),
        );

        self::assertSame(
            '55P03',
            $finalizerResult['sqlstate'] ?? null,
            json_encode($finalizerResult),
        );

        $probe = $this->runWorker([
            'action' => 'probe_schedule_nowait',
            'tenant_id' => $tenantId,
            'schedule_id' => $scheduleId,
        ]);

        self::assertTrue(
            $probe['ok'],
            'Failed finalization retained the Schedule lock while Tenant remained locked: '
                .json_encode([$finalizerResult, $probe]),
        );

        self::assertSame(
            'draft',
            DB::table('contractual_billing_schedules')
                ->where('id', $scheduleId)
                ->value('status'),
        );

        touch($holderRelease);

        $holderResult = $this->finishWorker($holder);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );
    }

    public function test_c2_two_schedules_racing_to_finalize_same_contract_leave_at_most_one_current_finalized(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$firstScheduleId] = $this->draftScheduleWithObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            'CBS-C2-FIRST',
        );

        [$secondScheduleId] = $this->draftScheduleWithObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
            'CBS-C2-SECOND',
        );

        $barrier = $this->temporaryFile('cbs-c2-start-');

        $first = $this->startWorker([
            'action' => 'finalize_schedule',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $firstScheduleId,
            'timezone' => 'Asia/Riyadh',
            'finalization_operation_id' => (string) Str::ulid(),
            'start_barrier' => $barrier,
        ]);

        $second = $this->startWorker([
            'action' => 'finalize_schedule',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $secondScheduleId,
            'timezone' => 'Asia/Riyadh',
            'finalization_operation_id' => (string) Str::ulid(),
            'start_barrier' => $barrier,
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->where('status', 'finalized')
                ->count(),
            json_encode($results),
        );
    }

    public function test_c3_contract_total_mutation_vs_finalization_never_commits_incoherent_truth(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId] = $this->draftScheduleWithObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $barrier = $this->temporaryFile('cbs-c3-start-');

        $finalizer = $this->startWorker([
            'action' => 'finalize_schedule',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'timezone' => 'Asia/Riyadh',
            'finalization_operation_id' => (string) Str::ulid(),
            'start_barrier' => $barrier,
        ]);

        $contractMutation = $this->startWorker([
            'action' => 'update_contract_total',
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'total_amount' => '1100.00',
            'start_barrier' => $barrier,
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($finalizer),
            $this->finishWorker($contractMutation),
        ];

        self::assertGreaterThanOrEqual(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        $contractTotal = (string) DB::table('contracts')
            ->where('id', $contractId)
            ->value('total_amount');

        if ($schedule->status === 'finalized') {
            self::assertSame(
                '1000.00',
                $contractTotal,
                json_encode($results),
            );
        } else {
            self::assertSame('draft', $schedule->status);

            if ($contractTotal === '1100.00') {
                self::assertFalse(
                    DB::table('contractual_billing_schedules')
                        ->where('id', $scheduleId)
                        ->where('status', 'finalized')
                        ->exists(),
                );
            }
        }

        self::assertFalse(
            $schedule->status === 'finalized' && $contractTotal !== '1000.00',
            json_encode($results),
        );
    }

    public function test_c4_two_entitlements_racing_for_same_obligation_preserve_historical_uniqueness(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->draftScheduleWithObligation(
            $tenantId,
            $actorId,
            $customerId,
            $contractId,
        );

        $this->finalizeSchedule(
            $scheduleId,
            $actorId,
        );

        $barrier = $this->temporaryFile('cbs-c4-start-');

        $base = [
            'action' => 'insert_entitlement',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'obligation_id' => $obligationId,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'amount' => '1000.00',
            'economic_date' => '2026-09-01',
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($base + [
            'entitlement_id' => (string) Str::ulid(),
            'operation_id' => (string) Str::ulid(),
        ]);

        $second = $this->startWorker($base + [
            'entitlement_id' => (string) Str::ulid(),
            'operation_id' => (string) Str::ulid(),
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('obligation_id', $obligationId)
                ->count(),
            json_encode($results),
        );

        $failure = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === false,
        ))[0];

        self::assertSame(
            '23505',
            $failure['sqlstate'] ?? null,
            json_encode($results),
        );
    }

    public function test_c5_same_entitlement_operation_race_resolves_to_one_history_row(): void
    {
        [$tenantId, $actorId, $customerId, $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->finalizedActionSource(
            $tenantId,
            $actorId,
            $contractId,
        );

        $operationId = (string) Str::ulid();
        $barrier = $this->temporaryFile('cbs-c5-start-');

        $payload = [
            'action' => 'activate_entitlement_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'obligation_id' => $obligationId,
            'operation_id' => $operationId,
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($payload);
        $second = $this->startWorker($payload);

        touch($barrier);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        self::assertSame(
            2,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('obligation_id', $obligationId)
                ->count(),
        );

        self::assertSame(
            1,
            count(array_unique([
                $results[0]['id'],
                $results[1]['id'],
            ])),
            json_encode($results),
        );
    }

    public function test_c6_different_entitlement_operations_same_obligation_leave_one_historical_truth(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();

        [, $obligationId] = $this->finalizedActionSource(
            $tenantId,
            $actorId,
            $contractId,
        );

        $barrier = $this->temporaryFile('cbs-c6-start-');

        $base = [
            'action' => 'activate_entitlement_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'obligation_id' => $obligationId,
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($base + [
            'operation_id' => (string) Str::ulid(),
        ]);

        $second = $this->startWorker($base + [
            'operation_id' => (string) Str::ulid(),
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('obligation_id', $obligationId)
                ->count(),
        );
    }

    public function test_c7_activation_vs_source_cancellation_never_leaves_terminal_source_with_effective_entitlement(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();

        [$scheduleId, $obligationId] = $this->finalizedActionSource(
            $tenantId,
            $actorId,
            $contractId,
        );

        $entitlementOperation = (string) Str::ulid();
        $correctionOperation = (string) Str::ulid();
        $barrier = $this->temporaryFile('cbs-c7-start-');

        $activation = $this->startWorker([
            'action' => 'activate_entitlement_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'obligation_id' => $obligationId,
            'operation_id' => $entitlementOperation,
            'start_barrier' => $barrier,
        ]);

        $cancellation = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_operation_id' => $correctionOperation,
            'source_correction_reason' => 'C7 cancellation race',
            'entitlement_reversals' => [],
            'start_barrier' => $barrier,
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($activation),
            $this->finishWorker($cancellation),
        ];

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);

        $effectiveCount = DB::table('contractual_billing_entitlements')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $scheduleId)
            ->where('status', 'effective')
            ->count();

        self::assertFalse(
            in_array($schedule->status, ['cancelled', 'superseded'], true)
                && $effectiveCount > 0,
            json_encode($results),
        );
    }

    public function test_c8_two_source_cancellations_racing_leave_one_terminal_correction_identity(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();

        [$scheduleId] = $this->finalizedActionSource(
            $tenantId,
            $actorId,
            $contractId,
        );

        $barrier = $this->temporaryFile('cbs-c8-start-');

        $base = [
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $scheduleId,
            'source_correction_reason' => 'C8 terminal race',
            'entitlement_reversals' => [],
            'start_barrier' => $barrier,
        ];

        $first = $this->startWorker($base + [
            'source_correction_operation_id' => (string) Str::ulid(),
        ]);

        $second = $this->startWorker($base + [
            'source_correction_operation_id' => (string) Str::ulid(),
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        $schedule = DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->first();

        self::assertNotNull($schedule);
        self::assertSame('cancelled', $schedule->status);
        self::assertNotNull(
            $schedule->source_correction_operation_id,
        );
    }

    public function test_c9_source_cancellation_vs_supersession_leaves_exactly_one_terminal_outcome(): void
    {
        [$tenantId, $actorId, , $contractId] = $this->context();

        [$sourceId] = $this->finalizedActionSource(
            $tenantId,
            $actorId,
            $contractId,
        );

        $actor = User::query()->findOrFail($actorId);

        $successorId = app(
            CreateSuccessorContractualBillingSchedule::class,
        )->execute(
            $tenantId,
            $sourceId,
            $actor,
            [
                'schedule_operation_id' => (string) Str::ulid(),
            ],
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
                    'contractual_reference' => 'C9 successor',
                ],
            );

        $barrier = $this->temporaryFile('cbs-c9-start-');

        $cancellation = $this->startWorker([
            'action' => 'cancel_finalized_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'schedule_id' => $sourceId,
            'source_correction_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'C9 cancel',
            'entitlement_reversals' => [],
            'start_barrier' => $barrier,
        ]);

        $supersession = $this->startWorker([
            'action' => 'supersede_source_action',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'source_schedule_id' => $sourceId,
            'successor_schedule_id' => $successorId,
            'source_correction_operation_id' => (string) Str::ulid(),
            'successor_finalization_operation_id' => (string) Str::ulid(),
            'source_correction_reason' => 'C9 supersede',
            'entitlement_reversals' => [],
            'start_barrier' => $barrier,
        ]);

        touch($barrier);

        $results = [
            $this->finishWorker($cancellation),
            $this->finishWorker($supersession),
        ];

        self::assertSame(
            1,
            count(array_filter(
                $results,
                static fn (array $result): bool => $result['ok'] === true,
            )),
            json_encode($results),
        );

        $source = DB::table('contractual_billing_schedules')
            ->where('id', $sourceId)
            ->first();

        $successor = DB::table('contractual_billing_schedules')
            ->where('id', $successorId)
            ->first();

        self::assertNotNull($source);
        self::assertNotNull($successor);

        self::assertContains(
            $source->status,
            ['cancelled', 'superseded'],
        );

        if ($source->status === 'superseded') {
            self::assertSame('finalized', $successor->status);
        } else {
            self::assertSame('draft', $successor->status);
        }
    }

    /**
     * @return array{string,string}
     */
    private function finalizedActionSource(
        string $tenantId,
        int $actorId,
        string $contractId,
    ): array {
        $actor = User::query()->findOrFail($actorId);

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
                'contractual_reference' => 'Concurrency source',
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

    /**
     * @return array{string, int, string, string}
     */
    private function context(): array
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        $tenantId = $this->integrityTenantId($actor);

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
            ['total_amount' => '1000.00'],
        );

        return [
            $tenantId,
            $actor->id,
            (string) $customer->id,
            (string) $contract->id,
        ];
    }

    /**
     * @return array{string, string}
     */
    private function draftScheduleWithObligation(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $contractId,
        string $reference = 'CBS concurrency fixture',
    ): array {
        $scheduleId = (string) Str::ulid();
        $obligationId = (string) Str::ulid();
        $now = now();

        DB::table('contractual_billing_schedules')->insert([
            'id' => $scheduleId,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'schedule_operation_id' => (string) Str::ulid(),
            'billing_model' => 'fixed_date_unconditional_full_schedule',
            'status' => 'draft',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contractual_billing_obligations')->insert([
            'id' => $obligationId,
            'tenant_id' => $tenantId,
            'schedule_id' => $scheduleId,
            'contract_id' => $contractId,
            'obligation_operation_id' => (string) Str::ulid(),
            'customer_id' => $customerId,
            'amount' => '1000.00',
            'currency' => 'SAR',
            'contractual_due_date' => '2026-09-01',
            'trigger_kind' => 'fixed_date_unconditional',
            'contractual_reference' => $reference,
            'draft_membership_status' => 'included',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$scheduleId, $obligationId];
    }

    private function finalizeSchedule(
        string $scheduleId,
        int $actorId,
    ): void {
        DB::table('contractual_billing_schedules')
            ->where('id', $scheduleId)
            ->update([
                'status' => 'finalized',
                'contractual_timezone' => 'Asia/Riyadh',
                'finalization_operation_id' => (string) Str::ulid(),
                'finalized_by' => $actorId,
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new \RuntimeException(
                'Unable to allocate Contractual Billing synchronization file.',
            );
        }

        unlink($path);

        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function waitForFiles(
        array $files,
        int $timeoutMs = 15000,
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

            if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                self::fail(
                    'Timed out waiting for Contractual Billing worker barrier.',
                );
            }
        }
    }

    private function databasePayload(): array
    {
        $connection = config(
            'database.connections.'.DB::getDefaultConnection(),
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

    private function startWorker(array $payload): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/contractual_billing_worker.php'),
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

    private function finishWorker(array $worker): array
    {
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

    private function runWorker(array $payload): array
    {
        return $this->finishWorker(
            $this->startWorker($payload),
        );
    }
}
