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

final class CreateSuccessorContractualBillingSchedule
{
    private const BILLING_MODEL =
        'fixed_date_unconditional_full_schedule';

    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        string $sourceScheduleId,
        User $actor,
        array $input,
    ): string {
        if (! Str::isUlid($sourceScheduleId)) {
            throw new ContractualBillingValidationFailed(
                'source_schedule_id must be a ULID.',
            );
        }

        $operationId = ContractualBillingFacts::operation(
            $input,
            'schedule_operation_id',
        );

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $sourceScheduleId,
            $actor,
            $operationId,
        ): string {
            $this->auth->authorizeTransactional($tenantId, $actor);

            $sourceIdentity = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->first();

            if ($sourceIdentity === null) {
                throw (new ModelNotFoundException)
                    ->setModel('ContractualBillingSchedule');
            }

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceIdentity->contract_id)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $source = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('id', $sourceScheduleId)
                ->lockForUpdate()
                ->first();

            if (
                $source === null
                || $source->contract_id !== $contract->id
            ) {
                throw new ContractualBillingConflict(
                    'Source Schedule provenance changed.',
                );
            }

            if ($source->status !== 'finalized') {
                throw new ContractualBillingConflict(
                    'A successor Schedule requires a finalized predecessor.',
                );
            }

            $existing = DB::table('contractual_billing_schedules')
                ->where('tenant_id', $tenantId)
                ->where('schedule_operation_id', $operationId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (
                    $existing->contract_id !== $contract->id
                    || $existing->billing_model !== self::BILLING_MODEL
                    || $existing->replaces_schedule_id
                        !== $sourceScheduleId
                ) {
                    throw new ContractualBillingConflict(
                        'Successor Schedule operation identity was reused with different facts.',
                    );
                }

                return (string) $existing->id;
            }

            $alreadyExists = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where('replaces_schedule_id', $sourceScheduleId)
                ->whereIn('status', ['draft', 'finalized'])
                ->lockForUpdate()
                ->first();

            if ($alreadyExists !== null) {
                throw new ContractualBillingConflict(
                    'Source Schedule already has a live successor candidate.',
                );
            }

            $id = (string) Str::ulid();
            $now = now();

            DB::table('contractual_billing_schedules')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'contract_id' => (string) $contract->id,
                'schedule_operation_id' => $operationId,
                'billing_model' => self::BILLING_MODEL,
                'status' => 'draft',
                'replaces_schedule_id' => $sourceScheduleId,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        });
    }
}
