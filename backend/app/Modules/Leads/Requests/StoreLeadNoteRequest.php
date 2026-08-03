<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use Closure;

final class StoreLeadNoteRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(['body']);
    }

    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (trim((string) $value) === '') {
                        $fail('نص الملاحظة مطلوب.');
                    }
                },
            ],
        ];
    }
}
