<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class TrialBalanceRequest extends AccountingRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->query->has('include_zero')) {
            $value = $this->query('include_zero');
            if ($value === 'true') {
                $this->merge(['include_zero' => true]);
            } elseif ($value === 'false') {
                $this->merge(['include_zero' => false]);
            }
        }
    }

    /** @return array<string,array<int,mixed>> */
    public function rules(): array
    {
        return [
            'as_of_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:9999-12-31'],
            'account_type' => ['sometimes', 'nullable', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'classification' => ['sometimes', 'nullable', Rule::in(['current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'])],
            'include_zero' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator($validator): void
    {
        parent::withValidator($validator);

        $validator->after(function ($validator): void {
            $unknown = array_diff(array_keys($this->query()), ['as_of_date', 'account_type', 'classification', 'include_zero']);

            if ($unknown !== []) {
                $validator->errors()->add((string) reset($unknown), 'Unknown Trial Balance filter.');
            }
        });
    }
}
