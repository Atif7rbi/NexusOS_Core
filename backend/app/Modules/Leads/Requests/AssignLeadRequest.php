<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

final class AssignLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['assigned_to']);
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
