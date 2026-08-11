<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesLeadFixtures;
use Tests\TestCase;

final class LeadsConversionConcurrencyTest extends TestCase
{
    use CreatesLeadFixtures;

    /** @var array<string, mixed>|null */
    private ?array $originalConnectionConfiguration = null;

    private ?string $connectionName = null;

    private ?string $schema = null;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::getDriverName());
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config(
            "database.connections.{$this->connectionName}"
        );
        $this->schema = 'lead_conversion_concurrency_'.strtolower(Str::random(16));

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

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION delay_customer_insert_for_conversion_test()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM pg_sleep(0.75);
                RETURN NEW;
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER delay_customer_insert_for_conversion_test
            BEFORE INSERT ON customers
            FOR EACH ROW
            EXECUTE PROCEDURE delay_customer_insert_for_conversion_test()
        SQL);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }

            if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
                DB::disconnect($this->connectionName);
                config([
                    "database.connections.{$this->connectionName}"
                        => $this->originalConnectionConfiguration,
                ]);
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

    public function test_parallel_create_new_translates_the_unique_violation_without_partial_mutation(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $phone = $this->nextLeadPhone();
        $firstLead = $this->createLead($tenant, $administrator, ['phone' => $phone]);
        $secondLead = $this->createLead($tenant, $administrator, ['phone' => $phone]);

        $results = $this->runWorkers($administrator, [$firstLead, $secondLead]);
        $successes = array_values(array_filter(
            $results,
            static fn (array $worker): bool => $worker['result']['ok'] === true,
        ));
        $conflicts = array_values(array_filter(
            $results,
            static fn (array $worker): bool => $worker['result']['ok'] === false,
        ));

        $this->assertCount(1, $successes);
        $this->assertCount(1, $conflicts);
        $this->assertSame(0, $successes[0]['exit_code']);
        $this->assertSame(1, $conflicts[0]['exit_code']);
        $this->assertSame(409, $conflicts[0]['result']['status']);
        $this->assertSame(
            'lead_conversion_new_customer_conflict',
            $conflicts[0]['result']['code'],
        );
        $this->assertSame(
            'unique_violation',
            $conflicts[0]['result']['conflict_source'],
            'The losing worker must reach the actual unique-violation path.',
        );

        $customer = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->sole();

        $winner = $this->leadByWorkerResult(
            $successes[0]['result']['lead_id'],
            $firstLead,
            $secondLead,
        );
        $loser = $this->leadByWorkerResult(
            $conflicts[0]['result']['lead_id'],
            $firstLead,
            $secondLead,
        );
        $winner->refresh();
        $loser->refresh();

        $this->assertSame(LeadStage::Won, $winner->stage);
        $this->assertSame($customer->id, $winner->customer_id);
        $this->assertNotSame(LeadStage::Won, $loser->stage);
        $this->assertNull($loser->customer_id);
        $this->assertNull($loser->converted_at);
        $this->assertNull($loser->converted_by);
        $this->assertNull($loser->conversion_mode);
        $this->assertSame(
            0,
            LeadActivity::query()
                ->where('lead_id', $loser->id)
                ->where('type', 'stage_change')
                ->count(),
        );
    }

    /**
     * @param  list<Lead>  $leads
     * @return list<array{result: array<string, mixed>, exit_code: int}>
     */
    private function runWorkers(User $actor, array $leads): array
    {
        $releaseFile = $this->allocateSynchronizationFile('lead-conversion-release-');
        $readyFiles = [
            $this->allocateSynchronizationFile('lead-conversion-ready-a-'),
            $this->allocateSynchronizationFile('lead-conversion-ready-b-'),
        ];
        $script = base_path('tests/Support/lead_conversion_worker.php');
        $connection = config("database.connections.{$this->connectionName}");
        $environment = array_filter([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => getenv('APP_CONFIG_CACHE') ?: null,
            'DB_CONNECTION' => $this->connectionName,
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => isset($connection['port']) ? (string) $connection['port'] : null,
            'DB_DATABASE' => $connection['database'] ?? null,
            'DB_USERNAME' => $connection['username'] ?? null,
            'DB_PASSWORD' => $connection['password'] ?? null,
            'DB_SSLMODE' => $connection['sslmode'] ?? null,
            'DB_TIMEZONE' => $connection['timezone'] ?? null,
            'LEADS_CONCURRENCY_SCHEMA' => $this->schema,
        ], static fn (mixed $value): bool => $value !== null);

        $workers = [];
        foreach ($leads as $index => $lead) {
            $payload = [
                'tenant_id' => (string) $lead->tenant_id,
                'lead_id' => (string) $lead->id,
                'actor_id' => $actor->id,
                'ready_file' => $readyFiles[$index],
                'release_file' => $releaseFile,
            ];
            $process = proc_open(
                [PHP_BINARY, $script, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
            ];
        }

        $this->waitForReadyFiles($readyFiles);
        touch($releaseFile);

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $exitCode = proc_close($worker['process']);
            $result = json_decode($stdout, true);
            $this->assertIsArray(
                $result,
                "Conversion worker did not return JSON. stderr: {$stderr}",
            );
            $results[] = ['result' => $result, 'exit_code' => $exitCode];
        }

        return $results;
    }

    private function allocateSynchronizationFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if (! is_string($file)) {
            $this->fail('Unable to allocate a synchronization file.');
        }
        unlink($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }

    /** @param list<string> $readyFiles */
    private function waitForReadyFiles(array $readyFiles): void
    {
        $deadline = microtime(true) + 15;
        while (true) {
            if (array_reduce(
                $readyFiles,
                static fn (bool $ready, string $file): bool => $ready && is_file($file),
                true,
            )) {
                return;
            }

            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for conversion workers to become ready.');
            }
            usleep(1_000);
        }
    }

    private function leadByWorkerResult(string $leadId, Lead $first, Lead $second): Lead
    {
        return $leadId === (string) $first->id ? $first : $second;
    }
}
