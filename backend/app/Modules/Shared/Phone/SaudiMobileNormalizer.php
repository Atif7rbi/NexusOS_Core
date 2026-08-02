<?php
declare(strict_types=1);
namespace App\Modules\Shared\Phone;
final class SaudiMobileNormalizer
{
    private const EDGE_WHITESPACE_PATTERN = '/\A[\x09-\x0D\x20]+|[\x09-\x0D\x20]+\z/u';
    private const CANONICAL_PATTERN = '/\A05[0-9]{8}\z/D';
    private const DIGIT_MAP = [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ];
    public function normalizeRequired(mixed $value): string
    {
        if ($value === null) throw new InvalidSaudiMobileNumberException(InvalidSaudiMobileNumberException::REASON_REQUIRED);
        $normalized = $this->normalizeString($value);
        if ($normalized === '') throw new InvalidSaudiMobileNumberException(InvalidSaudiMobileNumberException::REASON_REQUIRED);
        $this->assertCanonical($normalized);
        return $normalized;
    }
    public function normalizeNullable(mixed $value): ?string
    {
        if ($value === null) return null;
        $normalized = $this->normalizeString($value);
        if ($normalized === '') return null;
        $this->assertCanonical($normalized);
        return $normalized;
    }
    private function normalizeString(mixed $value): string
    {
        if (! is_string($value)) throw new InvalidSaudiMobileNumberException(InvalidSaudiMobileNumberException::REASON_INVALID_TYPE);
        $trimmed = preg_replace(self::EDGE_WHITESPACE_PATTERN, '', $value);
        if ($trimmed === null) throw new InvalidSaudiMobileNumberException(InvalidSaudiMobileNumberException::REASON_INVALID_FORMAT);
        return strtr($trimmed, self::DIGIT_MAP);
    }
    private function assertCanonical(string $value): void
    {
        if (preg_match(self::CANONICAL_PATTERN, $value) !== 1) throw new InvalidSaudiMobileNumberException(InvalidSaudiMobileNumberException::REASON_INVALID_FORMAT);
    }
}
