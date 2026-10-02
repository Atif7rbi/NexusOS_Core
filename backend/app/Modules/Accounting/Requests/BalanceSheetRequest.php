<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class BalanceSheetRequest extends AccountingRequest
{
    public function rules(): array
    {
        return ['as_of_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:9999-12-31']];
    }

    protected function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            foreach (array_diff(array_keys($this->query()), ['as_of_date']) as $key) {
                $validator->errors()->add($key, 'Balance Sheet does not accept this query filter.');
            }
        });
    }
}
