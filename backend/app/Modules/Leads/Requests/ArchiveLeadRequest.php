<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\ArchiveReason;
use Closure;
use Illuminate\Validation\Rule;

final class ArchiveLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['archive_reason', 'archive_reason_detail']);
    }

    public function rules(): array
    {
        return [
            'archive_reason' => ['required', Rule::enum(ArchiveReason::class)],
            'archive_reason_detail' => [
                'required_if:archive_reason,other',
                'prohibited_unless:archive_reason,other',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && trim((string) $value) === '') {
                        $fail('تفاصيل سبب الأرشفة مطلوبة.');
                    }
                },
            ],
        ];
    }
}
