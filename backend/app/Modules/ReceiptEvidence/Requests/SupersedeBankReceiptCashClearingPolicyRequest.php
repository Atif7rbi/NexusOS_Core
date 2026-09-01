<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class SupersedeBankReceiptCashClearingPolicyRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['policy_operation_id' => ['required', 'ulid'], 'clearing_account_id' => ['required', 'ulid'], 'supersession_reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }
}
