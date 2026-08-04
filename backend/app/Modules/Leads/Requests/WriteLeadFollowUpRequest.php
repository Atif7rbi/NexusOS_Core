<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\NextActionType;
use Illuminate\Validation\Rule;

final class WriteLeadFollowUpRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys([
            'next_follow_up_at',
            'next_action_type',
            'next_action_note',
        ]);

        if (is_string($this->input('next_action_note'))) {
            $this->merge(['next_action_note' => trim((string) $this->input('next_action_note'))]);
        }
    }

    public function rules(): array
    {
        return [
            'next_follow_up_at' => [
                'required',
                'string',
                'date',
                'regex:/^(?:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)(?:Z|[+-]\d{2}:\d{2})$/',
            ],
            'next_action_type' => ['required', 'string', Rule::in(NextActionType::values())],
            'next_action_note' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(fn (): bool => $this->input('next_action_type') === NextActionType::Other->value),
            ],
        ];
    }
}
