<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class AssociateReceiptWithPaymentRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['association_operation_id' => ['required', 'ulid'], 'receipt_id' => ['required', 'ulid'], 'payment_id' => ['required', 'ulid'], 'replaces_association_id' => ['prohibited'], 'associated_amount' => ['prohibited'], 'currency' => ['prohibited'], 'associated_by' => ['prohibited'], 'status' => ['prohibited']];
    }
}
