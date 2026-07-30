<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

use Closure;

final class AmendCollectionScheduleRequest extends CollectionScheduleRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys(
            $this->all(),
            ['expected_active_collection_ids', 'lines', 'cancellation_reason'],
        );
        $this->rejectUnknownLineKeys(
            $this->input('lines'),
            ['sequence', 'title', 'amount', 'due_date', 'notes'],
        );
    }

    public function rules(): array
    {
        return [
            'expected_active_collection_ids' => ['required', 'array', 'list', 'min:1'],
            'expected_active_collection_ids.*' => ['required', 'string', 'ulid', 'distinct:strict'],
            'lines' => ['required', 'array', 'list', 'min:1'],
            'lines.*' => ['required', 'array'],
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
            'cancellation_reason' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $normalized = trim((string) $value);

                    if ($normalized === '' || mb_strlen($normalized) > 500) {
                        $fail('collections:invalid_cancellation_reason');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_active_collection_ids.required' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.array' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.list' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.min' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.*.required' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.*.string' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.*.ulid' => 'collections:invalid_expected_active_collection_ids',
            'expected_active_collection_ids.*.distinct' => 'collections:invalid_expected_active_collection_ids',
            'lines.required' => 'collections:unexpected_request_fields',
            'lines.array' => 'collections:unexpected_request_fields',
            'lines.list' => 'collections:unexpected_request_fields',
            'lines.min' => 'collections:unexpected_request_fields',
            'lines.*.array' => 'collections:unexpected_request_fields',
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
            'cancellation_reason.required' => 'collections:invalid_cancellation_reason',
            'cancellation_reason.string' => 'collections:invalid_cancellation_reason',
        ];
    }
}
