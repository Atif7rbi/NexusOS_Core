<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Exceptions\CollectionScheduleChangedSinceLoadedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesCollectionScheduleFixtures;
use Tests\TestCase;

final class CollectionsConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesCollectionScheduleFixtures;

    /** @var array<string, mixed> */
    private ?array $originalConnectionConfiguration = null;

    private ?string $connectionName = null;

    private ?string $schema = null;

    /** @var array<int, string> */
    private array $temporaryAuditFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Collection concurrency coverage requires PostgreSQL row locking.');
        }

        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config("database.connections.{$this->connectionName}");
        $this->schema = 'collections_concurrency_'.strtolower(Str::random(16));

        DB::statement("CREATE SCHEMA {$this->schema}");

        $isolatedConnection = $this->originalConnectionConfiguration;
        $isolatedConnection['search_path'] = $this->schema;

        config(["database.connections.{$this->connectionName}" => $isolatedConnection]);
        DB::purge($this->connectionName);
        DB::reconnect($this->connectionName);

        Artisan::call('migrate', [
            '--database' => $this->connectionName,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->temporaryAuditFiles as $temporaryAuditFile) {
                if (is_file($temporaryAuditFile)) {
                    unlink($temporaryAuditFile);
                }
            }

            if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
                DB::disconnect($this->connectionName);
                config(["database.connections.{$this->connectionName}" => $this->originalConnectionConfiguration]);
                DB::purge($this->connectionName);
                DB::reconnect($this->connectionName);
            }

            if ($this->schema !== null) {
                DB::statement("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_finalization_attempts_leave_one_valid_scheduled_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveDraft($context);

        $results = $this->runWorkers([
            $this->finalizePayload($context),
            $this->finalizePayload($context),
        ]);

        $this->assertSame(1, $this->successCount($results));
        $this->assertValidScheduledAggregate($context);
    }

    public function test_two_amendment_attempts_from_one_generation_allow_exactly_one_success(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveDraft($context);
        (new FinalizeCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
        );
        $expectedIds = $this->activeCollectionIds($context);
        $auditLogPath = tempnam(sys_get_temp_dir(), 'collections-amend-audit-');
        if (! is_string($auditLogPath)) {
            $this->fail('Unable to create the temporary Collection audit log.');
        }
        $this->temporaryAuditFiles[] = $auditLogPath;

        $results = $this->runWorkers([
            $this->amendPayload(
                $context,
                $expectedIds,
                '400.00',
                '600.00',
                'التعديل الأول',
                $auditLogPath,
            ),
            $this->amendPayload(
                $context,
                $expectedIds,
                '300.00',
                '700.00',
                'التعديل الثاني',
                $auditLogPath,
            ),
        ]);

        $this->assertSame(1, $this->successCount($results));
        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['result']['ok'] === false,
        ));
        $this->assertCount(1, $failures);
        $this->assertSame(
            CollectionScheduleChangedSinceLoadedException::class,
            $failures[0]['result']['class'],
        );
        $this->assertSame(4, DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->count());
        $auditEvents = file(
            $auditLogPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );
        $this->assertIsArray($auditEvents);
        $this->assertCount(1, $auditEvents);
        $this->assertSame('collection_schedule_amended', $auditEvents[0]);
        $this->assertValidScheduledAggregate($context);
    }

    public function test_finalization_and_amendment_competing_on_one_contract_leave_a_valid_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveDraft($context);

        $results = $this->runWorkers([
            $this->finalizePayload($context),
            $this->amendPayload(
                $context,
                [(string) Str::ulid()],
                '450.00',
                '550.00',
                'تعديل متزامن',
            ),
        ]);

        $finalization = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['action'] === 'finalize',
        ));

        $this->assertCount(1, $finalization);
        $this->assertTrue($finalization[0]['result']['ok']);
        $amendment = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['action'] === 'amend',
        ));
        $this->assertCount(1, $amendment);
        $this->assertFalse($amendment[0]['result']['ok']);
        $this->assertValidScheduledAggregate($context);
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     */
    private function saveDraft(array $context): void
    {
        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01'),
                $this->collectionLine(null, 2, '500.00', '2026-09-01'),
            ],
        );
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     * @return array<string, mixed>
     */
    private function finalizePayload(array $context): array
    {
        return [
            'action' => 'finalize',
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'actor_id' => $context['user_id'],
        ];
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     * @return array<string, mixed>
     */
    private function amendPayload(
        array $context,
        array $expectedActiveCollectionIds,
        string $firstAmount,
        string $secondAmount,
        string $reason,
        ?string $auditLogPath = null,
    ): array {
        return [
            'action' => 'amend',
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'actor_id' => $context['user_id'],
            'expected_active_collection_ids' => $expectedActiveCollectionIds,
            'audit_log_path' => $auditLogPath,
            'cancellation_reason' => $reason,
            'lines' => [
                [
                    'sequence' => 1,
                    'title' => 'قسط متزامن أول',
                    'amount' => $firstAmount,
                    'due_date' => '2026-08-01',
                ],
                [
                    'sequence' => 2,
                    'title' => 'قسط متزامن ثان',
                    'amount' => $secondAmount,
                    'due_date' => '2026-09-01',
                ],
            ],
        ];
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     * @return array<int, string>
     */
    private function activeCollectionIds(array $context): array
    {
        return DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Scheduled->value)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array{action: string, result: array<string, mixed>, exit_code: int}>
     */
    private function runWorkers(array $payloads): array
    {
        $workers = [];
        $script = base_path('tests/Support/collection_action_worker.php');
        $connectionName = DB::getDefaultConnection();
        $connection = config("database.connections.{$connectionName}");
        $environment = array_filter([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => getenv('APP_CONFIG_CACHE') ?: null,
            'NEXUSOS_TEST_DATABASE' => getenv('NEXUSOS_TEST_DATABASE') ?: null,
            'DB_CONNECTION' => $connectionName,
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => isset($connection['port']) ? (string) $connection['port'] : null,
            'DB_DATABASE' => $connection['database'] ?? null,
            'DB_USERNAME' => $connection['username'] ?? null,
            'DB_PASSWORD' => $connection['password'] ?? null,
            'DB_SSLMODE' => $connection['sslmode'] ?? null,
            'DB_TIMEZONE' => $connection['timezone'] ?? null,
            'COLLECTIONS_CONCURRENCY_SCHEMA' => $this->schema,
        ], static fn (mixed $value): bool => $value !== null);

        foreach ($payloads as $payload) {
            $process = proc_open(
                [PHP_BINARY, $script, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                $environment,
            );

            $this->assertIsResource($process);
            fclose($pipes[0]);

            $workers[] = [
                'action' => $payload['action'],
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
            ];
        }

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);

            $exitCode = proc_close($worker['process']);
            $result = json_decode($stdout, true);

            $this->assertIsArray($result, "Collection worker did not return JSON: {$stderr}");

            $results[] = [
                'action' => $worker['action'],
                'result' => $result,
                'exit_code' => $exitCode,
            ];
        }

        return $results;
    }

    /**
     * @param  array<int, array{action: string, result: array<string, mixed>, exit_code: int}>  $results
     */
    private function successCount(array $results): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => $result['result']['ok'] === true,
        ));
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     */
    private function assertValidScheduledAggregate(array $context): void
    {
        $active = DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Scheduled->value)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $active);
        $this->assertSame([1, 2], $active->pluck('sequence')->all());
        $this->assertSame('1000.00', number_format(
            (float) $active->sum('amount'),
            2,
            '.',
            '',
        ));
        $this->assertSame(0, DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Draft->value)
            ->count());
    }
}
