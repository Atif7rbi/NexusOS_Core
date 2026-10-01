<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\FinancialStatementClassificationCatalog;
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
            'account_type' => ['required', Rule::in(FinancialStatementClassificationCatalog::accountTypes())],
            'classification' => ['present', 'nullable', Rule::in(FinancialStatementClassificationCatalog::classifications())],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'status' => ['prohibited'],
        ];
    }

    protected function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            $kind = $this->input('kind');
            $type = $this->input('account_type');
            $classification = $this->input('classification');
            if ($kind === 'group' && $classification !== null) {
                $validator->errors()->add('classification', 'Group Accounts cannot have a classification.');
            }
            if ($kind === 'posting' && ! FinancialStatementClassificationCatalog::isValidPostingPair((string) $type, $classification)) {
                $validator->errors()->add('classification', 'Classification is not valid for this Account type.');
            }
        });
    }
}
