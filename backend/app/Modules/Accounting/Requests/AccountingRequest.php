<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Http\ExactDecimalInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class AccountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->exists('tenant_id')) {
                $validator->errors()->add('tenant_id', 'The tenant_id field is prohibited.');
            }
        });
    }

    /** @return array<string,array<int,mixed>> */
    protected function lineRules(string $presence = 'required'): array
    {
        return [
            'lines' => [$presence, 'array'],
            'lines.*.account_id' => ['required', 'ulid'],
            'lines.*.debit' => ['required', new ExactDecimalInput],
            'lines.*.credit' => ['required', new ExactDecimalInput],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
