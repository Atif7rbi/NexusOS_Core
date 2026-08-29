<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Exceptions\PaymentsConflict;
use Illuminate\Support\Facades\DB;

final class EffectivePaymentAllocationGuard
{
    public function assertNoneForPayment(string $tenantId, string $paymentId): void
    {
        if ($this->exists($tenantId, 'payment_id', $paymentId)) {
            throw new PaymentsConflict('A Payment with effective allocations cannot be cancelled.');
        }
    }

    public function assertNoneForReceivable(string $tenantId, string $receivableId): void
    {
        if ($this->exists($tenantId, 'receivable_id', $receivableId)) {
            throw new PaymentsConflict('A Receivable with effective allocations cannot be cancelled.');
        }
    }

    private function exists(string $tenantId, string $column, string $id): bool
    {
        if (! $this->tableExists()) {
            return false;
        }

        return DB::table('payment_allocations')
            ->where('tenant_id', $tenantId)
            ->where($column, $id)
            ->where('status', 'effective')
            ->exists();
    }

    private function tableExists(): bool
    {
        return DB::selectOne("SELECT to_regclass('payment_allocations') AS name")?->name !== null;
    }
}
