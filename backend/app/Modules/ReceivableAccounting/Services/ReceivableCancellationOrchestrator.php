<?php

declare(strict_types=1);

namespace App\Modules\ReceivableAccounting\Services;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ReceivableCancellationOrchestrator
{
    public function __construct(private readonly CancelReceivableAction $cancel, private readonly ReverseJournalAction $reverse) {}

    public function execute(string $tenantId, string $receivableId, User $actor, string $cancelledAt, string $reason, ?string $reversalDate): void
    {
        [$at, $reason] = $this->cancel->normalize($cancelledAt, $reason);
        DB::transaction(function () use ($tenantId, $receivableId, $actor, $at, $reason, $reversalDate): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");
            $receivable = DB::table('receivables')->where('tenant_id', $tenantId)->where('id', $receivableId)->lockForUpdate()->first();
            if ($receivable === null) {
                throw (new ModelNotFoundException)->setModel('Receivable');
            }
            $journal = DB::table('journal_entries')->where('tenant_id', $tenantId)
                ->where('origin', 'business')->where('source_type', ReceivableAccountingIntegration::SOURCE_TYPE)
                ->where('source_id', $receivableId)->first();
            if ($journal !== null) {
                if ($reversalDate === null) {
                    throw new ReceivablesValidationFailed('reversal_date is required for an accounting-effective Receivable.');
                }
                $this->reverse->execute($tenantId, (string) $journal->id, $actor, $reversalDate, $reason, true);
            }
            $this->cancel->cancelInTransaction($tenantId, $receivableId, $actor, $at, $reason);
        });
    }
}
