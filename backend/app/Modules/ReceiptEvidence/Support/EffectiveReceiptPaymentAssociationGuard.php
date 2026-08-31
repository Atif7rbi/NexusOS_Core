<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Support;

use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use Illuminate\Support\Facades\DB;

final class EffectiveReceiptPaymentAssociationGuard
{
    public function assertNoneForPayment(string $tenantId, string $paymentId): void
    {
        if ($this->exists($tenantId, 'payment_id', $paymentId)) {
            throw new PaymentsConflict('A Payment with an effective receipt association cannot be cancelled.');
        }
    }

    public function assertNoneForReceipt(string $tenantId, string $receiptId): void
    {
        if ($this->exists($tenantId, 'receipt_id', $receiptId)) {
            throw new ReceiptEvidenceConflict('Receipt evidence with an effective Payment association cannot be invalidated.');
        }
    }

    private function exists(string $tenantId, string $column, string $id): bool
    {
        return DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where($column, $id)->where('status', 'effective')->exists();
    }
}
