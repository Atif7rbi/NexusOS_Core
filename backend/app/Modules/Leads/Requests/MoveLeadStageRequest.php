<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\LeadStage;
use Illuminate\Validation\Rule;

final class MoveLeadStageRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['stage']);
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(LeadStage::openValues())],
        ];
    }
}
