<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Requests;

use Illuminate\Validation\Rule;

final class IndexReceivablesRequest extends ReceivablesRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['recognized', 'cancelled'])],
            'customer_id' => ['sometimes', 'ulid'],
            'contract_id' => ['sometimes', 'ulid'],
            'collection_id' => ['sometimes', 'ulid'],
            'currency' => ['sometimes', Rule::in(['SAR', 'USD'])],
            'due_from' => ['sometimes', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
