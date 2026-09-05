<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EntitlementReceivableSourceCorrection
{
    public function __construct(
        private readonly CancelLinkedReceivablePrimitive $cancelReceivable,
    ) {}

    /**
     * Lock the complete downstream set after the caller has locked the exact
     * Entitlement set. The frozen corridor is Entitlements -> Links ->
     * Receivables; all multi-row sets use deterministic ordering.
     *
     * @return array{links:Collection<int,object>,receivables:Collection<int,object>}
     */
    public function lockForFirstCorrection(
        string $tenantId,
        Collection $entitlements,
    ): array {
        $entitlementIds = $entitlements
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($entitlementIds === []) {
            return [
                'links' => collect(),
                'receivables' => collect(),
            ];
        }

        $links = DB::table('entitlement_receivable_links')
            ->where('tenant_id', $tenantId)
            ->whereIn('entitlement_id', $entitlementIds)
            ->orderBy('entitlement_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $receivableIds = $links
            ->pluck('receivable_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();

        $receivables = $receivableIds === []
            ? collect()
            : DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $receivableIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        $this->assertFirstCorrectionState(
            $entitlements,
            $links,
            $receivables,
        );

        return [
            'links' => $links,
            'receivables' => $receivables,
        ];
    }

    /**
     * @param  array{links:Collection<int,object>,receivables:Collection<int,object>}  $locked
     */
    public function cancelLinkedReceivables(
        string $tenantId,
        array $locked,
        User $actor,
        string $sourceCorrectionOperationId,
        CarbonImmutable $at,
        string $reason,
    ): void {
        $receivables = $locked['receivables']->keyBy('id');

        foreach ($locked['links'] as $link) {
            $receivable = $receivables->get($link->receivable_id);

            if ($receivable === null) {
                throw new ContractualBillingConflict(
                    'Linked Receivable disappeared during source correction.',
                );
            }

            $this->cancelReceivable->cancelLocked(
                $tenantId,
                $link,
                $receivable,
                $actor,
                $sourceCorrectionOperationId,
                $at,
                $reason,
            );
        }
    }

    /**
     * Replay runs after the caller has locked the complete historical
     * Entitlement set. It then locks Links before Receivables and proves the
     * already-committed downstream terminal truth.
     */
    public function assertReplayCoherent(
        string $tenantId,
        Collection $entitlements,
        string $sourceCorrectionOperationId,
    ): void {
        $entitlementIds = $entitlements
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($entitlementIds === []) {
            return;
        }

        $links = DB::table('entitlement_receivable_links')
            ->where('tenant_id', $tenantId)
            ->whereIn('entitlement_id', $entitlementIds)
            ->orderBy('entitlement_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $receivableIds = $links
            ->pluck('receivable_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->sort(SORT_STRING)
            ->values()
            ->all();

        $receivables = $receivableIds === []
            ? collect()
            : DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $receivableIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        $entitlementMap = $entitlements->keyBy('id');
        $receivableMap = $receivables->keyBy('id');

        foreach ($links as $link) {
            $entitlement = $entitlementMap->get($link->entitlement_id);
            $receivable = $receivableMap->get($link->receivable_id);

            if (
                $entitlement === null
                || $receivable === null
                || $entitlement->status !== 'reversed'
                || $receivable->status !== 'cancelled'
                || $link->source_correction_operation_id
                    !== $sourceCorrectionOperationId
                || $entitlement->source_correction_operation_id
                    !== $sourceCorrectionOperationId
            ) {
                throw new ContractualBillingConflict(
                    'Source correction replay found inconsistent linked Receivable history.',
                );
            }
        }
    }

    private function assertFirstCorrectionState(
        Collection $entitlements,
        Collection $links,
        Collection $receivables,
    ): void {
        $entitlementMap = $entitlements->keyBy('id');
        $receivableMap = $receivables->keyBy('id');

        foreach ($links as $link) {
            $entitlement = $entitlementMap->get($link->entitlement_id);
            $receivable = $receivableMap->get($link->receivable_id);

            if (
                $entitlement === null
                || $receivable === null
                || $entitlement->status !== 'effective'
                || $link->source_correction_operation_id !== null
                || $receivable->status !== 'recognized'
            ) {
                throw new ContractualBillingConflict(
                    'First source correction found incoherent linked Receivable state.',
                );
            }
        }
    }
}
