<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Actions;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingConflict;
use App\Modules\ContractualBilling\Support\ContractualBillingAuthorization;
use App\Modules\ContractualBilling\Support\ContractualBillingFacts;
use App\Modules\ContractualBilling\Support\ContractualBillingTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateContractualBillingSchedule
{
    private const BILLING_MODEL =
        'fixed_date_unconditional_full_schedule';

    public function __construct(
        private readonly ContractualBillingTransaction $tx,
        private readonly ContractualBillingAuthorization $auth,
    ) {}

    public function execute(
        string $tenantId,
        User $actor,
        array $input,
    ): string {
        $contractId = ContractualBillingFacts::ulid(
            $input,
            'contract_id',
        );

        $operationId = ContractualBillingFacts::operation(
            $input,
            'schedule_operation_id',
        );

        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use (
            $tenantId,
            $actor,
            $contractId,
            $operationId,
        ): string {
            $this->auth->authorizeTransactional(
                $tenantId,
                $actor,
            );

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)
                    ->setModel('Contract');
            }

            $existing = DB::table(
                'contractual_billing_schedules',
            )
                ->where('tenant_id', $tenantId)
                ->where(
                    'schedule_operation_id',
                    $operationId,
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (
                    $existing->contract_id !== $contractId
                    || $existing->billing_model !== self::BILLING_MODEL
                    || $existing->replaces_schedule_id !== null
                ) {
                    throw new ContractualBillingConflict(
                        'Schedule operation identity was reused with different facts.',
                    );
                }

                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            $now = now();

            DB::table('contractual_billing_schedules')
                ->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'schedule_operation_id' => $operationId,
                    'billing_model' => self::BILLING_MODEL,
                    'status' => 'draft',
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            return $id;
        });
    }
}
