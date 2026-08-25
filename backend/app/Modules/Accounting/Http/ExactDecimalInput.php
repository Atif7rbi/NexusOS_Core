<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ExactDecimalInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^(?:0|[1-9]\d{0,16})(?:\.\d{1,2})?$/', (string) $value)) {
            $fail("The {$attribute} field must be an exact decimal string or integer with at most two decimal places.");
        }
    }
}
