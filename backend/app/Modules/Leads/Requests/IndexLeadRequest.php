<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Enums\LeadStage;
use Illuminate\Validation\Rule;

final class IndexLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['overdue', 'archived'] as $field) {
            $value = $this->query($field);
            $boolean = filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            );

            if ($value !== null && $boolean !== null) {
                $this->merge([
                    $field => $boolean,
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stage' => ['sometimes', 'nullable', Rule::enum(LeadStage::class)],
            'source' => ['sometimes', 'nullable', Rule::enum(LeadSource::class)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'project_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'follow_up_bucket' => [
                'sometimes',
                'nullable',
                Rule::in(['overdue', 'today', 'tomorrow', 'this_week', 'unscheduled']),
            ],
            'follow_up_state' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'overdue',
                    'today',
                    'tomorrow',
                    'this_week',
                    'scheduled_later',
                    'unscheduled',
                ]),
            ],
            'lifecycle' => [
                'sometimes',
                'nullable',
                Rule::in(['open', 'won', 'lost']),
            ],
            'date_from' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
            'date_to' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'overdue' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
