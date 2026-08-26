<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Collections\Models\Collection;
use App\Modules\Customers\Models\Customer;
use App\Modules\ReceivableAccounting\Services\ReceivableAccountingIntegration;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class ReceivablesConcurrencyTest extends TestCase
{
    use CreatesDomainIntegrityFixtures;

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

    public function test_recognition_lock_timeout_is_not_resolved_as_operation_replay(): void
    {
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id, 'type' => 'individual', 'category' => 'buyer', 'status' => 'customer',
            'name' => 'Busy debtor', 'phone' => '052'.random_int(1000000, 9999999), 'created_by' => $actor->id,
        ]);
        $recognition = $this->recognition((string) $customer->id);

        $results = $this->workers([
            ['action' => 'hold_membership', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'hold_ms' => 5500],
            ['action' => 'recognize', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'recognition' => $recognition, 'pre_delay_ms' => 100],
        ]);

        self::assertTrue($results[0]['ok'], json_encode($results));
        self::assertFalse($results[1]['ok'], json_encode($results));
        self::assertSame('App\\Modules\\Receivables\\Exceptions\\ReceivablesConflict', $results[1]['class']);
        self::assertSame('Receivables is busy. Retry the operation.', $results[1]['message']);
        self::assertFalse(DB::table('receivables')->where('recognition_operation_id', $recognition['recognition_operation_id'])->exists());
    }

    public function test_integrated_same_operation_race_commits_one_receivable_and_one_journal(): void
    {
        [$tenant, $first, $customer, $asset, $revenue] = $this->accountingReady();
        $second = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $second->id]);
        $recognition = $this->recognition((string) $customer->id);
        $payload = [
            'action' => 'integrated_recognize', 'tenant_id' => (string) $tenant->id,
            'recognition' => $recognition, 'entry_date' => '2026-09-01', 'description' => 'Concurrent integration',
            'lines' => [
                ['account_id' => $asset, 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $revenue, 'debit' => '0', 'credit' => '100.00'],
            ],
        ];

        $results = $this->workers([$payload + ['actor_id' => $first->id], $payload + ['actor_id' => $second->id]]);

        self::assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        self::assertSame(['committed', 'committed'], array_column($results, 'status'));
        self::assertSame($results[0]['receivable_id'], $results[1]['receivable_id']);
        self::assertSame($results[0]['journal_entry_id'], $results[1]['journal_entry_id']);
        self::assertSame(1, DB::table('receivables')->where('tenant_id', $tenant->id)->where('recognition_operation_id', $recognition['recognition_operation_id'])->count());
        self::assertSame(1, DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', ReceivableAccountingIntegration::SOURCE_TYPE)->where('source_id', $results[0]['receivable_id'])->count());
    }

    public function test_integrated_cancellation_waits_for_actor_membership_before_locking_receivable(): void
    {
        [$tenant, $actor, $customer, $asset, $revenue] = $this->accountingReady();
        $posted = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $this->recognition((string) $customer->id), '2026-09-01', 'Lock order',
            [new JournalLineData($asset, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')],
        ));

        $results = $this->workers([
            ['action' => 'deactivate_membership', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'hold_ms' => 900],
            ['action' => 'integrated_cancel', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'receivable_id' => $posted['receivable_id'], 'cancelled_at' => '2026-09-02T10:00:00+00:00', 'reason' => 'Lock order test', 'reversal_date' => '2026-09-02', 'pre_delay_ms' => 100],
            ['action' => 'probe_receivable_lock', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id, 'receivable_id' => $posted['receivable_id'], 'pre_delay_ms' => 300],
        ]);

        self::assertTrue($results[0]['ok'], json_encode($results));
        self::assertFalse($results[1]['ok'], json_encode($results));
        self::assertSame('App\\Modules\\Receivables\\Exceptions\\ReceivablesAccessDenied', $results[1]['class']);
        self::assertTrue($results[2]['ok'], 'Cancellation locked the Receivable before transactional actor authorization: '.json_encode($results));
        self::assertSame('recognized', DB::table('receivables')->where('id', $posted['receivable_id'])->value('status'));
        self::assertFalse(DB::table('journal_entries')->where('reverses_journal_entry_id', $posted['journal_entry_id'])->exists());
    }

    public function test_concurrent_collection_establishment_creates_at_most_one_effective_receivable(): void
    {
        [$tenant, $actor, $collection] = $this->scheduledCollectionContext();
        $base = [
            'action' => 'establish_collection', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id,
            'collection_id' => (string) $collection->id, 'recognized_at' => '2026-08-26T10:00:00+00:00',
        ];
        $results = $this->workers([
            $base + ['recognition_operation_id' => (string) Str::ulid()],
            $base + ['recognition_operation_id' => (string) Str::ulid()],
        ]);

        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'])), json_encode($results));
        self::assertSame(1, DB::table('receivables')->where('tenant_id', $tenant->id)->where('collection_id', $collection->id)->where('status', 'recognized')->count());
    }

    public function test_collection_amendment_and_establishment_have_one_winner_without_deadlock(): void
    {
        [$tenant, $actor, $collection] = $this->scheduledCollectionContext();
        $results = $this->workers([
            [
                'action' => 'establish_collection', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id,
                'collection_id' => (string) $collection->id, 'recognition_operation_id' => (string) Str::ulid(),
                'recognized_at' => '2026-08-26T10:00:00+00:00',
            ],
            [
                'action' => 'amend_collection', 'tenant_id' => (string) $tenant->id, 'actor_id' => $actor->id,
                'contract_id' => (string) $collection->contract_id, 'expected_active_collection_ids' => [(string) $collection->id],
                'cancellation_reason' => 'Concurrent correction',
                'lines' => [['sequence' => 1, 'title' => 'Replacement', 'amount' => '100.00', 'due_date' => '2026-09-30']],
            ],
        ]);

        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'])), json_encode($results));
    }

    private function scheduledCollectionContext(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR', 'status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id]);
        $customer = $this->createIntegrityCustomer((string) $tenant->id, $actor->id);
        $project = $this->createIntegrityProject((string) $tenant->id, $actor->id);
        $unit = $this->createIntegrityUnit((string) $tenant->id, (string) $project->id, $actor->id, 'sold');
        $reservation = $this->createIntegrityReservation((string) $tenant->id, (string) $unit->id, (string) $customer->id, $actor->id, 'converted');
        $contract = $this->createIntegrityContract((string) $tenant->id, (string) $reservation->id, $actor->id, 'active', ['total_amount' => '100.00']);
        $collection = Collection::query()->create([
            'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'sequence' => 1, 'title' => 'Concurrent Collection',
            'amount' => '100.00', 'due_date' => '2026-09-30', 'status' => 'scheduled', 'scheduled_at' => now(),
            'scheduled_by' => $actor->id, 'created_by' => $actor->id,
        ]);

        return [$tenant, $actor, $collection];
    }

    private function accountingReady(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR', 'status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id, 'type' => 'individual', 'category' => 'buyer', 'status' => 'customer',
            'name' => 'Integrated concurrency debtor', 'phone' => '053'.random_int(1000000, 9999999), 'created_by' => $actor->id,
        ]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $asset = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('1160', 'asset', 'current_asset'));
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('4160', 'revenue', 'operating_revenue'));

        return [$tenant, $actor, $customer, $asset, $revenue];
    }

    private function recognition(string $customerId): array
    {
        return [
            'recognition_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId,
            'contract_id' => null, 'collection_id' => null, 'currency' => 'SAR', 'recognized_amount' => '100.00',
            'due_date' => '2026-09-30', 'recognized_at' => '2026-08-26T10:00:00+00:00',
        ];
    }

    private function account(string $code, string $type, string $classification): array
    {
        return ['code' => $code, 'name' => 'Account '.$code, 'description' => null, 'kind' => 'posting', 'account_type' => $type, 'classification' => $classification, 'parent_id' => null];
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
