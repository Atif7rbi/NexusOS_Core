<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Support;

use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionIntegrityFailed;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class AccountingRecognitionTransaction
{
    public function run(
        callable $operation,
        bool $preserveUniqueViolation = false,
    ): mixed {

        try {
            return DB::transaction(function () use ($operation): mixed {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '30s'");

                return $operation();
            }, 3);
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? '');
            if ($preserveUniqueViolation && $state === '23505') {
                throw $exception;
            }
            if (in_array($state, ['23505', '40P01', '40001', '55P03', '57014'], true)) {
                throw new AccountingRecognitionConflict(
                    'Accounting Recognition operation conflicted; retry using the same business identity.',
                    previous: $exception,
                );
            }
            if (in_array($state, ['23503', '23514', '55000'], true)) {
                throw new AccountingRecognitionIntegrityFailed(
                    'PostgreSQL rejected inconsistent Accounting Recognition foundation truth.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
