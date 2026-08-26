<?php

declare(strict_types=1);

namespace App\Modules\ReceivableAccounting\Services;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use App\Modules\Receivables\Support\RecognitionOperationViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReceivableAccountingRaceResolver
{
    public function __construct(
        private readonly RecognitionOperationViolation $recognitionOperation,
        private readonly RecognizeReceivableAction $recognize,
        private readonly ReceivableAccountingRecoveryResolver $recovery,
    ) {}

    /** @param array<int,JournalLineData> $orderedLines */
    public function resolveAfterRollback(QueryException $exception, string $tenantId, array $recognition, int $actorId, string $entryDate, string $description, array $orderedLines): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Integrated recognition race resolution requires a completed outer rollback.');
        }
        if (! $this->recognitionOperation->matches($exception)) {
            throw $exception;
        }

        [$operationId, $facts] = $this->recognize->canonicalize($recognition);

        return $this->recovery->resolve($tenantId, $operationId, $actorId, $entryDate, $description, $orderedLines, $facts);
    }
}
