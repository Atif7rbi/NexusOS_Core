<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class ApproveReceivingAccountRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['receiving_account_operation_id' => ['required', 'ulid'], 'institution_identifier' => ['required', 'string', 'max:128'], 'account_identity' => ['required', 'string', 'max:255'], 'masked_account_identity' => ['required', 'string', 'max:255'], 'valid_from' => ['required', 'date_format:Y-m-d']];
    }
}
