<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EstablishEntitlementReceivable
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
        private readonly RecognizeReceivableAction $recognize,
    ) {}

    public function execute(
        string $tenantId,
        string $entitlementId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($entitlementId)) {
            throw new ContractualBillingValidationFailed(
                'entitlement_id must be a ULID.',
            );
        }

        $operationId = (string) (
            $input['receivable_establishment_operation_id'] ?? ''
        );

        if (! Str::isUlid($operationId)) {
            throw new ContractualBillingValidationFailed(
                'receivable_establishment_operation_id must be a caller-supplied ULID.',
            );
        }

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $entitlementId,
            $operationId,
            $actor,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            /*
             * Identity discovery is intentionally unlocked. It only supplies
             * parent identities; authoritative facts are revalidated after the
             * frozen parent-to-child locks are acquired.
             */
            $identity = DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingEntitlement');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)->setModel('Contract');
            }

            $schedule = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->schedule_id)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $obligation = DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->obligation_id)
                ->lockForUpdate()
                ->first();

            if ($obligation === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingObligation');
            }

            $entitlement = DB::table('contractual_billing_entitlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $entitlementId)
                ->lockForUpdate()
                ->first();

            if ($entitlement === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingEntitlement');
            }

            $this->assertSourceIdentity(
                $contract,
                $schedule,
                $obligation,
                $entitlement,
            );

            /*
             * Link discovery while Entitlement is locked is stable against
             * both another establishment and source correction. Existing Link
             * rows are not locked before their target Receivable; this keeps
             * the establishment/replay corridor Entitlement -> Receivable -> Link.
             */
            $byEntitlement = DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where('entitlement_id', $entitlementId)
                ->first();

            $byOperation = DB::table('entitlement_receivable_links')
                ->where('tenant_id', $tenantId)
                ->where('receivable_establishment_operation_id', $operationId)
                ->first();

            if ($byEntitlement !== null || $byOperation !== null) {
                if (
                    $byEntitlement === null
                    || $byOperation === null
                    || $byEntitlement->id !== $byOperation->id
                    || $byEntitlement->entitlement_id !== $entitlementId
                    || $byEntitlement->receivable_establishment_operation_id
                        !== $operationId
                ) {
                    throw new ContractualBillingConflict(
                        'Receivable establishment operation conflicts with historical Entitlement consumption.',
                    );
                }

                return $this->replayLocked(
                    $tenantId,
                    $entitlement,
                    $byEntitlement,
                    $operationId,
                );
            }

            $orphanReceivable = DB::table('receivables')
                ->where('tenant_id', $tenantId)
                ->where('recognition_operation_id', $operationId)
                ->first();

            if ($orphanReceivable !== null) {
                throw new ContractualBillingConflict(
                    'Receivable establishment found Receivable truth without Entitlement provenance.',
                );
            }

            if (
                $entitlement->status !== 'effective'
                || $schedule->status !== 'finalized'
                || $obligation->draft_membership_status !== 'included'
                || ! in_array($contract->status, ['active', 'completed'], true)
                || $entitlement->currency !== 'SAR'
                || $contract->currency !== 'SAR'
            ) {
                throw new ContractualBillingConflict(
                    'Contractual Billing Entitlement is not eligible for Receivable establishment.',
                );
            }

            $recognizedAt = CarbonImmutable::now('UTC');

            $facts = [
                'customer_id' => (string) $entitlement->customer_id,
                'contract_id' => (string) $entitlement->contract_id,
                'collection_id' => null,
                'currency' => (string) $entitlement->currency,
                'recognized_amount' => (string) $entitlement->amount,
                'due_date' => (string) $entitlement->economic_date,
                'recognized_at' => $recognizedAt,
            ];

            $receivableId = $this->recognize
                ->recognizeAuthorizedInTransaction(
                    $tenantId,
                    $actor,
                    $operationId,
                    $facts,
                );

            $now = now();

            DB::table('entitlement_receivable_links')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'entitlement_id' => $entitlementId,
                'receivable_id' => $receivableId,
                'receivable_establishment_operation_id' => $operationId,
                'source_correction_operation_id' => null,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $receivableId;
        });
    }

    private function assertSourceIdentity(
        object $contract,
        object $schedule,
        object $obligation,
        object $entitlement,
    ): void {
        if (
            $schedule->contract_id !== $contract->id
            || $obligation->contract_id !== $contract->id
            || $obligation->schedule_id !== $schedule->id
            || $entitlement->contract_id !== $contract->id
            || $entitlement->schedule_id !== $schedule->id
            || $entitlement->obligation_id !== $obligation->id
            || $entitlement->customer_id !== $obligation->customer_id
            || (string) $entitlement->amount
                !== (string) $obligation->amount
            || $entitlement->currency !== $obligation->currency
            || (string) $entitlement->economic_date
                !== (string) $obligation->contractual_due_date
        ) {
            throw new ContractualBillingConflict(
                'Entitlement source provenance changed or is inconsistent.',
            );
        }
    }

    private function replayLocked(
        string $tenantId,
        object $entitlement,
        object $discoveredLink,
        string $operationId,
    ): string {
        $receivable = DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $discoveredLink->receivable_id)
            ->lockForUpdate()
            ->first();

        if ($receivable === null) {
            throw new ContractualBillingConflict(
                'Receivable establishment replay found missing Receivable truth.',
            );
        }

        $link = DB::table('entitlement_receivable_links')
            ->where('tenant_id', $tenantId)
            ->where('id', $discoveredLink->id)
            ->lockForUpdate()
            ->first();

        if (
            $link === null
            || $link->entitlement_id !== $entitlement->id
            || $link->receivable_id !== $receivable->id
            || $link->receivable_establishment_operation_id !== $operationId
            || $receivable->recognition_operation_id !== $operationId
            || $receivable->contract_id !== $entitlement->contract_id
            || $receivable->customer_id !== $entitlement->customer_id
            || $receivable->collection_id !== null
            || (string) $receivable->recognized_amount
                !== (string) $entitlement->amount
            || $receivable->currency !== $entitlement->currency
            || (string) $receivable->due_date
                !== (string) $entitlement->economic_date
        ) {
            throw new ContractualBillingConflict(
                'Receivable establishment replay found inconsistent provenance truth.',
            );
        }

        if ($entitlement->status === 'effective') {
            if (
                $receivable->status !== 'recognized'
                || $link->source_correction_operation_id !== null
            ) {
                throw new ContractualBillingConflict(
                    'Effective establishment replay found inconsistent downstream state.',
                );
            }
        } elseif ($entitlement->status === 'reversed') {
            if (
                $receivable->status !== 'cancelled'
                || $link->source_correction_operation_id === null
                || $link->source_correction_operation_id
                    !== $entitlement->source_correction_operation_id
            ) {
                throw new ContractualBillingConflict(
                    'Historical establishment replay found inconsistent correction state.',
                );
            }
        } else {
            throw new ContractualBillingConflict(
                'Receivable establishment replay found unsupported Entitlement state.',
            );
        }

        return (string) $receivable->id;
    }
}
