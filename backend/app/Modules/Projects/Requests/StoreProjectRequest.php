<?php

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Enums\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sequence_number' => ['prohibited'],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'project_type' => [
                'required',
                Rule::enum(ProjectType::class),
            ],
            'status' => ['prohibited'],
            'city' => [
                'required',
                'string',
                'max:120',
            ],
            'district' => [
                'nullable',
                'string',
                'max:120',
            ],
            'address_line' => [
                'nullable',
                'string',
                'max:500',
            ],
            'estimated_budget' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'planned_start_date' => [
                'nullable',
                'date',
            ],
            'planned_end_date' => [
                'nullable',
                'date',
                'after_or_equal:planned_start_date',
            ],
            'actual_start_date' => [
                'nullable',
                'date',
            ],
            'actual_end_date' => [
                'nullable',
                'date',
                'after_or_equal:actual_start_date',
            ],
            'project_manager_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'external_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'legacy_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}
