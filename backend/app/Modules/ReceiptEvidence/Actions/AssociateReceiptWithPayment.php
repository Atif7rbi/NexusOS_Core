<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssociateReceiptWithPayment
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = $this->facts($input);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $receipt = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $facts['receipt_id'])->lockForUpdate()->first();
            if ($receipt === null) {
                throw (new ModelNotFoundException)->setModel('BankReceiptEvidence');
            }
            $payment = DB::table('payments')->where('tenant_id', $tenantId)->where('id', $facts['payment_id'])->lockForUpdate()->first();
            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel('Payment');
            }
            $existing = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('association_operation_id', $facts['association_operation_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($this->matches($existing, $facts)) {
                    return (string) $existing->id;
                }
                throw new ReceiptEvidenceConflict('Association operation identity was reused with different facts.');
            }
            $byReceipt = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('receipt_id', $facts['receipt_id'])->where('status', 'effective')->lockForUpdate()->first();
            if ($byReceipt !== null) {
                if ($this->matches($byReceipt, $facts)) {
                    return (string) $byReceipt->id;
                }
                throw new ReceiptEvidenceConflict('Receipt already has an effective Payment association.');
            }
            $byPayment = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('payment_id', $facts['payment_id'])->where('status', 'effective')->lockForUpdate()->first();
            if ($byPayment !== null) {
                throw new ReceiptEvidenceConflict('Payment already has an effective receipt association.');
            }
            if ($receipt->status !== 'effective' || $payment->status !== 'received' || (string) $receipt->amount !== (string) $payment->amount || $receipt->currency !== $payment->currency) {
                throw new ReceiptEvidenceConflict('Receipt and Payment are not eligible for exact association.');
            }
            if ($facts['replaces_association_id'] !== null) {
                $original = DB::table('receipt_payment_associations')->where('tenant_id', $tenantId)->where('id', $facts['replaces_association_id'])->lockForUpdate()->first();
                if ($original === null || $original->status !== 'cancelled') {
                    throw new ReceiptEvidenceConflict('Replacement association requires a cancelled original.');
                }
            }
            $id = (string) Str::ulid();
            $now = now();
            DB::table('receipt_payment_associations')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'associated_amount' => (string) $receipt->amount, 'currency' => $receipt->currency, 'associated_by' => $actor->id, 'associated_at' => $now, 'status' => 'effective', 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
    }

    private function facts(array $input): array
    {
        $receipt = (string) ($input['receipt_id'] ?? '');
        $payment = (string) ($input['payment_id'] ?? '');
        if ($receipt === '' || $payment === '') {
            throw new ReceiptEvidenceValidationFailed('receipt_id and payment_id are required.');
        }

        return ['association_operation_id' => ReceiptEvidenceFacts::operation($input, 'association_operation_id'), 'receipt_id' => $receipt, 'payment_id' => $payment, 'replaces_association_id' => ($replacement = (string) ($input['replaces_association_id'] ?? '')) === '' ? null : $replacement];
    }

    private function matches(object $row, array $facts): bool
    {
        return $row->receipt_id === $facts['receipt_id'] && $row->payment_id === $facts['payment_id'];
    }
}
