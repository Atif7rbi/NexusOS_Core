<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAllocationAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class PaymentsAllocationsTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_explicit_allocation_uses_immutable_evidence_and_capacity(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        $receivableId = $this->recognizedReceivable($tenantId, $actor->id, $customerId, '100.00');
        $paymentId = app(RecordPaymentAction::class)->execute($tenantId, $actor, ['payment_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId, 'amount' => '100.00', 'currency' => 'SAR', 'received_on' => now()->toDateString()]);
        $allocationId = app(AllocatePaymentAction::class)->execute($tenantId, $actor, ['payment_id' => $paymentId, 'receivable_id' => $receivableId, 'allocation_operation_id' => (string) Str::ulid(), 'amount' => '100.00']);

        self::assertSame('effective', DB::table('payment_allocations')->where('id', $allocationId)->value('status'));
        self::assertSame('100.00', (string) DB::table('payment_allocations')->where('id', $allocationId)->value('amount'));
        self::assertSame(0, DB::table('journal_entries')->count());
        $this->expectException(PaymentsConflict::class);
        app(AllocatePaymentAction::class)->execute($tenantId, $actor, ['payment_id' => $paymentId, 'receivable_id' => $receivableId, 'allocation_operation_id' => (string) Str::ulid(), 'amount' => '0.01']);
    }

    public function test_effective_allocation_blocks_parent_cancellation_until_explicitly_cancelled(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        $receivableId = $this->recognizedReceivable($tenantId, $actor->id, $customerId, '10.00');
        $paymentId = app(RecordPaymentAction::class)->execute($tenantId, $actor, ['payment_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId, 'amount' => '10.00', 'currency' => 'SAR', 'received_on' => now()->toDateString()]);
        $allocationId = app(AllocatePaymentAction::class)->execute($tenantId, $actor, ['payment_id' => $paymentId, 'receivable_id' => $receivableId, 'allocation_operation_id' => (string) Str::ulid(), 'amount' => '10.00']);

        try {
            app(CancelPaymentAction::class)->execute($tenantId, $paymentId, $actor, 'Correction');
            self::fail('Payment cancellation was accepted with an effective allocation.');
        } catch (PaymentsConflict) {
            self::assertTrue(true);
        }
        app(CancelPaymentAllocationAction::class)->execute($tenantId, $allocationId, $actor, 'Correction');
        app(CancelPaymentAction::class)->execute($tenantId, $paymentId, $actor, 'Correction');
        self::assertSame('cancelled', DB::table('payments')->where('id', $paymentId)->value('status'));
    }

    public function test_payment_cancellation_replay_is_idempotent_for_same_reason_and_conflicts_for_different_reason(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $paymentId = app(RecordPaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'amount' => '25.00',
                'currency' => 'SAR',
                'received_on' => now()->toDateString(),
            ],
        );

        $action = app(CancelPaymentAction::class);

        $action->execute(
            $tenantId,
            $paymentId,
            $actor,
            'Duplicate receipt correction',
        );

        $first = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentId)
            ->firstOrFail();

        self::assertSame('cancelled', $first->status);
        self::assertSame(
            'Duplicate receipt correction',
            $first->cancellation_reason,
        );

        $auditCountAfterFirstCancellation = DB::table(
            'payment_allocation_audits',
        )
            ->where('tenant_id', $tenantId)
            ->where('event', 'payment.cancelled')
            ->where('subject_type', 'payment')
            ->where('subject_id', $paymentId)
            ->count();

        self::assertSame(1, $auditCountAfterFirstCancellation);

        $action->execute(
            $tenantId,
            $paymentId,
            $actor,
            'Duplicate receipt correction',
        );

        $replayed = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('id', $paymentId)
            ->firstOrFail();

        self::assertSame($first->cancelled_at, $replayed->cancelled_at);
        self::assertSame($first->cancelled_by, $replayed->cancelled_by);
        self::assertSame(
            'Duplicate receipt correction',
            $replayed->cancellation_reason,
        );

        self::assertSame(
            1,
            DB::table('payment_allocation_audits')
                ->where('tenant_id', $tenantId)
                ->where('event', 'payment.cancelled')
                ->where('subject_type', 'payment')
                ->where('subject_id', $paymentId)
                ->count(),
        );

        try {
            $action->execute(
                $tenantId,
                $paymentId,
                $actor,
                'Different cancellation fact',
            );

            self::fail(
                'Payment cancellation replay accepted different facts.',
            );
        } catch (PaymentsConflict $exception) {
            self::assertSame(
                'Payment cancellation was replayed with different facts.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            1,
            DB::table('payment_allocation_audits')
                ->where('tenant_id', $tenantId)
                ->where('event', 'payment.cancelled')
                ->where('subject_type', 'payment')
                ->where('subject_id', $paymentId)
                ->count(),
        );
    }

    public function test_allocation_cancellation_replay_is_idempotent_for_same_reason_and_conflicts_for_different_reason(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $receivableId = $this->recognizedReceivable(
            $tenantId,
            $actor->id,
            $customerId,
            '25.00',
        );

        $paymentId = app(RecordPaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'amount' => '25.00',
                'currency' => 'SAR',
                'received_on' => now()->toDateString(),
            ],
        );

        $allocationId = app(AllocatePaymentAction::class)->execute(
            $tenantId,
            $actor,
            [
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => '25.00',
            ],
        );

        $action = app(CancelPaymentAllocationAction::class);

        $action->execute(
            $tenantId,
            $allocationId,
            $actor,
            'Allocation correction',
        );

        $first = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $allocationId)
            ->firstOrFail();

        self::assertSame('cancelled', $first->status);
        self::assertSame(
            'Allocation correction',
            $first->cancellation_reason,
        );

        self::assertSame(
            1,
            DB::table('payment_allocation_audits')
                ->where('tenant_id', $tenantId)
                ->where('event', 'payment_allocation.cancelled')
                ->where('subject_type', 'payment_allocation')
                ->where('subject_id', $allocationId)
                ->count(),
        );

        $action->execute(
            $tenantId,
            $allocationId,
            $actor,
            'Allocation correction',
        );

        $replayed = DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where('id', $allocationId)
            ->firstOrFail();

        self::assertSame($first->cancelled_at, $replayed->cancelled_at);
        self::assertSame($first->cancelled_by, $replayed->cancelled_by);
        self::assertSame(
            'Allocation correction',
            $replayed->cancellation_reason,
        );

        self::assertSame(
            1,
            DB::table('payment_allocation_audits')
                ->where('tenant_id', $tenantId)
                ->where('event', 'payment_allocation.cancelled')
                ->where('subject_type', 'payment_allocation')
                ->where('subject_id', $allocationId)
                ->count(),
        );

        try {
            $action->execute(
                $tenantId,
                $allocationId,
                $actor,
                'Different cancellation fact',
            );

            self::fail(
                'Payment Allocation cancellation replay accepted different facts.',
            );
        } catch (PaymentsConflict $exception) {
            self::assertSame(
                'Payment Allocation cancellation was replayed with different facts.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            1,
            DB::table('payment_allocation_audits')
                ->where('tenant_id', $tenantId)
                ->where('event', 'payment_allocation.cancelled')
                ->where('subject_type', 'payment_allocation')
                ->where('subject_id', $allocationId)
                ->count(),
        );
    }

    public function test_database_trigger_rejects_direct_sql_over_allocation(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        $receivableId = $this->recognizedReceivable($tenantId, $actor->id, $customerId, '1.00');
        $paymentId = app(RecordPaymentAction::class)->execute($tenantId, $actor, ['payment_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId, 'amount' => '1.00', 'currency' => 'SAR', 'received_on' => now()->toDateString()]);

        $this->expectException(QueryException::class);
        DB::table('payment_allocations')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'payment_id' => $paymentId, 'receivable_id' => $receivableId, 'allocation_operation_id' => (string) Str::ulid(), 'amount' => '1.01', 'status' => 'effective', 'allocated_at' => now(), 'allocated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = (string) TenantUser::query()->where('user_id', $actor->id)->value('tenant_id');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        return [$tenantId, $actor, (string) $customer->id];
    }

    private function recognizedReceivable(string $tenantId, int $actorId, string $customerId, string $amount): string
    {
        $id = (string) Str::ulid();
        DB::table('receivables')->insert(['id' => $id, 'tenant_id' => $tenantId, 'customer_id' => $customerId, 'recognition_operation_id' => (string) Str::ulid(), 'currency' => 'SAR', 'recognized_amount' => $amount, 'due_date' => now()->toDateString(), 'status' => 'recognized', 'recognized_at' => now(), 'recognized_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
