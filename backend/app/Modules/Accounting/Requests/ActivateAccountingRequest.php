<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class ActivateAccountingRequest extends AccountingRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('idempotent')) {
            $this->merge(['idempotent' => filter_var($this->input('idempotent'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return ['idempotent' => ['sometimes', 'boolean']];
    }
}
