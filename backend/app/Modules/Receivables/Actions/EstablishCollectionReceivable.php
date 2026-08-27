<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Models\User;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Models\Collection;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Receivables\Support\ReceivablesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5C Collection-backed establishment.
 *
 * This is an explicit business command. It accepts only operation identity,
 * recognition evidence, and the authoritative Collection identity; every
 * canonical Receivable fact is derived while the Contract and Collection are
 * locked. It intentionally does not invoke the Accounting boundary.
 */
final class EstablishCollectionReceivable
{
    public function __construct(
        private readonly ReceivablesTransaction $tx,
        private readonly ReceivablesAuthorization $auth,
        private readonly RecognizeReceivableAction $recognition,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        string $collectionId,
        string $recognitionOperationId,
        string $recognizedAt,
    ): string {
        if (! Str::isUlid($collectionId)) {
            throw new ReceivablesValidationFailed('collection_id must be a ULID.');
        }
        if (! Str::isUlid($recognitionOperationId)) {
            throw new ReceivablesValidationFailed('recognition_operation_id must be a caller-supplied ULID.');
        }
        $timestamp = $this->timestamp($recognizedAt);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $collectionId, $recognitionOperationId, $timestamp): string {
            // Authorization locks precede the source aggregate locks.
            $this->auth->authorizeTransactional($tenantId, $actor);

            // Preserve the Collections global source order: Contract -> Collection.
            $contract = Contract::query()
                ->join('collections', function ($join): void {
                    $join->on('collections.tenant_id', '=', 'contracts.tenant_id')
                        ->on('collections.contract_id', '=', 'contracts.id');
                })
                ->where('contracts.tenant_id', $tenantId)
                ->where('collections.id', $collectionId)
                ->select('contracts.*')
                ->lock('FOR UPDATE OF contracts')
                ->firstOrFail();
            $collection = Collection::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($collectionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $collection->contract_id !== (string) $contract->id || $collection->status !== CollectionStatus::Scheduled) {
                throw new ReceivablesConflict('Collection is not eligible for Receivable establishment.');
            }

            $customerId = DB::table('reservations')
                ->where('tenant_id', $tenantId)
                ->where('id', $contract->reservation_id)
                ->value('customer_id');
            if (! is_string($customerId) || $customerId === '') {
                throw new ReceivablesConflict('Collection customer provenance is unavailable.');
            }

            return $this->recognition->recognizeAuthorizedInTransaction($tenantId, $actor, $recognitionOperationId, [
                'customer_id' => $customerId,
                'contract_id' => (string) $contract->id,
                'collection_id' => (string) $collection->id,
                'currency' => (string) $contract->currency,
                'recognized_amount' => (string) $collection->amount,
                'due_date' => $collection->due_date->format('Y-m-d'),
                'recognized_at' => $timestamp,
            ]);
        });
    }

    private function timestamp(string $value): CarbonImmutable
    {
        try {
            $timestamp = CarbonImmutable::createFromFormat(DATE_RFC3339, $value);
        } catch (\Throwable) {
            $timestamp = false;
        }
        if ($timestamp === false) {
            throw new ReceivablesValidationFailed('recognized_at must be an RFC3339 timestamp.');
        }

        return $timestamp;
    }
}
