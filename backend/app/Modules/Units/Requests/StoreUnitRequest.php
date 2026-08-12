<?php

declare(strict_types=1);

namespace App\Modules\Units\Requests;

use App\Modules\Units\Enums\UnitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => [
                'required',
                'ulid',
                Rule::exists('projects', 'id'),
            ],

            'unit_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'unit_number')
                    ->where(
                        fn ($query) => $query->where(
                            'project_id',
                            $this->input('project_id')
                        )
                    ),
            ],

            'unit_type' => [
                'required',
                Rule::enum(UnitType::class),
            ],

            'status' => [
                'prohibited',
            ],

            'selling_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'area' => [
                'nullable',
                'numeric',
            ],

            'floor' => [
                'nullable',
                'integer',
            ],

            'bedrooms' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'bathrooms' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }

}
