<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Shared\Phone\Rules\SaudiMobileRule;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Closure;
use Illuminate\Validation\Rule;

final class UpdateLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys([
            'name',
            'phone',
            'email',
            'source',
            'source_detail',
            'project_id',
            'unit_id',
            'next_follow_up_at',
        ]);
        $this->normalizeRequiredPhone();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', $this->notBlank(), 'max:255'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                new SaudiMobileRule(
                    app(SaudiMobileNormalizer::class),
                    required: true,
                ),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'source' => ['sometimes', 'required', Rule::enum(LeadSource::class)],
            'source_detail' => ['sometimes', 'nullable', 'string'],
            'project_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    private function notBlank(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (trim((string) $value) === '') {
                $fail('يجب ألا تكون القيمة فارغة.');
            }
        };
    }
}
