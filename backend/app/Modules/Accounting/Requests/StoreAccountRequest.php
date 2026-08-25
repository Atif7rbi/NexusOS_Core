<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class StoreAccountRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'kind' => ['required', Rule::in(['group', 'posting'])],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'classification' => ['present', 'nullable', Rule::in(['current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'])],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'status' => ['prohibited'],
        ];
    }
}
