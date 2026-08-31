<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class CancelReceiptPaymentAssociationRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['cancellation_operation_id' => ['required', 'ulid'], 'cancellation_reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }
}
