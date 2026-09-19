<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Actions;

use App\Models\User;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionValidationFailed;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionAuthorization;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdoptPerformanceAccounting
{
    public const SCOPE = 'PERFORMANCE_ACCOUNTING_V1';

    public const BASIS = 'CLEAN_PROTOCOL_READY';

    public function __construct(
        private readonly AccountingRecognitionTransaction $transaction,
        private readonly AccountingRecognitionAuthorization $authorization,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): array
    {
        $allowed = [
            'contract_id',
            'performance_accounting_adoption_operation_id',
        ];
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected !== []) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting adoption accepts identities only.',
            );
        }

        $contractId = (string) ($input['contract_id'] ?? '');
        $operationId = (string) (
            $input['performance_accounting_adoption_operation_id'] ?? ''
        );

        if (! Str::isUlid($contractId) || ! Str::isUlid($operationId)) {
            throw new AccountingRecognitionValidationFailed(
                'Performance Accounting adoption identities must be ULIDs.',
            );
        }

        $this->authorization->authorize($tenantId, $actor);

        return $this->transaction->run(function () use (
            $tenantId,
            $actor,
            $contractId,
            $operationId,
        ): array {
            $this->authorization->authorizeTransactional($tenantId, $actor);

            $contract = DB::table('contracts')
                ->where('tenant_id', $tenantId)
                ->where('id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw (new ModelNotFoundException)->setModel('Contract');
            }

            $position = DB::table('contract_consideration_positions')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->lockForUpdate()
                ->first();

            if ($position === null || $position->status !== 'ADOPTED') {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting requires an adopted Contract Consideration root.',
                );
            }

            $byContract = DB::table('performance_accounting_adoptions')
                ->where('tenant_id', $tenantId)
                ->where('contract_id', $contractId)
                ->first();
            $byOperation = DB::table('performance_accounting_adoptions')
                ->where('tenant_id', $tenantId)
                ->where(
                    'performance_accounting_adoption_operation_id',
                    $operationId,
                )
                ->first();

            if ($byContract !== null || $byOperation !== null) {
                if (
                    $byContract === null
                    || $byOperation === null
                    || $byContract->id !== $byOperation->id
                    || $byContract->contract_id !== $contractId
                    || $byContract->consideration_position_id !== $position->id
                    || $byContract->performance_accounting_adoption_operation_id
                        !== $operationId
                    || $byContract->accounting_scope_version !== self::SCOPE
                    || $byContract->adoption_basis !== self::BASIS
                    || (string) $byContract->consideration_amount
                        !== (string) $contract->total_amount
                    || $byContract->currency !== 'SAR'
                ) {
                    throw new AccountingRecognitionConflict(
                        'Performance Accounting adoption identity conflicts with historical truth.',
                    );
                }

                return [
                    'status' => 'committed',
                    'adoption_id' => (string) $byContract->id,
                    'consideration_position_id' => (string) $position->id,
                ];
            }

            if (
                $contract->currency !== 'SAR'
                || $position->currency !== 'SAR'
                || (string) $contract->total_amount
                    !== (string) $position->consideration_amount
            ) {
                throw new AccountingRecognitionConflict(
                    'Performance Accounting adoption requires exact SAR Contract consideration.',
                );
            }

            if (
                DB::table('unit_handover_acceptances')
                    ->where('tenant_id', $tenantId)
                    ->where('contract_id', $contractId)
                    ->where('status', 'effective')
                    ->exists()
            ) {
                throw new AccountingRecognitionConflict(
                    'Clean Performance Accounting adoption rejects prior effective performance.',
                );
            }

            if (
                DB::table('accounting_position_origins')
                    ->where('tenant_id', $tenantId)
                    ->where('contract_id', $contractId)
                    ->where('position_type', 'CONTRACT_ASSET')
                    ->exists()
                || DB::table('accounting_position_consumptions')
                    ->where('tenant_id', $tenantId)
                    ->where('contract_id', $contractId)
                    ->exists()
            ) {
                throw new AccountingRecognitionConflict(
                    'Clean Performance Accounting adoption rejects prior performance-accounting protocol history.',
                );
            }

            $billedUnearned = DB::select(
                <<<'SQL'
                    SELECT
                      lot.id,
                      lot.transition_id,
                      lot.amount - COALESCE(SUM(edge.consumed_amount) FILTER (
                        WHERE consumer.status='effective'
                      ),0) AS remaining
                    FROM contract_consideration_lots lot
                    JOIN contract_consideration_transitions creator
                      ON creator.tenant_id=lot.tenant_id
                     AND creator.id=lot.transition_id
                    LEFT JOIN contract_consideration_transition_lots edge
                      ON edge.tenant_id=lot.tenant_id
                     AND edge.lot_id=lot.id
                    LEFT JOIN contract_consideration_transitions consumer
                      ON consumer.tenant_id=edge.tenant_id
                     AND consumer.id=edge.transition_id
                    WHERE lot.tenant_id=?
                      AND lot.position_id=?
                      AND lot.semantic_position='BILLED_UNEARNED'
                      AND creator.status='effective'
                    GROUP BY lot.id,lot.transition_id,lot.amount
                    HAVING lot.amount - COALESCE(SUM(edge.consumed_amount) FILTER (
                      WHERE consumer.status='effective'
                    ),0) > 0
                    ORDER BY lot.id
                    SQL,
                [$tenantId, $position->id],
            );

            foreach ($billedUnearned as $lot) {
                DB::table('accounting_position_origins')
                    ->where('tenant_id', $tenantId)
                    ->where('contract_id', $contractId)
                    ->where('position_type', 'CONTRACT_LIABILITY')
                    ->where('status', 'effective')
                    ->where('consideration_transition_id', $lot->transition_id)
                    ->where('consideration_lot_id', $lot->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $capacity = DB::selectOne(
                    <<<'SQL'
                        SELECT COALESCE(SUM(
                          origin.origin_amount - COALESCE((
                            SELECT SUM(consumption.amount)
                            FROM accounting_position_consumptions consumption
                            WHERE consumption.tenant_id=origin.tenant_id
                              AND consumption.origin_id=origin.id
                              AND consumption.status='effective'
                          ),0)
                        ),0) AS available
                        FROM accounting_position_origins origin
                        WHERE origin.tenant_id=?
                          AND origin.contract_id=?
                          AND origin.position_type='CONTRACT_LIABILITY'
                          AND origin.status='effective'
                          AND origin.consideration_transition_id=?
                          AND origin.consideration_lot_id=?
                        SQL,
                    [
                        $tenantId,
                        $contractId,
                        $lot->transition_id,
                        $lot->id,
                    ],
                );

                $sufficient = (bool) DB::selectOne(
                    'SELECT ?::numeric >= ?::numeric AS sufficient',
                    [(string) $capacity->available, (string) $lot->remaining],
                )->sufficient;

                if (! $sufficient) {
                    throw new AccountingRecognitionConflict(
                        'Clean Performance Accounting adoption requires exact protocol-backed Contract Liability capacity.',
                    );
                }
            }

            $id = (string) Str::ulid();
            $now = CarbonImmutable::now('UTC');

            DB::table('performance_accounting_adoptions')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'consideration_position_id' => $position->id,
                'performance_accounting_adoption_operation_id' => $operationId,
                'status' => 'ADOPTED',
                'accounting_scope_version' => self::SCOPE,
                'adoption_basis' => self::BASIS,
                'consideration_amount' => (string) $contract->total_amount,
                'currency' => 'SAR',
                'adopted_by' => $actor->id,
                'adopted_at' => $now,
            ]);

            return [
                'status' => 'committed',
                'adoption_id' => $id,
                'consideration_position_id' => (string) $position->id,
            ];
        });
    }
}
