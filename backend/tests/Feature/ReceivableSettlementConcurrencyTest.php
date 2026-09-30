<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Settlement\Actions\SettlePaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesReceivableSettlementFixtures;
use Tests\TestCase;

final class ReceivableSettlementConcurrencyTest extends TestCase
{
    use CreatesReceivableSettlementFixtures;

    private array $processes = [];

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (str_contains($path, 'release')) {
                @touch($path);
            }
        }
        foreach ($this->processes as [$process, $stdout, $stderr]) {
            if (is_resource($process)) {
                proc_terminate($process);
                @fclose($stdout);
                @fclose($stderr);
                @proc_close($process);
            }
        }
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_c1_same_operation_same_facts_replays_one_settlement(): void
    {
        $fixture = $this->settlementContext();
        $operation = (string) Str::ulid();
        [$holder, $waiter] = $this->heldRace(
            $this->settlePayload($fixture, $operation, 'settle_hold'),
            $this->settlePayload($fixture, $operation),
            'set_c1_holder', 'set_c1_waiter',
        );
        self::assertTrue($holder['ok'], json_encode($holder));
        self::assertTrue($waiter['ok'], json_encode($waiter));
        self::assertSame($holder['result']['settlement_id'], $waiter['result']['settlement_id']);
        self::assertSame(1, DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->count());
    }

    public function test_c2_same_operation_different_facts_conflicts(): void
    {
        $fixture = $this->settlementContext('500.00');
        $second = $this->secondAllocation($fixture, '500.00');
        $operation = (string) Str::ulid();
        [$holder, $waiter] = $this->heldRace(
            $this->settlePayload($fixture, $operation, 'settle_hold'),
            $this->settlePayload($fixture, $operation, 'settle', $second),
            'set_c2_holder', 'set_c2_waiter',
        );
        self::assertTrue($holder['ok'], json_encode($holder));
        self::assertFalse($waiter['ok'], json_encode($waiter));
        self::assertSame(1, DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->count());
    }

    public function test_c3_two_operations_same_allocation_commit_once(): void
    {
        $fixture = $this->settlementContext();
        [$holder, $waiter] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            $this->settlePayload($fixture, (string) Str::ulid()),
            'set_c3_holder', 'set_c3_waiter',
        );
        self::assertTrue($holder['ok'], json_encode($holder));
        self::assertFalse($waiter['ok'], json_encode($waiter));
        self::assertSame(1, DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->count());
    }

    public function test_c4_c5_competing_double_consumption_preserves_ar_and_cash_capacity(): void
    {
        $fixture = $this->settlementContext();
        [$holder, $waiter] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            $this->settlePayload($fixture, (string) Str::ulid()),
            'set_c45_holder', 'set_c45_waiter',
        );
        self::assertTrue($holder['ok'], json_encode($holder));
        self::assertFalse($waiter['ok'], json_encode($waiter));
        $sum = (string) DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->where('status', 'posted')->sum('amount');
        self::assertSame('1000.00', $sum);
        self::assertLessThanOrEqual(1000.0, (float) $sum);
    }

    public function test_c6_settlement_vs_allocation_cancellation_serializes(): void
    {
        $fixture = $this->settlementContext();
        [$settle, $cancel] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            [
                'action' => 'cancel_allocation', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'allocation_id' => $fixture['allocationId'],
                'reason' => 'Concurrent correction',
            ],
            'set_c6_holder', 'set_c6_waiter',
        );
        self::assertTrue($settle['ok'], json_encode($settle));
        self::assertFalse($cancel['ok'], json_encode($cancel));
        self::assertSame('effective', DB::table('payment_allocations')->where('id', $fixture['allocationId'])->value('status'));
    }

    public function test_c7_settlement_vs_association_cancellation_serializes(): void
    {
        $fixture = $this->settlementContext();
        [$settle, $cancel] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            [
                'action' => 'cancel_association', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'association_id' => $fixture['associationId'],
                'operation_id' => (string) Str::ulid(), 'reason' => 'Concurrent correction',
            ],
            'set_c7_holder', 'set_c7_waiter',
        );
        self::assertTrue($settle['ok'], json_encode($settle));
        self::assertFalse($cancel['ok'], json_encode($cancel));
        self::assertSame('effective', DB::table('receipt_payment_associations')->where('id', $fixture['associationId'])->value('status'));
    }

    public function test_c8_settlement_vs_cash_reversal_preserves_posted_chain(): void
    {
        $fixture = $this->settlementContext();
        [$settle, $reverse] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            [
                'action' => 'reverse_cash', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'receipt_id' => $fixture['receiptId'],
                'cash_posting_id' => $fixture['cashPosting']['posting_id'], 'operation_id' => (string) Str::ulid(),
                'invalidation_operation_id' => (string) Str::ulid(), 'reversal_date' => '2026-08-23',
                'reason' => 'Concurrent correction',
            ],
            'set_c8_holder', 'set_c8_waiter',
        );
        self::assertTrue($settle['ok'], json_encode($settle));
        self::assertFalse($reverse['ok'], json_encode($reverse));
        self::assertSame('posted', DB::table('bank_receipt_cash_postings')->where('id', $fixture['cashPosting']['posting_id'])->value('status'));
    }

    public function test_c9_settlement_vs_ar_source_correction_preserves_posted_ar(): void
    {
        $fixture = $this->settlementContext();
        $entitlement = DB::table('contractual_billing_entitlements')->where('id', $fixture['source']['id'])->firstOrFail();
        [$settle, $correct] = $this->heldRace(
            $this->settlePayload($fixture, (string) Str::ulid(), 'settle_hold'),
            [
                'action' => 'source_correct', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'schedule_id' => $entitlement->schedule_id,
                'entitlement_id' => $entitlement->id, 'operation_id' => (string) Str::ulid(),
                'entitlement_reversal_operation_id' => (string) Str::ulid(),
                'reason' => 'Concurrent correction', 'reference' => 'SET/C9/'.Str::ulid(),
            ],
            'set_c9_holder', 'set_c9_waiter',
        );
        self::assertTrue($settle['ok'], json_encode($settle));
        self::assertFalse($correct['ok'], json_encode($correct));
        self::assertSame('posted', DB::table('receivable_ar_recognitions')->where('id', $fixture['recognitionId'])->value('status'));
    }

    public function test_c10_reversal_vs_allocation_cancellation_serializes(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        [$reverse, $cancel] = $this->heldRace(
            [
                'action' => 'reverse_settlement_hold', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'settlement_id' => $posted['settlement_id'],
                'operation_id' => (string) Str::ulid(), 'reversal_date' => '2026-08-23', 'reason' => 'Correction',
            ],
            [
                'action' => 'cancel_allocation', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $fixture['context']['actor']->id, 'allocation_id' => $fixture['allocationId'],
                'reason' => 'Correction',
            ],
            'set_c10_holder', 'set_c10_waiter',
        );
        self::assertTrue($reverse['ok'], json_encode($reverse));
        self::assertTrue($cancel['ok'], json_encode($cancel));
        self::assertSame('reversed', DB::table('receivable_settlements')->where('id', $posted['settlement_id'])->value('status'));
        self::assertSame('cancelled', DB::table('payment_allocations')->where('id', $fixture['allocationId'])->value('status'));
    }

    public function test_c11_c12_direct_sql_over_settlement_is_rejected(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
            ->where('id', $posted['settlement_id'])->update(['amount' => '1000.01']));
        self::assertSame('1000.00', DB::table('receivable_settlements')->where('id', $posted['settlement_id'])->value('amount'));
    }

    public function test_c13_direct_sql_provenance_and_lifecycle_bypass_is_rejected(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
            ->where('id', $posted['settlement_id'])->update(['receipt_id' => (string) Str::ulid()]));
        $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
            ->where('id', $posted['settlement_id'])->update(['status' => 'reversed']));
    }

    public function test_c14_competing_commit_then_replay_resolves_durable_winner(): void
    {
        $fixture = $this->settlementContext();
        $operation = (string) Str::ulid();
        [$holder, $waiter] = $this->heldRace(
            $this->settlePayload($fixture, $operation, 'settle_hold'),
            $this->settlePayload($fixture, $operation),
            'set_c14_holder', 'set_c14_waiter',
        );
        self::assertTrue($holder['ok'], json_encode($holder));
        self::assertTrue($waiter['ok'], json_encode($waiter));
        self::assertSame($holder['result']['settlement_id'], $waiter['result']['settlement_id']);
        self::assertTrue($waiter['result']['idempotent_replay']);
    }

    public function test_s_a6_settlement_vs_account_archive_serializes_on_exact_account(): void
    {
        $fixture = $this->settlementContext();
        $archiver = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create([
            'tenant_id' => $fixture['context']['tenant_id'], 'user_id' => $archiver->id,
            'status' => TenantUser::STATUS_ACTIVE,
        ]);
        [$archive, $settle] = $this->heldRace(
            [
                'action' => 'archive_account_hold', 'tenant_id' => $fixture['context']['tenant_id'],
                'actor_id' => $archiver->id, 'account_id' => $fixture['accounts']['ar'],
            ],
            $this->settlePayload($fixture, (string) Str::ulid()),
            'set_sa6_archive_holder', 'set_sa6_settle_waiter',
        );
        self::assertTrue($archive['ok'], json_encode($archive));
        self::assertFalse($settle['ok'], json_encode($settle));
        self::assertSame(0, DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->count());
        self::assertSame('archived', DB::table('accounts')->where('id', $fixture['accounts']['ar'])->value('status'));
    }

    private function secondAllocation(array $fixture, string $amount): string
    {
        return app(AllocatePaymentAction::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            [
                'payment_id' => $fixture['paymentId'], 'receivable_id' => $fixture['receivableId'],
                'allocation_operation_id' => (string) Str::ulid(), 'amount' => $amount,
            ],
        );
    }

    private function settlePayload(array $fixture, string $operation, string $action = 'settle', ?string $allocation = null): array
    {
        return [
            'action' => $action, 'tenant_id' => $fixture['context']['tenant_id'],
            'actor_id' => $fixture['context']['actor']->id,
            'allocation_id' => $allocation ?? $fixture['allocationId'], 'operation_id' => $operation,
        ];
    }

    private function heldRace(array $holderPayload, array $waiterPayload, string $holderName, string $waiterName): array
    {
        $ready = $this->path('ready');
        $release = $this->path('release');
        $holder = $this->start($holderPayload + [
            'application_name' => $holderName, 'ready_file' => $ready,
            'release_file' => $release, 'barrier_timeout_ms' => 20000,
        ]);
        $this->waitFile($ready);
        $waiter = $this->start($waiterPayload + ['application_name' => $waiterName]);
        $this->waitBlocked($waiterName, $holderName);
        touch($release);

        return [$this->finish($holder), $this->finish($waiter)];
    }

    private function start(array $payload): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $database = array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password']));
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/receivable_settlement_worker.php'), base64_encode(json_encode($payload + ['database' => $database], JSON_THROW_ON_ERROR))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $worker = [$process, $pipes[1], $pipes[2]];
        $this->processes[] = $worker;

        return $worker;
    }

    private function finish(array $worker): array
    {
        [$process, $stdout, $stderr] = $worker;
        $output = stream_get_contents($stdout);
        $error = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        self::assertSame(0, proc_close($process), $error);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitBlocked(string $waiterName, string $holderName): void
    {
        $deadline = microtime(true) + 15;
        do {
            $row = DB::selectOne(
                'SELECT cardinality(pg_blocking_pids(w.pid)) blocked
                 FROM pg_stat_activity w
                 WHERE w.application_name=?
                   AND EXISTS (
                     SELECT 1 FROM pg_stat_activity h
                     WHERE h.application_name=?
                       AND h.pid=ANY(pg_blocking_pids(w.pid))
                   )',
                [$waiterName, $holderName],
            );
            if ($row !== null && (int) $row->blocked > 0) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail("{$waiterName} did not block behind {$holderName}.");
    }

    private function waitFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                self::fail("Timed out waiting for {$path}");
            }
            usleep(10_000);
        }
    }

    private function path(string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), "settlement-{$kind}-");
        if ($path === false) {
            throw new \RuntimeException('Unable to create Settlement barrier path.');
        }
        unlink($path);
        $this->paths[] = $path;

        return $path;
    }

    private function assertSqlRejected(callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted concurrent Settlement bypass.');
        } catch (QueryException $exception) {
            self::assertContains((string) ($exception->errorInfo[0] ?? ''), ['23503', '23514', '23505', '55000', '42501']);
        } finally {
            DB::rollBack();
        }
    }
}
