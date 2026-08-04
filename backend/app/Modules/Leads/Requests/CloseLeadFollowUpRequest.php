<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

final class CloseLeadFollowUpRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['note']);

        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim((string) $this->input('note'))]);
        }
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
