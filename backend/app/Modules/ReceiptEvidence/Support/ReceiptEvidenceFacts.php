<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Support;

use App\Modules\Payments\ValueObjects\PaymentAmount;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ReceiptEvidenceFacts
{
    public static function operation(array $input, string $field): string
    {
        $value = (string) ($input[$field] ?? '');
        if (! Str::isUlid($value)) {
            throw new ReceiptEvidenceValidationFailed("{$field} must be a caller-supplied ULID.");
        }

        return $value;
    }

    public static function date(array $input, string $field): string
    {
        $value = (string) ($input[$field] ?? '');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ReceiptEvidenceValidationFailed("{$field} must be a YYYY-MM-DD date.");
        }

        return $value;
    }

    public static function reason(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw new ReceiptEvidenceValidationFailed("{$field} is required.");
        }

        return $value;
    }

    public static function amount(array $input, string $field = 'amount'): string
    {
        return (string) PaymentAmount::of((string) ($input[$field] ?? ''));
    }

    public static function currency(array $input, string $field = 'currency'): string
    {
        $value = (string) ($input[$field] ?? '');
        if (! preg_match('/^[A-Z]{3}$/', $value)) {
            throw new ReceiptEvidenceValidationFailed("{$field} must be an uppercase ISO currency code.");
        }

        return $value;
    }
}
