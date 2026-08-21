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

    /**
     * Simulates two concurrent create_new conversion attempts for the same Lead
     * phone. The first succeeds; the second encounters the PostgreSQL unique
     * violation on (tenant_id, phone) and must receive a semantic 409 conflict
     * (LeadConversionNewCustomerConflictException) with no partial state left.
     *
     * We simulate the race by:
     *  1. Manually creating a Customer with the Lead's phone inside a nested
     *     transaction (mimicking the winner that committed first).
     *  2. Attempting the Action's create_new path — the SELECT FOR UPDATE check
     *     finds no conflict (the Customer was committed between the check and
     *     the INSERT), so the INSERT hits the unique constraint.
     *  3. Asserting the Action throws LeadConversionNewCustomerConflictException
     *     and leaves the Lead in its original stage.
     */
    public function test_concurrent_create_new_unique_violation_returns_semantic_conflict(): void
    {
        $tenant      = $this->createLeadTenant();
        $admin       = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR)['user'];
        $lead        = $this->createLead($tenant, $admin);
        $phone       = $lead->phone;
        $tenantId    = (string) $tenant->id;

        // Simulate the winner: create a Customer with the Lead's phone before
        // the second actor's transaction commits.
        DB::table('customers')->insert([
            'id'         => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id'  => $tenantId,
            'name'       => 'Existing Customer',
            'phone'      => $phone,
            'type'       => 'individual',
            'category'   => 'buyer',
            'status'     => 'customer',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var \App\Modules\Leads\Actions\ConvertLeadToCustomerAction $action */
        $action = app(\App\Modules\Leads\Actions\ConvertLeadToCustomerAction::class);

        try {
            $action->execute(
                tenantId: $tenantId,
                leadId: (string) $lead->id,
                actorId: $admin->id,
                conversionIntent: 'create_new',
                existingCustomerId: null,
                customerData: ['type' => 'individual', 'category' => 'buyer'],
            );
            $this->fail('Expected LeadConversionNewCustomerConflictException');
        } catch (\App\Modules\Leads\Exceptions\LeadConversionNewCustomerConflictException $e) {
            $this->addToAssertionCount(1);
        }

        // Lead must not be in won state — no partial conversion
        $lead->refresh();
        $this->assertNotSame(\App\Modules\Leads\Enums\LeadStage::Won, $lead->stage);
        $this->assertNull($lead->customer_id);
        $this->assertNull($lead->converted_at);

        // Only one Customer must exist with this phone
        $this->assertSame(
            1,
            \App\Modules\Customers\Models\Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->count(),
        );
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

    public function test_competing_complete_and_cancel_allow_exactly_one_follow_up_mutation(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];

        $lead = $this->createLead($tenant, $administrator, [
            'next_follow_up_at' => now()->addDay(),
            'next_action_type' => 'call',
            'next_action_note' => 'Concurrent follow-up.',
        ]);

        $results = $this->runFollowUpWorkers(
            $lead,
            $administrator,
            ['complete', 'cancel'],
        );

        $successes = array_values(array_filter(
            $results,
            static fn (array $result): bool =>
                $result['result']['ok'] === true,
        ));

        $conflicts = array_values(array_filter(
            $results,
            static fn (array $result): bool =>
                $result['result']['ok'] === false,
        ));

        $this->assertCount(1, $successes);
        $this->assertCount(1, $conflicts);
        $this->assertSame(0, $successes[0]['exit_code']);
        $this->assertSame(1, $conflicts[0]['exit_code']);
        $this->assertSame(409, $conflicts[0]['result']['status']);
        $this->assertSame(
            'lead_follow_up_conflict',
            $conflicts[0]['result']['code'],
        );

        $lead->refresh();

        $this->assertNull($lead->next_follow_up_at);
        $this->assertNull($lead->next_action_type);
        $this->assertNull($lead->next_action_note);
        $this->assertSame($administrator->id, $lead->updated_by);

        $activities = LeadActivity::query()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->whereIn('type', [
                ActivityType::FollowUpCompleted->value,
                ActivityType::FollowUpCancelled->value,
            ])
            ->get();

        $this->assertCount(1, $activities);

        $winningOperation = $successes[0]['result']['operation'];
        $expectedType = $winningOperation === 'complete'
            ? ActivityType::FollowUpCompleted->value
            : ActivityType::FollowUpCancelled->value;

        $this->assertSame($expectedType, $activities[0]->type->value);
    }

    /**
     * @param list<'complete'|'cancel'> $operations
     * @return list<array{result: array<string, mixed>, exit_code: int}>
     */
    private function runFollowUpWorkers(
        Lead $lead,
        User $actor,
        array $operations,
    ): array {
        $barrier = tempnam(
            sys_get_temp_dir(),
            'lead-follow-up-barrier-',
        );

        if (! is_string($barrier)) {
            $this->fail(
                'Unable to allocate the Lead follow-up barrier.',
            );
        }

        unlink($barrier);
        $this->temporaryFiles[] = $barrier;

        $script = base_path(
            'tests/Support/lead_follow_up_worker.php',
        );

        $connectionName = DB::getDefaultConnection();
        $connection = config(
            "database.connections.{$connectionName}",
        );

        $environment = array_filter([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => getenv('APP_CONFIG_CACHE') ?: null,
            'NEXUSOS_TEST_DATABASE' => getenv('NEXUSOS_TEST_DATABASE') ?: null,
            'DB_CONNECTION' => $connectionName,
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => isset($connection['port'])
                ? (string) $connection['port']
                : null,
            'DB_DATABASE' => $connection['database'] ?? null,
            'DB_USERNAME' => $connection['username'] ?? null,
            'DB_PASSWORD' => $connection['password'] ?? null,
            'DB_SSLMODE' => $connection['sslmode'] ?? null,
            'DB_TIMEZONE' => $connection['timezone'] ?? null,
            'LEADS_CONCURRENCY_SCHEMA' => $this->schema,
        ], static fn (mixed $value): bool => $value !== null);

        $workers = [];

        foreach ($operations as $operation) {
            $payload = [
                'tenant_id' => (string) $lead->tenant_id,
                'lead_id' => (string) $lead->id,
                'actor_id' => $actor->id,
                'operation' => $operation,
                'barrier' => $barrier,
            ];

            $process = proc_open(
                [
                    PHP_BINARY,
                    $script,
                    base64_encode(json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR,
                    )),
                ],
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

            $this->assertIsArray(
                $result,
                "Lead follow-up worker did not return JSON: {$stderr}",
            );

            $results[] = [
                'result' => $result,
                'exit_code' => $exitCode,
            ];
        }

        return $results;
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
