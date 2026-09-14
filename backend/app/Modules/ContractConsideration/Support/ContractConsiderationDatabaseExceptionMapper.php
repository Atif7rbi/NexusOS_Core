<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationIntegrityFault;
use Illuminate\Database\QueryException;

final class ContractConsiderationDatabaseExceptionMapper
{
    public function map(QueryException $exception): \Throwable
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        if (in_array($state, ['23514', '55000'], true)) {
            return new ContractConsiderationIntegrityFault(
                'Contract Consideration database integrity rejected the requested state.',
                previous: $exception,
            );
        }

        return new ContractConsiderationConflict(
            'Contract Consideration database operation failed.',
            previous: $exception,
        );
    }
}
