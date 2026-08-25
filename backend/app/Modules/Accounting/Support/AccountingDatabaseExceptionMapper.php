<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Exceptions\AccountingLockTimeout;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use Illuminate\Database\QueryException;

final class AccountingDatabaseExceptionMapper
{
    public function map(QueryException $exception): AccountingException
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $message = $exception->getMessage();
        if ($state === '55P03') {
            return new AccountingLockTimeout('Accounting lock wait timed out.', previous: $exception);
        }
        if ($state === '23505') {
            return new AccountingConflict('Accounting uniqueness conflict.', previous: $exception);
        }
        if (in_array($state, ['23503', '23514', '55000'], true)) {
            return new AccountingValidationFailed('Accounting integrity validation failed.', previous: $exception);
        }

        return new AccountingException('Accounting persistence failed.', previous: $exception);
    }
}
