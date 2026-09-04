<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingValidationFailed;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RemoveDraftContractualBillingObligation
{
    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        string $obligationId,
        User $actor,
        array $input,
    ): void {
        if (! Str::isUlid($obligationId)) {
            throw new ContractualBillingValidationFailed(
                'obligation_id must be a ULID.',
            );
        }

        $operationId = ContractualBillingFacts::operation(
            $input,
            'removal_operation_id',
        );

        $reason = ContractualBillingFacts::text(
            $input,
            'removal_reason',
        );

        $this->auth->authorize($tenantId, $actor);

        $this->tx->run(function () use (
            $tenantId,
            $obligationId,
            $actor,
            $operationId,
            $reason,
        ): void {
            $this->auth->authorizeTransactional(
                $tenantId,
                $actor,
            );

            $identity = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->first();

            if ($identity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingObligation');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $schedule = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $identity->schedule_id)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $obligation = DB::table(
                'contractual_billing_obligations',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->lockForUpdate()
                ->first();

            if (
                $obligation === null
                || $obligation->contract_id !== $contract->id
                || $obligation->schedule_id !== $schedule->id
            ) {
                throw new ContractualBillingConflict(
                    'Obligation provenance changed during removal.',
                );
            }

            if ($schedule->status !== 'draft') {
                throw new ContractualBillingConflict(
                    'Only a draft Schedule can remove Obligations.',
                );
            }

            if (
                $obligation->draft_membership_status
                === 'removed'
            ) {
                if (
                    $obligation->removal_operation_id
                        !== $operationId
                    || $obligation->removal_reason !== $reason
                ) {
                    throw new ContractualBillingConflict(
                        'Obligation removal was replayed with different facts.',
                    );
                }

                return;
            }

            if (
                $obligation->draft_membership_status
                !== 'included'
            ) {
                throw new ContractualBillingConflict(
                    'Obligation is not removable.',
                );
            }

            DB::table('contractual_billing_obligations')
                ->where('tenant_id', $tenantId)
                ->where('id', $obligationId)
                ->update([
                    'draft_membership_status' => 'removed',
                    'removal_operation_id' => $operationId,
                    'removed_by' => $actor->id,
                    'removed_at' => now(),
                    'removal_reason' => $reason,
                    'updated_at' => now(),
                ]);
        });
    }
}
