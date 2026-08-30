<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CancelReceiptPaymentAssociation
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, string $associationId, User $actor, array $input): void
    {
        $facts = ['cancellation_operation_id' => ReceiptEvidenceFacts::operation($input, 'cancellation_operation_id'), 'cancellation_reason' => ReceiptEvidenceFacts::reason($input, 'cancellation_reason')];
        $this->auth->authorize($tenantId, $actor);
        $this->tx->run(function () use ($tenantId, $associationId, $actor, $facts): void {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $association = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('id', $associationId)->lockForUpdate()->first();
            if ($association === null) throw (new ModelNotFoundException)->setModel('ReceiptPaymentAssociation');
            if ($association->status === 'cancelled') {
                if ($association->cancellation_operation_id === $facts['cancellation_operation_id'] && $association->cancellation_reason === $facts['cancellation_reason']) return;
                throw new ReceiptEvidenceConflict('Receipt Payment association is already cancelled by a different terminal operation.');
            }
            $operation = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('cancellation_operation_id', $facts['cancellation_operation_id'])->lockForUpdate()->first();
            if ($operation !== null) throw new ReceiptEvidenceConflict('Association cancellation operation identity was reused with different facts.');
            $now = now();
            DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('id', $associationId)->update([...$facts, 'status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => $now, 'updated_at' => $now]);
        });
    }
}
