<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class IndexJournalsRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'posted'])],
            'origin' => ['sometimes', 'nullable', Rule::in(['manual', 'business', 'opening_balance', 'reversal'])],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'journal_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
