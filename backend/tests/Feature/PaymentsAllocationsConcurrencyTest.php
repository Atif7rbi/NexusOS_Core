<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Collections\Exceptions\CollectionHasEffectiveReceivableException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use App\Modules\Receivables\Actions\EstablishCollectionReceivable;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class PaymentsAllocationsConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        parent::tearDown();
    }

    public function test_c1_same_payment_same_receivable_concurrent_application_allocations_preserve_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->workers([
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $receivableId,
                '70.00',
            ),
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $receivableId,
                '70.00',
            ),
        ]);

        self::assertSame(1, $this->successCount($results), json_encode($results));
        self::assertSame(
            '70.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
        );
        self::assertSame(
            '70.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
        );
    }

    public function test_c2_same_payment_different_receivables_concurrent_application_allocations_preserve_payment_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $firstReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $secondReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->workers([
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $firstReceivableId,
                '70.00',
            ),
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $secondReceivableId,
                '70.00',
            ),
        ]);

        self::assertSame(1, $this->successCount($results), json_encode($results));
        self::assertSame(
            '70.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
        );
    }

    public function test_c3_different_payments_same_receivable_concurrent_application_allocations_preserve_receivable_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $firstPaymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $secondPaymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->workers([
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $firstPaymentId,
                $receivableId,
                '70.00',
            ),
            $this->allocatePayload(
                $tenantId,
                $actor->id,
                $secondPaymentId,
                $receivableId,
                '70.00',
            ),
        ]);

        self::assertSame(1, $this->successCount($results), json_encode($results));
        self::assertSame(
            '70.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
        );
    }

    public function test_c4_allocate_vs_cancel_payment_never_leaves_cancelled_payment_with_effective_allocation(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->concurrentApplicationWorkersBehindTenantBarrier(
            $tenantId,
            [
                $this->allocatePayload(
                    $tenantId,
                    $actor->id,
                    $paymentId,
                    $receivableId,
                    '100.00',
                ),
                $this->cancelPaymentPayload(
                    $tenantId,
                    $actor->id,
                    $paymentId,
                ),
            ],
        );

        self::assertSame(
            1,
            $this->successCount($results),
            json_encode($results),
        );

        $paymentStatus = (string) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentId)
            ->value('status');

        $effective = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where('status', 'effective')
            ->exists();

        self::assertFalse(
            $paymentStatus === 'cancelled' && $effective,
            json_encode($results),
        );

        if ($paymentStatus === 'cancelled') {
            self::assertFalse($effective, json_encode($results));
        } else {
            self::assertSame('received', $paymentStatus);
            self::assertTrue($effective, json_encode($results));
        }
    }

    public function test_c5_allocate_vs_cancel_receivable_never_leaves_cancelled_receivable_with_effective_allocation(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->concurrentApplicationWorkersBehindTenantBarrier(
            $tenantId,
            [
                $this->allocatePayload(
                    $tenantId,
                    $actor->id,
                    $paymentId,
                    $receivableId,
                    '100.00',
                ),
                $this->cancelReceivablePayload(
                    $tenantId,
                    $actor->id,
                    $receivableId,
                ),
            ],
        );

        self::assertSame(
            1,
            $this->successCount($results),
            json_encode($results),
        );

        $receivableStatus = (string) DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $receivableId)
            ->value('status');

        $effective = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('receivable_id', $receivableId)
            ->where('status', 'effective')
            ->exists();

        self::assertFalse(
            $receivableStatus === 'cancelled' && $effective,
            json_encode($results),
        );

        if ($receivableStatus === 'cancelled') {
            self::assertFalse($effective, json_encode($results));
        } else {
            self::assertSame('recognized', $receivableStatus);
            self::assertTrue($effective, json_encode($results));
        }
    }

    public function test_c6_cancel_allocation_vs_cancel_payment_preserves_valid_final_state(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $allocationId = $this->allocation(
            $tenantId,
            $actor,
            $paymentId,
            $receivableId,
            '100.00',
        );

        $results = $this->concurrentApplicationWorkersBehindTenantBarrier(
            $tenantId,
            [
                $this->cancelAllocationPayload(
                    $tenantId,
                    $actor->id,
                    $allocationId,
                ),
                $this->cancelPaymentPayload(
                    $tenantId,
                    $actor->id,
                    $paymentId,
                ),
            ],
        );

        self::assertContains(
            $this->successCount($results),
            [1, 2],
            json_encode($results),
        );

        $paymentStatus = (string) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentId)
            ->value('status');

        $allocationStatus = (string) DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $allocationId)
            ->value('status');

        self::assertContains(
            $paymentStatus,
            ['received', 'cancelled'],
        );

        self::assertSame(
            'cancelled',
            $allocationStatus,
            json_encode($results),
        );

        if ($paymentStatus === 'cancelled') {
            self::assertSame(2, $this->successCount($results), json_encode($results));
        }
    }

    public function test_c7_cancel_allocation_vs_cancel_receivable_preserves_valid_final_state(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $allocationId = $this->allocation(
            $tenantId,
            $actor,
            $paymentId,
            $receivableId,
            '100.00',
        );

        $results = $this->concurrentApplicationWorkersBehindTenantBarrier(
            $tenantId,
            [
                $this->cancelAllocationPayload(
                    $tenantId,
                    $actor->id,
                    $allocationId,
                ),
                $this->cancelReceivablePayload(
                    $tenantId,
                    $actor->id,
                    $receivableId,
                ),
            ],
        );

        self::assertContains(
            $this->successCount($results),
            [1, 2],
            json_encode($results),
        );

        $receivableStatus = (string) DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $receivableId)
            ->value('status');

        $allocationStatus = (string) DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $allocationId)
            ->value('status');

        self::assertContains(
            $receivableStatus,
            ['recognized', 'cancelled'],
        );

        self::assertSame(
            'cancelled',
            $allocationStatus,
            json_encode($results),
        );

        if ($receivableStatus === 'cancelled') {
            self::assertSame(2, $this->successCount($results), json_encode($results));
        }
    }

    public function test_c8_same_allocation_operation_same_facts_replays_one_allocation(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $operationId = (string) Str::ulid();

        $payload = [
            'action' => 'allocate',
            'tenant_id' => $tenantId,
            'actor_id' => $actor->id,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_operation_id' => $operationId,
            'amount' => '50.00',
        ];

        $results = $this->workers([
            $payload,
            $payload,
        ]);

        self::assertSame(
            2,
            $this->successCount($results),
            json_encode($results),
        );

        self::assertSame(
            $results[0]['allocation_id'],
            $results[1]['allocation_id'],
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('allocation_operation_id', $operationId)
                ->count(),
        );

        self::assertSame(
            '50.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
        );

        self::assertSame(
            '50.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
        );
    }

    public function test_c9_same_allocation_operation_different_facts_returns_deterministic_operation_conflict(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $firstPaymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $secondPaymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $firstReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $secondReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $operationId = (string) Str::ulid();

        $results = $this->workers([
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $firstPaymentId,
                'receivable_id' => $firstReceivableId,
                'allocation_operation_id' => $operationId,
                'amount' => '50.00',
            ],
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $secondPaymentId,
                'receivable_id' => $secondReceivableId,
                'allocation_operation_id' => $operationId,
                'amount' => '50.00',
            ],
        ]);

        self::assertSame(
            1,
            $this->successCount($results),
            json_encode($results),
        );

        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === false,
        ));

        self::assertCount(1, $failures);

        self::assertSame(
            PaymentsConflict::class,
            $failures[0]['class'],
            json_encode($results),
        );

        self::assertSame(
            'Allocation operation identity was reused with different facts.',
            $failures[0]['message'],
            json_encode($results),
        );

        self::assertNotSame(
            '23505',
            $failures[0]['sqlstate'] ?? null,
            json_encode($results),
        );

        self::assertSame(
            1,
            DB::table('payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('allocation_operation_id', $operationId)
                ->count(),
        );
    }

    public function test_c10_different_operation_ids_competing_for_capacity_return_capacity_conflict_not_idempotency_conflict(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $results = $this->workers([
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '70.00',
            ],
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '70.00',
            ],
        ]);

        self::assertSame(
            1,
            $this->successCount($results),
            json_encode($results),
        );

        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === false,
        ));

        self::assertCount(1, $failures);

        self::assertSame(
            PaymentsConflict::class,
            $failures[0]['class'],
            json_encode($results),
        );

        self::assertSame(
            'Allocation exceeds the remaining capacity.',
            $failures[0]['message'],
            json_encode($results),
        );

        self::assertNotSame(
            'Allocation operation identity was reused with different facts.',
            $failures[0]['message'],
            json_encode($results),
        );

        self::assertSame(
            '70.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
        );

        self::assertSame(
            '70.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
        );
    }

    public function test_c11_direct_sql_concurrent_allocations_cannot_exceed_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $holderReady = $this->allocateSynchronizationFile(
            'payments-c11-holder-ready-',
        );
        $holderRelease = $this->allocateSynchronizationFile(
            'payments-c11-holder-release-',
        );

        $firstReady = $this->allocateSynchronizationFile(
            'payments-c11-first-ready-',
        );
        $secondReady = $this->allocateSynchronizationFile(
            'payments-c11-second-ready-',
        );

        $insertRelease = $this->allocateSynchronizationFile(
            'payments-c11-insert-release-',
        );

        $firstPidFile = $this->allocateSynchronizationFile(
            'payments-c11-first-pid-',
        );
        $secondPidFile = $this->allocateSynchronizationFile(
            'payments-c11-second-pid-',
        );

        $holder = $this->startWorker([
            'action' => 'hold_payment',
            'tenant_id' => $tenantId,
            'payment_id' => $paymentId,
            'ready_file' => $holderReady,
            'release_file' => $holderRelease,
        ]);

        $this->waitForFiles([$holderReady]);

        $first = $this->startWorker([
            'action' => 'direct_insert_allocation',
            'tenant_id' => $tenantId,
            'actor_id' => $actor->id,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_id' => (string) Str::ulid(),
            'allocation_operation_id' => (string) Str::ulid(),
            'amount' => '70.00',
            'ready_file' => $firstReady,
            'release_file' => $insertRelease,
            'pid_file' => $firstPidFile,
        ]);

        $second = $this->startWorker([
            'action' => 'direct_insert_allocation',
            'tenant_id' => $tenantId,
            'actor_id' => $actor->id,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_id' => (string) Str::ulid(),
            'allocation_operation_id' => (string) Str::ulid(),
            'amount' => '70.00',
            'ready_file' => $secondReady,
            'release_file' => $insertRelease,
            'pid_file' => $secondPidFile,
        ]);

        $this->waitForFiles([
            $firstReady,
            $secondReady,
            $firstPidFile,
            $secondPidFile,
        ]);

        touch($insertRelease);

        $firstPid = (int) trim((string) file_get_contents($firstPidFile));
        $secondPid = (int) trim((string) file_get_contents($secondPidFile));

        $this->waitForPostgreSqlLockWaits([
            $firstPid,
            $secondPid,
        ]);

        touch($holderRelease);

        $results = [
            $this->finishWorker($first),
            $this->finishWorker($second),
        ];

        $holderResult = $this->finishWorker($holder);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        self::assertSame(
            1,
            $this->successCount($results),
            json_encode($results),
        );

        self::assertSame(
            '70.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
            json_encode($results),
        );

        self::assertSame(
            '70.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
            json_encode($results),
        );

        $failures = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === false,
        ));

        self::assertCount(1, $failures);

        self::assertSame(
            '23514',
            $failures[0]['sqlstate'] ?? null,
            json_encode($results),
        );
    }

    public function test_c12_direct_sql_eligibility_and_parent_cancellation_bypasses_are_rejected(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $allocationId = $this->allocation(
            $tenantId,
            $actor,
            $paymentId,
            $receivableId,
            '25.00',
        );

        $directPaymentCancellation = $this->workers([
            [
                'action' => 'direct_cancel_payment',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $paymentId,
                'reason' => 'Direct SQL bypass probe',
            ],
        ]);

        self::assertFalse(
            $directPaymentCancellation[0]['ok'],
            json_encode($directPaymentCancellation),
        );

        self::assertSame(
            'received',
            DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->where('id', $paymentId)
                ->value('status'),
        );

        $directReceivableCancellation = $this->workers([
            [
                'action' => 'direct_cancel_receivable',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'receivable_id' => $receivableId,
                'cancelled_at' => now()->toRfc3339String(),
                'reason' => 'Direct SQL bypass probe',
            ],
        ]);

        self::assertFalse(
            $directReceivableCancellation[0]['ok'],
            json_encode($directReceivableCancellation),
        );

        self::assertSame(
            'recognized',
            DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->value('status'),
        );

        self::assertSame(
            'effective',
            DB::table('payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('id', $allocationId)
                ->value('status'),
        );

        $cancelledPaymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        app(CancelPaymentAction::class)->execute(
            $tenantId,
            $cancelledPaymentId,
            $actor,
            'Eligibility fixture',
        );

        $cancelledReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        app(CancelReceivableAction::class)->execute(
            $tenantId,
            $cancelledReceivableId,
            $actor,
            now()->toRfc3339String(),
            'Eligibility fixture',
        );

        $cancelledPaymentInsert = $this->workers([
            $this->directAllocationPayload(
                $tenantId,
                $actor->id,
                $cancelledPaymentId,
                $receivableId,
                '10.00',
            ),
        ]);

        self::assertFalse(
            $cancelledPaymentInsert[0]['ok'],
            json_encode($cancelledPaymentInsert),
        );

        $cancelledReceivableInsert = $this->workers([
            $this->directAllocationPayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $cancelledReceivableId,
                '10.00',
            ),
        ]);

        self::assertFalse(
            $cancelledReceivableInsert[0]['ok'],
            json_encode($cancelledReceivableInsert),
        );

        $otherCustomer = $this->createIntegrityCustomer(
            $tenantId,
            $actor->id,
        );

        $wrongCustomerReceivableId = $this->receivable(
            $tenantId,
            $actor->id,
            (string) $otherCustomer->id,
            '100.00',
        );

        $wrongCustomerInsert = $this->workers([
            $this->directAllocationPayload(
                $tenantId,
                $actor->id,
                $paymentId,
                $wrongCustomerReceivableId,
                '10.00',
            ),
        ]);

        self::assertFalse(
            $wrongCustomerInsert[0]['ok'],
            json_encode($wrongCustomerInsert),
        );

        $usdPaymentId = app(RecordPaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'amount' => '100.00',
                'currency' => 'USD',
                'received_on' => now()->toDateString(),
            ],
        );

        $wrongCurrencyInsert = $this->workers([
            $this->directAllocationPayload(
                $tenantId,
                $actor->id,
                $usdPaymentId,
                $receivableId,
                '10.00',
            ),
        ]);

        self::assertFalse(
            $wrongCurrencyInsert[0]['ok'],
            json_encode($wrongCurrencyInsert),
        );

        $otherTenant = Tenant::factory()->create([
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $otherActor = User::factory()->create([
            'role' => User::ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
        ]);

        TenantUser::factory()
            ->active()
            ->create([
                'tenant_id' => $otherTenant->id,
                'user_id' => $otherActor->id,
            ]);

        $wrongTenantInsert = $this->workers([
            [
                'action' => 'direct_insert_allocation',
                'tenant_id' => (string) $otherTenant->id,
                'actor_id' => $otherActor->id,
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_id' => (string) Str::ulid(),
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '10.00',
            ],
        ]);

        self::assertFalse(
            $wrongTenantInsert[0]['ok'],
            json_encode($wrongTenantInsert),
        );
    }

    public function test_c13_correction_gap_does_not_reserve_replacement_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $receivableId = $this->receivable(
            $tenantId,
            $actor->id,
            $customerId,
            '100.00',
        );

        $originalAllocationId = $this->allocation(
            $tenantId,
            $actor,
            $paymentId,
            $receivableId,
            '60.00',
        );

        $cancelResults = $this->workers([
            $this->cancelAllocationPayload(
                $tenantId,
                $actor->id,
                $originalAllocationId,
            ),
        ]);

        self::assertSame(
            1,
            $this->successCount($cancelResults),
            json_encode($cancelResults),
        );

        $competitorResults = $this->workers([
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '80.00',
            ],
        ]);

        self::assertSame(
            1,
            $this->successCount($competitorResults),
            json_encode($competitorResults),
        );

        $replacementResults = $this->workers([
            [
                'action' => 'allocate',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '60.00',
            ],
        ]);

        self::assertSame(
            0,
            $this->successCount($replacementResults),
            json_encode($replacementResults),
        );

        self::assertSame(
            PaymentsConflict::class,
            $replacementResults[0]['class'],
            json_encode($replacementResults),
        );

        self::assertSame(
            'Allocation exceeds the remaining capacity.',
            $replacementResults[0]['message'],
            json_encode($replacementResults),
        );

        self::assertSame(
            '80.00',
            $this->effectivePaymentTotal($tenantId, $paymentId),
        );

        self::assertSame(
            '80.00',
            $this->effectiveReceivableTotal($tenantId, $receivableId),
        );

        self::assertSame(
            'cancelled',
            DB::table('payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('id', $originalAllocationId)
                ->value('status'),
        );
    }

    public function test_c14_collection_composition_preserves_phase5c_protocol(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        DB::table('tenants')
            ->where('id', $tenantId)
            ->update([
                'currency' => 'SAR',
                'status' => Tenant::STATUS_ACTIVE,
            ]);

        $project = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
        );

        $unit = $this->createIntegrityUnit(
            $tenantId,
            (string) $project->id,
            $actor->id,
            'sold',
        );

        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            $customerId,
            $actor->id,
            'converted',
        );

        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            [
                'total_amount' => '100.00',
            ],
        );

        $collection = Collection::query()->create([
            'tenant_id' => $tenantId,
            'contract_id' => $contract->id,
            'sequence' => 1,
            'title' => 'Phase 6 composition Collection',
            'amount' => '100.00',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => 'scheduled',
            'scheduled_at' => now(),
            'scheduled_by' => $actor->id,
            'created_by' => $actor->id,
        ]);

        $receivableId = app(
            EstablishCollectionReceivable::class,
        )->execute(
            $tenantId,
            $actor,
            (string) $collection->id,
            (string) Str::ulid(),
            now()->toRfc3339String(),
        );

        $paymentId = $this->payment(
            $tenantId,
            $actor,
            $customerId,
            '100.00',
        );

        $allocationId = $this->allocation(
            $tenantId,
            $actor,
            $paymentId,
            $receivableId,
            '100.00',
        );

        $blockedReceivableCancellation = $this->workers([
            $this->cancelReceivablePayload(
                $tenantId,
                $actor->id,
                $receivableId,
            ),
        ]);

        self::assertFalse(
            $blockedReceivableCancellation[0]['ok'],
            json_encode($blockedReceivableCancellation),
        );

        self::assertSame(
            ReceivablesConflict::class,
            $blockedReceivableCancellation[0]['class'],
            json_encode($blockedReceivableCancellation),
        );

        $replacementDueDate = now()->addMonths(2)->toDateString();

        $blockedCollectionAmendment = $this->workers([
            [
                'action' => 'amend_collection',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'contract_id' => (string) $contract->id,
                'collection_id' => (string) $collection->id,
                'title' => 'Replacement Collection',
                'amount' => '100.00',
                'due_date' => $replacementDueDate,
                'reason' => 'Blocked while Receivable remains recognized',
            ],
        ]);

        self::assertFalse(
            $blockedCollectionAmendment[0]['ok'],
            json_encode($blockedCollectionAmendment),
        );

        self::assertSame(
            CollectionHasEffectiveReceivableException::class,
            $blockedCollectionAmendment[0]['class'],
            json_encode($blockedCollectionAmendment),
        );

        self::assertSame(
            'recognized',
            DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->value('status'),
        );

        self::assertSame(
            'scheduled',
            DB::table('collections')
                ->where('tenant_id', $tenantId)
                ->where('id', $collection->id)
                ->value('status'),
        );

        $cancelAllocation = $this->workers([
            $this->cancelAllocationPayload(
                $tenantId,
                $actor->id,
                $allocationId,
            ),
        ]);

        self::assertSame(
            1,
            $this->successCount($cancelAllocation),
            json_encode($cancelAllocation),
        );

        $cancelReceivable = $this->workers([
            $this->cancelReceivablePayload(
                $tenantId,
                $actor->id,
                $receivableId,
            ),
        ]);

        self::assertSame(
            1,
            $this->successCount($cancelReceivable),
            json_encode($cancelReceivable),
        );

        $successfulCollectionAmendment = $this->workers([
            [
                'action' => 'amend_collection',
                'tenant_id' => $tenantId,
                'actor_id' => $actor->id,
                'contract_id' => (string) $contract->id,
                'collection_id' => (string) $collection->id,
                'title' => 'Replacement Collection',
                'amount' => '100.00',
                'due_date' => $replacementDueDate,
                'reason' => 'Collection correction after Receivable cancellation',
            ],
        ]);

        self::assertSame(
            1,
            $this->successCount($successfulCollectionAmendment),
            json_encode($successfulCollectionAmendment),
        );

        self::assertSame(
            'cancelled',
            DB::table('payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('id', $allocationId)
                ->value('status'),
        );

        self::assertSame(
            'cancelled',
            DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->value('status'),
        );

        self::assertSame(
            'cancelled',
            DB::table('collections')
                ->where('tenant_id', $tenantId)
                ->where('id', $collection->id)
                ->value('status'),
        );

        self::assertSame(
            1,
            DB::table('collections')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contract->id)
                ->where('status', 'scheduled')
                ->count(),
        );
    }

    private function context(): array
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        $tenantId = (string) TenantUser::query()
            ->where('user_id', $actor->id)
            ->value('tenant_id');

        $customer = $this->createIntegrityCustomer(
            $tenantId,
            $actor->id,
        );

        return [
            $tenantId,
            $actor,
            (string) $customer->id,
        ];
    }

    private function payment(
        string $tenantId,
        User $actor,
        string $customerId,
        string $amount,
    ): string {
        return app(RecordPaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'amount' => $amount,
                'currency' => 'SAR',
                'received_on' => now()->toDateString(),
            ],
        );
    }

    private function receivable(
        string $tenantId,
        int $actorId,
        string $customerId,
        string $amount,
    ): string {
        $id = (string) Str::ulid();

        DB::table('receivables')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'recognition_operation_id' => (string) Str::ulid(),
            'currency' => 'SAR',
            'recognized_amount' => $amount,
            'due_date' => now()->toDateString(),
            'status' => 'recognized',
            'recognized_at' => now(),
            'recognized_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function allocatePayload(
        string $tenantId,
        int $actorId,
        string $paymentId,
        string $receivableId,
        string $amount,
    ): array {
        return [
            'action' => 'allocate',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_operation_id' => (string) Str::ulid(),
            'amount' => $amount,
        ];
    }

    private function allocation(
        string $tenantId,
        User $actor,
        string $paymentId,
        string $receivableId,
        string $amount,
    ): string {
        return app(AllocatePaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => $amount,
            ],
        );
    }

    private function cancelPaymentPayload(
        string $tenantId,
        int $actorId,
        string $paymentId,
    ): array {
        return [
            'action' => 'cancel_payment',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'payment_id' => $paymentId,
            'reason' => 'Concurrent correction',
        ];
    }

    private function cancelReceivablePayload(
        string $tenantId,
        int $actorId,
        string $receivableId,
    ): array {
        return [
            'action' => 'cancel_receivable',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'receivable_id' => $receivableId,
            'cancelled_at' => now()->toRfc3339String(),
            'reason' => 'Concurrent correction',
        ];
    }

    private function cancelAllocationPayload(
        string $tenantId,
        int $actorId,
        string $allocationId,
    ): array {
        return [
            'action' => 'cancel_allocation',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'allocation_id' => $allocationId,
            'reason' => 'Concurrent correction',
        ];
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

    /** @param list<string> $files */
    private function waitForFiles(array $files): void
    {
        $deadline = microtime(true) + 15;

        while (true) {
            if (array_reduce(
                $files,
                static fn (bool $ready, string $file): bool => $ready && is_file($file),
                true,
            )) {
                return;
            }

            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for Payments concurrency synchronization files.');
            }

            usleep(1_000);
        }
    }

    /** @param list<int> $backendPids */
    private function waitForPostgreSqlLockWaits(array $backendPids): void
    {
        $deadline = microtime(true) + 15;

        while (true) {
            $waiting = DB::table('pg_stat_activity')
                ->whereIn('pid', $backendPids)
                ->where('wait_event_type', 'Lock')
                ->pluck('pid')
                ->map(static fn (mixed $pid): int => (int) $pid)
                ->all();

            sort($waiting);

            $expected = $backendPids;
            sort($expected);

            if ($waiting === $expected) {
                return;
            }

            if (microtime(true) >= $deadline) {
                $this->fail(
                    'Timed out waiting for direct SQL allocation workers to block on PostgreSQL locks.',
                );
            }

            usleep(1_000);
        }
    }

    private function directAllocationPayload(
        string $tenantId,
        int $actorId,
        string $paymentId,
        string $receivableId,
        string $amount,
    ): array {
        return [
            'action' => 'direct_insert_allocation',
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'payment_id' => $paymentId,
            'receivable_id' => $receivableId,
            'allocation_id' => (string) Str::ulid(),
            'allocation_operation_id' => (string) Str::ulid(),
            'amount' => $amount,
        ];
    }

    private function startWorker(array $payload): array
    {
        $connection = config(
            'database.connections.'.DB::getDefaultConnection(),
        );

        $database = array_intersect_key(
            $connection,
            array_flip([
                'host',
                'port',
                'database',
                'username',
                'password',
                'search_path',
            ]),
        );

        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/payments_allocations_worker.php'),
                base64_encode(json_encode(
                    $payload + ['database' => $database],
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

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        self::assertSame(
            0,
            proc_close($worker['process']),
            $stderr,
        );

        $result = json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($result);

        return $result;
    }

    private function concurrentApplicationWorkersBehindTenantBarrier(
        string $tenantId,
        array $payloads,
    ): array {
        $holderReady = $this->allocateSynchronizationFile(
            'payments-tenant-holder-ready-',
        );

        $holderRelease = $this->allocateSynchronizationFile(
            'payments-tenant-holder-release-',
        );

        $startBarrier = $this->allocateSynchronizationFile(
            'payments-application-start-',
        );

        $holder = $this->startWorker([
            'action' => 'hold_tenant',
            'tenant_id' => $tenantId,
            'ready_file' => $holderReady,
            'release_file' => $holderRelease,
        ]);

        $this->waitForFiles([$holderReady]);

        $workers = [];
        $pidFiles = [];

        foreach ($payloads as $index => $payload) {
            $pidFile = $this->allocateSynchronizationFile(
                'payments-application-pid-'.$index.'-',
            );

            $pidFiles[] = $pidFile;

            $workers[] = $this->startWorker(
                $payload + [
                    'start_barrier' => $startBarrier,
                    'pid_file' => $pidFile,
                ],
            );
        }

        $this->waitForFiles($pidFiles);

        touch($startBarrier);

        $backendPids = array_map(
            static fn (string $pidFile): int => (int) trim(
                (string) file_get_contents($pidFile),
            ),
            $pidFiles,
        );

        $this->waitForPostgreSqlLockWaits($backendPids);

        touch($holderRelease);

        $results = array_map(
            fn (array $worker): array => $this->finishWorker($worker),
            $workers,
        );

        $holderResult = $this->finishWorker($holder);

        self::assertTrue(
            $holderResult['ok'],
            json_encode($holderResult),
        );

        return $results;
    }

    private function workers(array $payloads): array
    {
        $connection = config(
            'database.connections.'.DB::getDefaultConnection(),
        );

        $database = array_intersect_key(
            $connection,
            array_flip([
                'host',
                'port',
                'database',
                'username',
                'password',
                'search_path',
            ]),
        );

        $running = [];

        foreach ($payloads as $payload) {
            $process = proc_open(
                [
                    PHP_BINARY,
                    base_path('tests/Support/payments_allocations_worker.php'),
                    base64_encode(json_encode(
                        $payload + ['database' => $database],
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

            $running[] = [
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
            ];
        }

        $results = [];

        foreach ($running as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);

            fclose($worker['stdout']);
            fclose($worker['stderr']);

            self::assertSame(
                0,
                proc_close($worker['process']),
                $stderr,
            );

            $results[] = json_decode(
                $stdout,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return $results;
    }

    private function successCount(array $results): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        ));
    }

    private function effectivePaymentTotal(
        string $tenantId,
        string $paymentId,
    ): string {
        return (string) DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where('status', 'effective')
            ->sum('amount');
    }

    private function effectiveReceivableTotal(
        string $tenantId,
        string $receivableId,
    ): string {
        return (string) DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('receivable_id', $receivableId)
            ->where('status', 'effective')
            ->sum('amount');
    }
}
