<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Support;

use Illuminate\Database\QueryException;

final class RecognitionOperationViolation
{
    public const CONSTRAINT = 'receivables_tenant_recognition_operation_unique';

    public function matches(QueryException $exception): bool
    {
        if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
            return false;
        }

        $diagnostic = (string) ($exception->errorInfo[2] ?? '');

        return preg_match('/constraint "([^"]+)"/i', $diagnostic, $matches) === 1
            && ($matches[1] ?? null) === self::CONSTRAINT;
    }
}
