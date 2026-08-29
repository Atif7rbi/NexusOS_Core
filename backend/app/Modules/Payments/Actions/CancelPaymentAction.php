<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use App\Modules\Payments\Support\EffectivePaymentAllocationGuard;
use App\Modules\Payments\Support\PaymentAllocationAuditWriter;
use App\Modules\Payments\Support\PaymentsTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CancelPaymentAction
{
    public function __construct(
        private readonly PaymentsTransaction $tx,
        private readonly ReceivablesAuthorization $auth,
        private readonly EffectivePaymentAllocationGuard $guard,
        private readonly PaymentAllocationAuditWriter $audit,
    ) {}

    public function execute(string $tenantId, string $paymentId, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PaymentsValidationFailed('cancellation_reason is required.');
        }
        $this->auth->authorize($tenantId, $actor);
        $this->tx->run(function () use ($tenantId, $paymentId, $actor, $reason): void {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $payment = DB::table('payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel('Payment');
            }
            if ($payment->status === 'cancelled') {
                if ((string) $payment->cancellation_reason !== $reason) {
                    throw new PaymentsConflict('Payment cancellation was replayed with different facts.');
                }

                return;
            }
            if ($payment->status !== 'received') {
                throw new PaymentsConflict('Only a received Payment can be cancelled.');
            }
            $this->guard->assertNoneForPayment($tenantId, $paymentId);
            $now = now();
            DB::table('payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason, 'updated_at' => $now]);
            $this->audit->write($tenantId, 'payment.cancelled', 'payment', $paymentId, $actor->id, ['reason' => $reason]);
        });
    }
}
