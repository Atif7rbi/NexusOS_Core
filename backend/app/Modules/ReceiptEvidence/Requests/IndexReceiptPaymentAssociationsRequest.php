<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class IndexReceiptPaymentAssociationsRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:effective,cancelled'],
            'receipt_id' => ['sometimes', 'ulid'],
            'payment_id' => ['sometimes', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
