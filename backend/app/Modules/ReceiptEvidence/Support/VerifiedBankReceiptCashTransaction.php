<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Phase 8F owns 23505 recovery so callers can resolve canonical replay after rollback. */
final class VerifiedBankReceiptCashTransaction
{
    public function run(callable $callback): mixed
    {
        $attempt = 0;
        beginning:
        try {
            return DB::transaction(function () use ($callback) {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '30s'");

                return $callback();
            });
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? '');
            if (in_array($state, ['40P01', '40001'], true) && ++$attempt < 3) {
                usleep(random_int(10_000, 40_000) * $attempt);

                goto beginning;
            }

            throw $exception;
        }
    }
}
