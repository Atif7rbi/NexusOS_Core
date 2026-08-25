<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class UpdateAccountRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'kind' => ['sometimes', Rule::in(['group', 'posting'])],
            'account_type' => ['sometimes', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'classification' => ['sometimes', 'nullable', Rule::in(['current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'])],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'status' => ['prohibited'],
        ];
    }
}
