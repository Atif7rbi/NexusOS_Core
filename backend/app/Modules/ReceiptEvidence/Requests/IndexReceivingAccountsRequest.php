<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class IndexReceivingAccountsRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:approved,retired'],
            'institution_identifier' => ['sometimes', 'string', 'max:128'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
