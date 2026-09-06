<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\Payments\Support\EffectivePaymentAllocationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Internal child mutation for compound Contractual Billing source correction.
 *
 * The owning orchestration must already have completed transactional
 * ContractualBillingAuthorization and locked the source corridor, Link, and
 * exact Receivable. This primitive deliberately performs no authorization and
 * acquires no Contractual Billing parent locks.
 */
final class CancelLinkedReceivablePrimitive
{
    public function __construct(
        private readonly EffectivePaymentAllocationGuard $allocationGuard,
    ) {}

    public function cancelLocked(
        string $tenantId,
        object $link,
        object $receivable,
        User $actor,
        string $sourceCorrectionOperationId,
        CarbonImmutable $cancelledAt,
        string $reason,
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'Linked Receivable cancellation requires a caller-owned transaction.',
            );
        }

        if (
            $link->tenant_id !== $tenantId
            || $receivable->tenant_id !== $tenantId
            || $link->receivable_id !== $receivable->id
            || $link->source_correction_operation_id !== null
        ) {
            throw new ContractualBillingConflict(
                'Linked Receivable cancellation provenance is inconsistent.',
            );
        }

        if ($receivable->status !== 'recognized') {
            throw new ContractualBillingConflict(
                'First source correction requires a recognized linked Receivable.',
            );
        }

        /*
         * Read-only eligibility guard. It must not acquire Payment locks or
         * introduce a Receivable -> Payment lock corridor.
         */
        $this->allocationGuard->assertNoneForReceivable(
            $tenantId,
            (string) $receivable->id,
        );

        $updatedLink = DB::table('entitlement_receivable_links')
            ->where('tenant_id', $tenantId)
            ->where('id', $link->id)
            ->whereNull('source_correction_operation_id')
            ->update([
                'source_correction_operation_id' => $sourceCorrectionOperationId,
                'updated_at' => $cancelledAt,
            ]);

        if ($updatedLink !== 1) {
            throw new ContractualBillingConflict(
                'Entitlement Receivable Link correction evidence changed concurrently.',
            );
        }

        $updatedReceivable = DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $receivable->id)
            ->where('status', 'recognized')
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_at' => $cancelledAt,
            ]);

        if ($updatedReceivable !== 1) {
            throw new ContractualBillingConflict(
                'Linked Receivable changed concurrently during source correction.',
            );
        }
    }
}
