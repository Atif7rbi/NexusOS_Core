<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingException;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use Illuminate\Database\QueryException;

final class ContractualBillingDatabaseExceptionMapper
{
    public function map(
        QueryException $exception,
    ): ContractualBillingException {
        $state = (string) ($exception->errorInfo[0] ?? '');

        if (in_array($state, ['40P01', '40001', '55P03'], true)) {
            return new ContractualBillingConflict(
                'Contractual Billing is busy. Retry the operation.',
                previous: $exception,
            );
        }

        if ($state === '23505') {
            return new ContractualBillingConflict(
                'Contractual Billing uniqueness conflict.',
                previous: $exception,
            );
        }

        if (in_array($state, ['23503', '23514', '55000'], true)) {
            return new ContractualBillingValidationFailed(
                'Contractual Billing integrity validation failed.',
                previous: $exception,
            );
        }

        return new ContractualBillingException(
            'Contractual Billing persistence failed.',
            previous: $exception,
        );
    }
}
