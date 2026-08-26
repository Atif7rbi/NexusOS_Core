<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Models\User;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Receivables\Support\ReceivablesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CancelReceivableAction
{
    public function __construct(private readonly ReceivablesTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, string $receivableId, User $actor, string $cancelledAt, string $reason): void
    {
        $this->auth->authorize($tenantId, $actor);
        [$at, $reason] = $this->normalize($cancelledAt, $reason);

        $this->tx->run(fn () => $this->cancelInTransaction($tenantId, $receivableId, $actor, $at, $reason));
    }

    public function cancelInTransaction(string $tenantId, string $receivableId, User $actor, CarbonImmutable $at, string $reason): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receivable cancellation requires a caller-owned transaction.');
        }
        $this->auth->authorizeTransactional($tenantId, $actor);
        $receivable = DB::table('receivables')->where('tenant_id', $tenantId)->where('id', $receivableId)->lockForUpdate()->first();
        if ($receivable === null) {
            throw (new ModelNotFoundException)->setModel('Receivable');
        }
        if ($receivable->status !== 'recognized') {
            throw new ReceivablesConflict('Only a recognized Receivable can be cancelled.');
        }
        DB::table('receivables')->where('tenant_id', $tenantId)->where('id', $receivableId)->update([
            'status' => 'cancelled',
            'cancelled_at' => $at,
            'cancelled_by' => $actor->id,
            'cancellation_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    public function normalize(string $cancelledAt, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReceivablesValidationFailed('cancellation_reason is required.');
        }
        try {
            $at = CarbonImmutable::createFromFormat(DATE_RFC3339, $cancelledAt);
        } catch (\Throwable) {
            $at = false;
        }
        if ($at === false) {
            throw new ReceivablesValidationFailed('cancelled_at must be an RFC3339 timestamp.');
        }

        return [$at, $reason];
    }
}
