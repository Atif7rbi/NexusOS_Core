<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use App\Modules\Payments\Support\PaymentAllocationAuditWriter;
use App\Modules\Payments\Support\PaymentsTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CancelPaymentAllocationAction
{
    public function __construct(private readonly PaymentsTransaction $tx, private readonly ReceivablesAuthorization $auth, private readonly PaymentAllocationAuditWriter $audit) {}

    public function execute(string $tenantId, string $allocationId, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PaymentsValidationFailed('cancellation_reason is required.');
        }
        $this->auth->authorize($tenantId, $actor);
        $this->tx->run(function () use ($tenantId, $allocationId, $actor, $reason): void {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $allocation = DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('id', $allocationId)->lockForUpdate()->first();
            if ($allocation === null) {
                throw (new ModelNotFoundException)->setModel('PaymentAllocation');
            }
            if ($allocation->status !== 'effective') {
                throw new PaymentsConflict('Only an effective Payment Allocation can be cancelled.');
            }
            $now = now();
            DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('id', $allocationId)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason, 'updated_at' => $now]);
            $this->audit->write($tenantId, 'payment_allocation.cancelled', 'payment_allocation', $allocationId, $actor->id, ['reason' => $reason]);
        });
    }
}
