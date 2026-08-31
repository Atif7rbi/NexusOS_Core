<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class InvalidateBankReceiptRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['invalidation_operation_id' => ['required', 'ulid'], 'invalidation_reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }
}
