<?php

declare(strict_types=1);

namespace App\Modules\Units\Requests;

use App\Modules\Units\Enums\UnitType;
use App\Modules\Units\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => [
                'sometimes',
                'required',
                'ulid',
                Rule::exists('projects', 'id'),
            ],

            'unit_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'unit_number')
                    ->where(
                        fn ($query) => $query->where(
                            'project_id',
                            $this->projectId()
                        )
                    )
                    ->ignore($this->unitId()),
            ],

            'unit_type' => [
                'sometimes',
                'required',
                Rule::enum(UnitType::class),
            ],

            'status' => [
                'prohibited',
            ],

            'selling_price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'area' => [
                'sometimes',
                'nullable',
                'numeric',
            ],

            'floor' => [
                'sometimes',
                'nullable',
                'integer',
            ],

            'bedrooms' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'bathrooms' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }

    private function unitId(): string
    {
        return (string) $this->route('unit');
    }

    private function projectId(): string
    {
        if ($this->has('project_id')) {
            return $this->string('project_id')->toString();
        }

        return (string) Unit::query()
            ->findOrFail($this->unitId())
            ->project_id;
    }
}
