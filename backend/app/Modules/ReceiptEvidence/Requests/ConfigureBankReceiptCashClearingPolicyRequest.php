<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class ConfigureBankReceiptCashClearingPolicyRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['policy_operation_id' => ['required', 'ulid'], 'clearing_account_id' => ['required', 'ulid']];
    }
}
