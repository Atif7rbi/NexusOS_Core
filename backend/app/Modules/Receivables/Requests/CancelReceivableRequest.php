<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Requests;

final class CancelReceivableRequest extends ReceivablesRequest
{
    public function rules(): array
    {
        return [
            'cancelled_at' => ['required', 'date_format:'.DATE_RFC3339],
            'cancellation_reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'reversal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reversal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
