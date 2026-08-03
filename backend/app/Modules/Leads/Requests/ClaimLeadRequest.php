<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

final class ClaimLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys([]);
    }

    public function rules(): array
    {
        return [];
    }
}
