<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReceivablesConcurrencyTest extends TestCase
{
    public function test_same_recognition_operation_race_returns_one_receivable(): void
    {
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id, 'type' => 'individual', 'category' => 'buyer', 'status' => 'customer',
            'name' => 'Idempotent debtor', 'phone' => '055'.random_int(1000000, 9999999), 'created_by' => $actor->id,
        ]);
        $recognition = [
            'recognition_operation_id' => (string) Str::ulid(), 'customer_id' => (string) $customer->id,
            'currency' => 'SAR', 'recognized_amount' => '100.00', 'due_date' => '2026-09-30',
            'recognized_at' => '2026-08-26T10:00:00+00:00',
        ];
        $base = ['action' => 'recognize', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'recognition' => $recognition];
        $results = $this->workers([$base, $base]);

        self::assertTrue($results[0]['ok'], json_encode($results));
        self::assertTrue($results[1]['ok'], json_encode($results));
        self::assertSame($results[0]['receivable_id'], $results[1]['receivable_id']);
        self::assertSame(1, DB::table('receivables')->where('tenant_id', $tenant->id)->where('recognition_operation_id', $recognition['recognition_operation_id'])->count());
    }

    public function test_same_receivable_cancellation_race_has_one_winner_and_one_conflict(): void
    {
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id, 'type' => 'individual', 'category' => 'buyer', 'status' => 'customer',
            'name' => 'Concurrency debtor', 'phone' => '056'.random_int(1000000, 9999999), 'created_by' => $actor->id,
        ]);
        $id = app(RecognizeReceivableAction::class)->execute((string) $tenant->id, $actor, [
            'recognition_operation_id' => (string) Str::ulid(),
            'customer_id' => (string) $customer->id, 'currency' => 'SAR', 'recognized_amount' => '100.00',
            'due_date' => '2026-09-30', 'recognized_at' => '2026-08-26T10:00:00+00:00',
        ]);

        $payload = ['tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'receivable_id' => $id, 'cancelled_at' => '2026-08-26T12:00:00+00:00'];
        $results = $this->workers([$payload + ['reason' => 'First correction'], $payload + ['reason' => 'Second correction']]);

        self::assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        $loser = $results[0]['ok'] ? $results[1] : $results[0];
        self::assertSame('App\\Modules\\Receivables\\Exceptions\\ReceivablesConflict', $loser['class']);
        self::assertSame('cancelled', DB::table('receivables')->where('id', $id)->value('status'));
        self::assertContains(DB::table('receivables')->where('id', $id)->value('cancellation_reason'), ['First correction', 'Second correction']);
    }

    private function workers(array $payloads): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $database = array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password']));
        $running = [];
        foreach ($payloads as $payload) {
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/receivables_worker.php'), base64_encode(json_encode($payload + ['database' => $database], JSON_THROW_ON_ERROR))],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }

        $results = [];
        foreach ($running as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
