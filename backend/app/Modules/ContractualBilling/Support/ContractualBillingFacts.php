<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ContractualBillingFacts
{
    public static function operation(
        array $input,
        string $field,
    ): string {
        $value = (string) ($input[$field] ?? '');

        if (! Str::isUlid($value)) {
            throw new ContractualBillingValidationFailed(
                "{$field} must be a caller-supplied ULID.",
            );
        }

        return $value;
    }

    public static function ulid(
        array $input,
        string $field,
    ): string {
        $value = (string) ($input[$field] ?? '');

        if (! Str::isUlid($value)) {
            throw new ContractualBillingValidationFailed(
                "{$field} must be a ULID.",
            );
        }

        return $value;
    }

    public static function amount(
        array $input,
        string $field = 'amount',
    ): string {
        $value = trim((string) ($input[$field] ?? ''));

        if (
            ! preg_match(
                '/^(?:0|[1-9][0-9]{0,16})(?:\.[0-9]{1,2})?$/',
                $value,
            )
        ) {
            throw new ContractualBillingValidationFailed(
                "{$field} must be a positive NUMERIC(19,2) amount.",
            );
        }

        $amount = BigDecimal::of($value);

        if ($amount->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new ContractualBillingValidationFailed(
                "{$field} must be greater than zero.",
            );
        }

        return (string) $amount->toScale(2);
    }

    public static function date(
        array $input,
        string $field,
    ): string {
        $value = (string) ($input[$field] ?? '');

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value,
                'UTC',
            );
        } catch (\Throwable) {
            $date = false;
        }

        if (
            $date === false
            || $date->format('Y-m-d') !== $value
        ) {
            throw new ContractualBillingValidationFailed(
                "{$field} must be an explicit YYYY-MM-DD business date.",
            );
        }

        return $value;
    }

    public static function optionalText(
        array $input,
        string $field,
        int $maxLength = 500,
    ): ?string {
        if (! array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }

        $value = trim((string) $input[$field]);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new ContractualBillingValidationFailed(
                "{$field} must not exceed {$maxLength} characters.",
            );
        }

        return $value;
    }

    public static function text(
        array $input,
        string $field,
        int $maxLength = 500,
    ): string {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value === '') {
            throw new ContractualBillingValidationFailed(
                "{$field} is required.",
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw new ContractualBillingValidationFailed(
                "{$field} must not exceed {$maxLength} characters.",
            );
        }

        return $value;
    }
}
