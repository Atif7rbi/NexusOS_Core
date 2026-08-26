<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Requests;

use App\Modules\Receivables\Http\ExactReceivableAmount;
use Illuminate\Validation\Rule;

final class RecognizeReceivableRequest extends ReceivablesRequest
{
    public function rules(): array
    {
        return [
            'recognition_operation_id' => ['required', 'ulid'],
            'customer_id' => ['required', 'ulid'],
            'contract_id' => ['sometimes', 'nullable', 'ulid'],
            'collection_id' => ['sometimes', 'nullable', 'ulid'],
            'currency' => ['required', Rule::in(['SAR', 'USD'])],
            'recognized_amount' => ['required', new ExactReceivableAmount],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'recognized_at' => ['required', 'date_format:'.DATE_RFC3339],
            'status' => ['prohibited'],
        ];
    }
}
