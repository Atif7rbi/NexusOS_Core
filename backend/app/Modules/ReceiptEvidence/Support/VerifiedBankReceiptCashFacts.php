<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Support;

use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;

final class VerifiedBankReceiptCashFacts
{
    public static function accountId(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw new ReceiptEvidenceValidationFailed("{$field} is required.");
        }

        return $value;
    }
}
