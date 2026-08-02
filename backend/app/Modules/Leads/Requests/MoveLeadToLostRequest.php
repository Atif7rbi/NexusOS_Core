<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\LostReason;
use Closure;
use Illuminate\Validation\Rule;

final class MoveLeadToLostRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['lost_reason', 'lost_reason_detail']);
    }

    public function rules(): array
    {
        return [
            'lost_reason' => ['required', Rule::enum(LostReason::class)],
            'lost_reason_detail' => [
                'required_if:lost_reason,other',
                'prohibited_unless:lost_reason,other',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && trim((string) $value) === '') {
                        $fail('تفاصيل سبب الفقد مطلوبة.');
                    }
                },
            ],
        ];
    }
}
