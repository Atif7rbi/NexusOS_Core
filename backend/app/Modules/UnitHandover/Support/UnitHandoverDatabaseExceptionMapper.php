<?php

declare(strict_types=1);

namespace App\Modules\UnitHandover\Support;

use App\Modules\UnitHandover\Exceptions\UnitHandoverConflict;
use App\Modules\UnitHandover\Exceptions\UnitHandoverException;
use App\Modules\UnitHandover\Exceptions\UnitHandoverValidationFailed;
use Illuminate\Database\QueryException;

final class UnitHandoverDatabaseExceptionMapper
{
    public function map(
        QueryException $exception,
    ): UnitHandoverException {
        $state = (string) ($exception->errorInfo[0] ?? '');

        if (in_array($state, ['40P01', '40001', '55P03'], true)) {
            return new UnitHandoverConflict(
                'Unit Handover is busy. Retry the operation.',
                previous: $exception,
            );
        }

        if ($state === '23505') {
            return new UnitHandoverConflict(
                'Unit Handover uniqueness conflict.',
                previous: $exception,
            );
        }

        if (in_array($state, ['23503', '23514', '55000'], true)) {
            return new UnitHandoverValidationFailed(
                'Unit Handover integrity validation failed.',
                previous: $exception,
            );
        }

        return new UnitHandoverException(
            'Unit Handover persistence failed.',
            previous: $exception,
        );
    }
}
