<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class IndexAccountsRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'archived'])],
            'kind' => ['sometimes', 'nullable', Rule::in(['group', 'posting'])],
            'account_type' => ['sometimes', 'nullable', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'classification' => ['sometimes', 'nullable', Rule::in(['current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'])],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
