<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

use App\Modules\Contracts\Enums\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexCollectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(ContractStatus::class)],
            'schedule_state' => [
                'sometimes',
                'nullable',
                Rule::in(['absent', 'draft', 'scheduled', 'cancelled']),
            ],
        ];
    }
}
