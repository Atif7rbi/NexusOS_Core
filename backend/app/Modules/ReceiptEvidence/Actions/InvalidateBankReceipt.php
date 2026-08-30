<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\EffectiveReceiptPaymentAssociationGuard;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class InvalidateBankReceipt
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth, private readonly EffectiveReceiptPaymentAssociationGuard $guard) {}

    public function execute(string $tenantId, string $receiptId, User $actor, array $input): void
    {
        $facts = ['invalidation_operation_id' => ReceiptEvidenceFacts::operation($input, 'invalidation_operation_id'), 'invalidation_reason' => ReceiptEvidenceFacts::reason($input, 'invalidation_reason')];
        $this->auth->authorize($tenantId, $actor);
        $this->tx->run(function () use ($tenantId, $receiptId, $actor, $facts): void {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $receipt = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $receiptId)->lockForUpdate()->first();
            if ($receipt === null) throw (new ModelNotFoundException)->setModel('BankReceiptEvidence');
            if ($receipt->status === 'invalidated') {
                if ($receipt->invalidation_operation_id === $facts['invalidation_operation_id'] && $receipt->invalidation_reason === $facts['invalidation_reason']) return;
                throw new ReceiptEvidenceConflict('Receipt is already invalidated by a different terminal operation.');
            }
            $operation = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('invalidation_operation_id', $facts['invalidation_operation_id'])->lockForUpdate()->first();
            if ($operation !== null) throw new ReceiptEvidenceConflict('Invalidation operation identity was reused with different facts.');
            $this->guard->assertNoneForReceipt($tenantId, $receiptId);
            $now = now();
            DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $receiptId)->update([...$facts, 'status' => 'invalidated', 'invalidated_by' => $actor->id, 'invalidated_at' => $now, 'updated_at' => $now]);
        });
    }
}
