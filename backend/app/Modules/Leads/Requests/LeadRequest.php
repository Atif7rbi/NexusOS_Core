<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @param list<string> $allowedKeys
     */
    protected function rejectUnknownKeys(array $allowedKeys): void
    {
        if (array_diff(array_keys($this->all()), $allowedKeys) !== []) {
            $this->failValidation(
                'The request contains fields that are not defined by the CRM Leads v1 API contract.',
            );
        }
    }

    protected function normalizeRequiredPhone(): void
    {
        if (! $this->exists('phone')) {
            return;
        }

        try {
            $this->merge([
                'phone' => app(SaudiMobileNormalizer::class)
                    ->normalizeRequired($this->input('phone')),
            ]);
        } catch (InvalidSaudiMobileNumberException) {
            // Preserve rejected input so SaudiMobileRule returns the contract error.
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        $message = (string) collect($validator->errors()->all())->first();

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
            ],
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    protected function failValidation(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
            ],
        ], 422));
    }
}
