<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class GeneralLedgerRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:9999-12-31'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:9999-12-31', 'after_or_equal:from_date'],
        ];
    }

    protected function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            $unknown = array_diff(array_keys($this->query()), ['from_date', 'to_date']);
            if ($unknown !== []) {
                $validator->errors()->add((string) reset($unknown), 'Unknown General Ledger filter.');
            }
        });
    }
}
