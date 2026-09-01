<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class ReversePostedBankReceiptCashAndInvalidateRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return [
            'reversal_operation_id' => ['required', 'ulid'],
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reversal_reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'invalidation_operation_id' => ['required', 'ulid'],
            'invalidation_reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
        ];
    }
}
