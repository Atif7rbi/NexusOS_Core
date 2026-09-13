<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverValidationFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class UnitHandoverFacts
{
    public static function operation(
        array $input,
        string $field,
    ): string {
        $value = (string) ($input[$field] ?? '');

        if (! Str::isUlid($value)) {
            throw new UnitHandoverValidationFailed(
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
            throw new UnitHandoverValidationFailed(
                "{$field} must be a ULID.",
            );
        }

        return $value;
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
            throw new UnitHandoverValidationFailed(
                "{$field} must be an explicit YYYY-MM-DD business date.",
            );
        }

        return $value;
    }

    public static function canonicalEvidenceReference(
        array $input,
    ): string {
        $value = strtoupper(trim(
            (string) ($input['handover_evidence_reference'] ?? ''),
        ));

        if (
            ! preg_match(
                '/^[A-Z0-9][A-Z0-9._\/-]{0,63}$/',
                $value,
            )
        ) {
            throw new UnitHandoverValidationFailed(
                'handover_evidence_reference must be a canonical external reference using A-Z, 0-9, dot, underscore, slash, or hyphen and must not exceed 64 characters.',
            );
        }

        return $value;
    }

    public static function text(
        array $input,
        string $field,
        int $maxLength = 255,
    ): string {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value === '') {
            throw new UnitHandoverValidationFailed(
                "{$field} is required.",
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw new UnitHandoverValidationFailed(
                "{$field} must not exceed {$maxLength} characters.",
            );
        }

        return $value;
    }
}
