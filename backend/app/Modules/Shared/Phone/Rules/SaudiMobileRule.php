<?php
declare(strict_types=1);
namespace App\Modules\Shared\Phone\Rules;
use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
final class SaudiMobileRule implements ValidationRule
{
    public function __construct(private readonly SaudiMobileNormalizer $normalizer, private readonly bool $required) {}
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if ($this->required) { $this->normalizer->normalizeRequired($value); return; }
            $this->normalizer->normalizeNullable($value);
        } catch (InvalidSaudiMobileNumberException) {
            $fail('صيغة رقم الجوال غير صحيحة. استخدم رقمًا محليًا من 10 أرقام يبدأ بـ 05.');
        }
    }
}
