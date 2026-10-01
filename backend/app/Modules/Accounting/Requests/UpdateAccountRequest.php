<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\FinancialStatementClassificationCatalog;
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
            'account_type' => ['sometimes', Rule::in(FinancialStatementClassificationCatalog::accountTypes())],
            'classification' => ['sometimes', 'nullable', Rule::in(FinancialStatementClassificationCatalog::classifications())],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'status' => ['prohibited'],
        ];
    }
}
