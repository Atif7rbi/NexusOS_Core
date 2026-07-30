<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class CollectionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function rejectUnknownKeys(array $input, array $allowedKeys): void
    {
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            $this->fail('unexpected_request_fields', 'The request contains fields that are not defined by the Collections API contract.');
        }
    }

    protected function rejectUnknownLineKeys(mixed $lines, array $allowedKeys): void
    {
        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            if (is_array($line) && array_diff(array_keys($line), $allowedKeys) !== []) {
                $this->fail('unexpected_request_fields', 'A Collection line contains fields that are not defined by the Collections API contract.');
            }
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        $message = (string) collect($validator->errors()->all())->first();
        $code = str_starts_with($message, 'collections:')
            ? substr($message, strlen('collections:'))
            : 'unexpected_request_fields';

        $this->fail($code, $this->messageFor($code));
    }

    protected function fail(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 422));
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'invalid_collection_sequence' => 'Collection sequence must be a positive integer.',
            'blank_collection_title' => 'Collection title must not be blank.',
            'collection_title_too_long' => 'Collection title must not exceed 150 characters.',
            'invalid_collection_amount' => 'Collection amount must be a positive decimal(12,2) value.',
            'excess_decimal_precision' => 'Collection amount must not have more than two decimal places.',
            'invalid_collection_reference' => 'The referenced Collection is invalid.',
            'invalid_cancellation_reason' => 'Cancellation reason is required and must not exceed 500 characters.',
            'invalid_expected_active_collection_ids' => 'Expected active Collection IDs must be a non-empty list of distinct valid ULIDs.',
            'invalid_query_parameter' => 'The query parameter is invalid.',
            default => 'The request contains unexpected or structurally invalid fields.',
        };
    }
}
