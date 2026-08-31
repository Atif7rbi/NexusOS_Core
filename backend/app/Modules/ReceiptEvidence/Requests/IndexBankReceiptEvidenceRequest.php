<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

use Illuminate\Validation\Rule;

final class IndexBankReceiptEvidenceRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:effective,invalidated'],
            'receiving_account_id' => ['sometimes', 'ulid'],
            'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/'],
            'control_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'control_date_to' => ['sometimes', 'date_format:Y-m-d'],
            'source_identity_kind' => ['sometimes', Rule::in(['bank_transaction_id', 'statement_line_fingerprint_v1'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
