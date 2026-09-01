<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class ConfigureReceivingAccountCashMappingRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['mapping_operation_id' => ['required', 'ulid'], 'cash_account_id' => ['required', 'ulid']];
    }
}
