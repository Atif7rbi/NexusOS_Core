<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesLeadFixtures;
use Tests\TestCase;

final class LeadsConcurrencyTest extends TestCase
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

        $this->assertSame(
            'pgsql',
            DB::getDriverName(),
            'Lead concurrency coverage must run on PostgreSQL.',
        );

        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config(
            "database.connections.{$this->connectionName}"
        );
        $this->schema = 'leads_concurrency_'.strtolower(Str::random(16));

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

    public function test_two_users_claiming_one_unassigned_lead_allow_exactly_one_success(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $firstSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $secondSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $lead = $this->createLead($tenant, $administrator, ['assigned_to' => null]);

        $results = $this->runClaimWorkers($lead, [$firstSales, $secondSales]);
        $successes = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['result']['ok'] === true,
        ));
        $conflicts = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['result']['ok'] === false,
        ));

        $this->assertCount(1, $successes);
        $this->assertCount(1, $conflicts);
        $this->assertSame(409, $conflicts[0]['result']['status']);
        $this->assertSame('lead_claim_conflict', $conflicts[0]['result']['code']);
        $this->assertSame(0, $successes[0]['exit_code']);
        $this->assertSame(1, $conflicts[0]['exit_code']);

        $winnerId = (int) $successes[0]['result']['actor_id'];
        $lead->refresh();
        $this->assertSame($winnerId, $lead->assigned_to);
        $this->assertSame($winnerId, $lead->updated_by);

        $activities = LeadActivity::query()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::Assignment->value)
            ->get();

        $this->assertCount(1, $activities);
        $this->assertNull($activities[0]->payload['from_user_id']);
        $this->assertSame($winnerId, $activities[0]->payload['to_user_id']);
        $this->assertSame('claim', $activities[0]->payload['reason']);
    }

    /**
     * @param list<User> $actors
     * @return list<array{result: array<string, mixed>, exit_code: int}>
     */
    private function runClaimWorkers(Lead $lead, array $actors): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'lead-claim-barrier-');
        if (! is_string($barrier)) {
            $this->fail('Unable to allocate the Lead claim barrier.');
        }
        unlink($barrier);
        $this->temporaryFiles[] = $barrier;

        $script = base_path('tests/Support/lead_claim_worker.php');
        $connectionName = DB::getDefaultConnection();
        $connection = config("database.connections.{$connectionName}");
        $environment = array_filter([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connectionName,
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
        foreach ($actors as $actor) {
            $payload = [
                'tenant_id' => (string) $lead->tenant_id,
                'lead_id' => (string) $lead->id,
                'actor_id' => $actor->id,
                'barrier' => $barrier,
            ];
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
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
            ];
        }

        touch($barrier);

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $exitCode = proc_close($worker['process']);
            $result = json_decode($stdout, true);

            $this->assertIsArray($result, "Lead claim worker did not return JSON: {$stderr}");
            $results[] = [
                'result' => $result,
                'exit_code' => $exitCode,
            ];
        }

        return $results;
    }
}
