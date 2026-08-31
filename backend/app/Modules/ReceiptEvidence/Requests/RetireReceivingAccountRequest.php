<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

final class RetireReceivingAccountRequest extends ReceiptEvidenceRequest
{
    public function rules(): array
    {
        return ['retirement_operation_id' => ['required', 'ulid'], 'retired_from' => ['required', 'date_format:Y-m-d'], 'retirement_reason' => ['required', 'string', 'max:500', 'regex:/\S/u']];
    }
}
