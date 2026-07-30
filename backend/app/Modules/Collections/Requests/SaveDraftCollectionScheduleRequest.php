<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

use Closure;

final class SaveDraftCollectionScheduleRequest extends CollectionScheduleRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys($this->all(), ['lines']);
        $this->rejectUnknownLineKeys(
            $this->input('lines'),
            ['id', 'sequence', 'title', 'amount', 'due_date', 'notes'],
        );
    }

    public function rules(): array
    {
        return $this->lineRules(allowId: true);
    }

    /** @return array<string, array<int, mixed>> */
    private function lineRules(bool $allowId): array
    {
        return [
            'lines' => ['present', 'array', 'list'],
            'lines.*' => ['required', 'array'],
            'lines.*.id' => $allowId
                ? ['nullable', 'string', 'ulid', 'distinct:strict']
                : ['prohibited'],
            'lines.*.sequence' => ['required', 'integer', 'min:1'],
            'lines.*.title' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (trim((string) $value) === '') {
                        $fail('collections:blank_collection_title');
                    }
                },
                'max:150',
            ],
            'lines.*.amount' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $amount = (string) $value;

                    if (preg_match('/^[+]?\d+\.(\d{3,})$/', $amount) === 1) {
                        $fail('collections:excess_decimal_precision');

                        return;
                    }

                    if (
                        preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) !== 1
                        || preg_match('/^0+(?:\.0{1,2})?$/', $amount) === 1
                    ) {
                        $fail('collections:invalid_collection_amount');
                    }
                },
            ],
            'lines.*.due_date' => ['required', 'string', 'date_format:Y-m-d'],
            'lines.*.notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.present' => 'collections:unexpected_request_fields',
            'lines.array' => 'collections:unexpected_request_fields',
            'lines.list' => 'collections:unexpected_request_fields',
            'lines.*.array' => 'collections:unexpected_request_fields',
            'lines.*.id.string' => 'collections:invalid_collection_reference',
            'lines.*.id.ulid' => 'collections:invalid_collection_reference',
            'lines.*.id.distinct' => 'collections:invalid_collection_reference',
            'lines.*.sequence.required' => 'collections:invalid_collection_sequence',
            'lines.*.sequence.integer' => 'collections:invalid_collection_sequence',
            'lines.*.sequence.min' => 'collections:invalid_collection_sequence',
            'lines.*.title.required' => 'collections:blank_collection_title',
            'lines.*.title.string' => 'collections:blank_collection_title',
            'lines.*.title.max' => 'collections:collection_title_too_long',
            'lines.*.amount.required' => 'collections:invalid_collection_amount',
            'lines.*.amount.string' => 'collections:invalid_collection_amount',
            'lines.*.due_date.required' => 'collections:unexpected_request_fields',
            'lines.*.due_date.string' => 'collections:unexpected_request_fields',
            'lines.*.due_date.date_format' => 'collections:unexpected_request_fields',
            'lines.*.notes.string' => 'collections:unexpected_request_fields',
        ];
    }
}
