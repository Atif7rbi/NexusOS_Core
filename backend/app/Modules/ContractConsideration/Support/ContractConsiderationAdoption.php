<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationIntegrityFailed;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContractConsiderationAdoption
{
    public const BASIS = 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES';

    public const SCOPE = 'CONTRACT_CONSIDERATION_V1';

    public function __construct(
        private readonly ContractConsiderationAuthorization $authorization,
        private readonly ContractConsiderationHistory $history,
    ) {}

    /** @return array{string,string} */
    public function identities(string $tenantId, array $input): array
    {
        if (array_diff(array_keys($input), ['contract_id', 'coordination_adoption_operation_id']) !== []) {
            throw new ContractConsiderationValidationFailed('Adoption accepts identities only; canonical facts are source-derived.');
        }
        foreach (['tenant_id' => $tenantId, 'contract_id' => $input['contract_id'] ?? null,
            'coordination_adoption_operation_id' => $input['coordination_adoption_operation_id'] ?? null] as $key => $id) {
            if (! is_string($id) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1 || ! Str::isUlid($id)) {
                throw new ContractConsiderationValidationFailed($key.' must be a canonical ULID.');
            }
        }

        return [$input['contract_id'], $input['coordination_adoption_operation_id']];
    }

    public function lockContract(string $tenantId, string $contractId, User $actor): object
    {
        $this->authorization->lock($tenantId, $actor);
        $contract = DB::table('contracts')->where('tenant_id', $tenantId)->where('id', $contractId)->lockForUpdate()->first();
        if ($contract === null || $contract->currency !== 'SAR' || BigDecimal::of((string) $contract->total_amount)->isLessThanOrEqualTo(0)) {
            throw new ContractConsiderationConflict('Adoption requires an existing tenant Contract with positive SAR capacity.');
        }

        return $contract;
    }

    /** Resolve historical identity before checking clean eligibility for a new adoption. */
    public function resolve(string $tenantId, object $contract, string $operationId): ?array
    {
        $byOperation = DB::table('contract_consideration_positions')->where('tenant_id', $tenantId)
            ->where('coordination_adoption_operation_id', $operationId)->first();
        if ($byOperation !== null && $byOperation->contract_id !== $contract->id) {
            throw new ContractConsiderationConflict('Adoption operation belongs to a different Contract.');
        }
        $position = DB::table('contract_consideration_positions')->where('tenant_id', $tenantId)
            ->where('contract_id', $contract->id)->first();
        if ($position === null) {
            $this->assertClean($tenantId, (string) $contract->id);

            return null;
        }
        if ($position->coordination_adoption_operation_id !== $operationId) {
            throw new ContractConsiderationConflict('Contract was adopted by a different operation.');
        }
        $genesis = DB::table('contract_consideration_lots')->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)->where('lot_kind', 'GENESIS')->get();
        if ($position->status !== 'ADOPTED' || $position->adoption_basis !== self::BASIS
            || $position->coordination_scope_version !== self::SCOPE || $position->currency !== 'SAR'
            || ! BigDecimal::of((string) $position->consideration_amount)->isEqualTo((string) $contract->total_amount)
            || $genesis->count() !== 1) {
            throw new ContractConsiderationIntegrityFailed('Adoption historical facts or genesis are inconsistent.');
        }
        $lot = $genesis->first();
        if ($lot->contract_id !== $contract->id || $lot->transition_id !== null || $lot->currency !== 'SAR'
            || $lot->semantic_position !== 'UNPERFORMED_UNBILLED'
            || ! BigDecimal::of((string) $lot->amount)->isEqualTo((string) $position->consideration_amount)) {
            throw new ContractConsiderationIntegrityFailed('Adoption genesis is inconsistent.');
        }
        // Existing sources may legitimately appear after adoption, but never without coordination.
        foreach (['contractual_billing_entitlements' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
            'unit_handover_acceptances' => 'UNIT_HANDOVER_ACCEPTANCE'] as $table => $type) {
            $missing = DB::table($table.' as source')->where('source.tenant_id', $tenantId)
                ->where('source.contract_id', $contract->id)->where('source.status', 'effective')
                ->whereNotExists(function ($query) use ($position, $type): void {
                    $query->selectRaw('1')->from('contract_consideration_transitions as transition')
                        ->whereColumn('transition.tenant_id', 'source.tenant_id')->whereColumn('transition.source_id', 'source.id')
                        ->where('transition.position_id', $position->id)->where('transition.source_type', $type)
                        ->where('transition.status', 'effective');
                })->exists();
            if ($missing) {
                throw new ContractConsiderationIntegrityFailed('Adopted Contract has uncoordinated supported-source history.');
            }
        }

        $this->history->replay($tenantId, $position);

        return ['status' => 'committed', 'position_id' => (string) $position->id, 'genesis_lot_id' => (string) $lot->id];
    }

    public function assertClean(string $tenantId, string $contractId): void
    {
        foreach (['contractual_billing_entitlements', 'unit_handover_acceptances'] as $table) {
            if (DB::table($table)->where('tenant_id', $tenantId)->where('contract_id', $contractId)->where('status', 'effective')->exists()) {
                throw new ContractConsiderationConflict('Clean adoption rejects prior effective supported sources.');
            }
        }
    }
}
