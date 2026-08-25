<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Validation\Rule;

final class IndexOpeningBalancesRequest extends AccountingRequest
{
    public function rules(): array
    {
        return ['status' => ['sometimes', 'nullable', Rule::in(['draft', 'posted'])], 'effect_state' => ['sometimes', 'nullable', Rule::in(['effective', 'neutralized'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
