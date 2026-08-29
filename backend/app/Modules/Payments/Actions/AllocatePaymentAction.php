<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use App\Modules\Payments\Support\PaymentAllocationAuditWriter;
use App\Modules\Payments\Support\PaymentsTransaction;
use App\Modules\Payments\ValueObjects\PaymentAmount;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AllocatePaymentAction
{
    public function __construct(
        private readonly PaymentsTransaction $tx,
        private readonly ReceivablesAuthorization $auth,
        private readonly PaymentAllocationAuditWriter $audit,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = $this->canonicalize($input);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $payment = DB::table('payments')->where('tenant_id', $tenantId)->where('id', $facts['payment_id'])->lockForUpdate()->first();
            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel('Payment');
            }
            $receivable = DB::table('receivables')->where('tenant_id', $tenantId)->where('id', $facts['receivable_id'])->lockForUpdate()->first();
            if ($receivable === null) {
                throw (new ModelNotFoundException)->setModel('Receivable');
            }
            $existing = DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('allocation_operation_id', $facts['allocation_operation_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->payment_id !== $facts['payment_id'] || $existing->receivable_id !== $facts['receivable_id'] || (string) $existing->amount !== $facts['amount']) {
                    throw new PaymentsConflict('Allocation operation identity was reused with different facts.');
                }

                return (string) $existing->id;
            }
            if ($payment->status !== 'received' || $receivable->status !== 'recognized' || $payment->customer_id !== $receivable->customer_id || $payment->currency !== $receivable->currency) {
                throw new PaymentsConflict('Payment and Receivable are not eligible for allocation.');
            }
            $paymentUsed = (string) (DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('payment_id', $facts['payment_id'])->where('status', 'effective')->sum('amount'));
            $receivableUsed = (string) (DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('receivable_id', $facts['receivable_id'])->where('status', 'effective')->sum('amount'));
            $amount = BigDecimal::of($facts['amount']);
            if (BigDecimal::of($paymentUsed)->plus($amount)->isGreaterThan(BigDecimal::of((string) $payment->amount)) || BigDecimal::of($receivableUsed)->plus($amount)->isGreaterThan(BigDecimal::of((string) $receivable->recognized_amount))) {
                throw new PaymentsConflict('Allocation exceeds the remaining capacity.');
            }
            $id = (string) Str::ulid();
            $now = now();
            DB::table('payment_allocations')->insert([
                ...$facts,
                'id' => $id,
                'tenant_id' => $tenantId,
                'status' => 'effective',
                'allocated_at' => $now,
                'allocated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->write($tenantId, 'payment_allocation.effective', 'payment_allocation', $id, $actor->id, ['payment_id' => $facts['payment_id'], 'receivable_id' => $facts['receivable_id'], 'amount' => $facts['amount']]);

            return $id;
        });
    }

    private function canonicalize(array $input): array
    {
        $paymentId = (string) ($input['payment_id'] ?? '');
        $receivableId = (string) ($input['receivable_id'] ?? '');
        $operation = (string) ($input['allocation_operation_id'] ?? '');
        if ($paymentId === '' || $receivableId === '' || ! Str::isUlid($operation)) {
            throw new PaymentsValidationFailed('payment_id, receivable_id, and a caller-supplied allocation_operation_id are required.');
        }

        return ['payment_id' => $paymentId, 'receivable_id' => $receivableId, 'allocation_operation_id' => $operation, 'amount' => (string) PaymentAmount::of($input['amount'] ?? '')];
    }
}
