<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Http;

use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use App\Modules\Receivables\ValueObjects\RecognizedAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ExactReceivableAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail("The {$attribute} field must be an exact decimal string or integer.");

            return;
        }

        try {
            RecognizedAmount::of($value);
        } catch (ReceivablesValidationFailed $exception) {
            $fail($exception->getMessage());
        }
    }
}
