<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Support;

use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\Exceptions\ReceivablesException;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use Illuminate\Database\QueryException;

final class ReceivablesDatabaseExceptionMapper
{
    public function map(QueryException $exception): ReceivablesException
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        if (in_array($state, ['40P01', '40001', '55P03'], true)) {
            return new ReceivablesConflict('Receivables is busy. Retry the operation.', previous: $exception);
        }
        if ($state === '23505') {
            return new ReceivablesConflict('Receivables uniqueness conflict.', previous: $exception);
        }
        if (in_array($state, ['23503', '23514', '55000'], true)) {
            return new ReceivablesValidationFailed('Receivables integrity validation failed.', previous: $exception);
        }

        return new ReceivablesException('Receivables persistence failed.', previous: $exception);
    }
}
