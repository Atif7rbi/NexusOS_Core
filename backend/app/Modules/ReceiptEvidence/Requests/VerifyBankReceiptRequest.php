<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

use Illuminate\Validation\Rule;

final class VerifyBankReceiptRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['receipt_operation_id' => ['required', 'ulid'], 'receiving_account_id' => ['required', 'ulid'], 'source_identity_kind' => ['required', Rule::in(['bank_transaction_id', 'statement_line_fingerprint_v1'])], 'source_identity_version' => ['required', 'integer', 'min:1'], 'source_identity' => ['required', 'string', 'max:512'], 'amount' => ['required'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'control_date' => ['required', 'date_format:Y-m-d'], 'evidence_reference' => ['required', 'string', 'max:500'], 'evidence_locator' => ['sometimes', 'nullable', 'string', 'max:500'], 'replaces_receipt_id' => ['prohibited'], 'channel' => ['prohibited'], 'verification_method' => ['prohibited'], 'verified_by' => ['prohibited'], 'status' => ['prohibited']];
    }
}
