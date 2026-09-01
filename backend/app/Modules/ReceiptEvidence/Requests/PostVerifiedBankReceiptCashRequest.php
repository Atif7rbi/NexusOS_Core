<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class PostVerifiedBankReceiptCashRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['posting_operation_id' => ['required', 'ulid']];
    }
}
