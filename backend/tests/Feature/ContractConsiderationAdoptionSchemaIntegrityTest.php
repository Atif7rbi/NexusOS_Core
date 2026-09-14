<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ContractConsiderationAdoptionSchemaIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    public function test_direct_sql_adoption_without_position_and_genesis_fails_at_commit(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        try {
            DB::transaction(function () use ($tenantId, $actor, $contractId): void {
                $now = now();

                DB::table('contract_consideration_adoptions')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'coordination_adoption_operation_id' => (string) Str::ulid(),
                    'consideration_amount' => '450000.00',
                    'currency' => 'SAR',
                    'adoption_basis' => 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES',
                    'coordination_scope_version' => 'CONTRACT_CONSIDERATION_V1',
                    'status' => 'ADOPTED',
                    'adopted_by' => $actor->id,
                    'adopted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            self::fail('Expected deferred adoption final-state guard to reject partial state.');
        } catch (QueryException|PDOException $exception) {
            self::assertSame(
                '23514',
                (string) ($exception->errorInfo[0] ?? $exception->getCode() ?? ''),
            );
        }
    }

    public function test_direct_sql_mismatched_genesis_amount_fails_at_commit(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        try {
            DB::transaction(function () use ($tenantId, $actor, $contractId): void {
                $now = now();
                $adoptionId = (string) Str::ulid();
                $positionId = (string) Str::ulid();

                DB::table('contract_consideration_adoptions')->insert([
                    'id' => $adoptionId,
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'coordination_adoption_operation_id' => (string) Str::ulid(),
                    'consideration_amount' => '450000.00',
                    'currency' => 'SAR',
                    'adoption_basis' => 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES',
                    'coordination_scope_version' => 'CONTRACT_CONSIDERATION_V1',
                    'status' => 'ADOPTED',
                    'adopted_by' => $actor->id,
                    'adopted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('contract_consideration_positions')->insert([
                    'id' => $positionId,
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'adoption_id' => $adoptionId,
                    'consideration_amount' => '450000.00',
                    'currency' => 'SAR',
                    'source_contract_total_snapshot' => '450000.00',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('contract_consideration_lots')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'position_root_id' => $positionId,
                    'lot_kind' => 'GENESIS',
                    'amount' => '449999.00',
                    'currency' => 'SAR',
                    'semantic_position' => 'UNPERFORMED_UNBILLED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            self::fail('Expected deferred final-state guard to reject mismatched genesis capacity.');
        } catch (QueryException|PDOException $exception) {
            self::assertSame(
                '23514',
                (string) ($exception->errorInfo[0] ?? $exception->getCode() ?? ''),
            );
        }
    }

    public function test_adoption_position_and_genesis_are_immutable(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        app(AdoptContractConsideration::class)->execute(
            $tenantId,
            $contractId,
            $actor,
            ['coordination_adoption_operation_id' => (string) Str::ulid()],
        );

        foreach (
            [
                'contract_consideration_adoptions',
                'contract_consideration_positions',
                'contract_consideration_lots',
            ] as $table
        ) {
            try {
                DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('contract_id', $contractId)
                    ->update(['updated_at' => now()]);

                self::fail("Expected {$table} canonical history mutation to fail.");
            } catch (QueryException $exception) {
                self::assertSame('55000', (string) ($exception->errorInfo[0] ?? ''));
            }
        }
    }

    public function test_genesis_uniqueness_is_database_backed(): void
    {
        [$tenantId, $actor, $contractId] = $this->context();

        app(AdoptContractConsideration::class)->execute(
            $tenantId,
            $contractId,
            $actor,
            ['coordination_adoption_operation_id' => (string) Str::ulid()],
        );

        $position = DB::table('contract_consideration_positions')
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->firstOrFail();

        try {
            DB::table('contract_consideration_lots')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'contract_id' => $contractId,
                'position_root_id' => $position->id,
                'lot_kind' => 'GENESIS',
                'amount' => '450000.00',
                'currency' => 'SAR',
                'semantic_position' => 'UNPERFORMED_UNBILLED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            self::fail('Expected unique genesis constraint to reject a second genesis lot.');
        } catch (QueryException $exception) {
            self::assertSame('23505', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    /** @return array{string,User,string} */
    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'sold');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            (string) $unit->id,
            (string) $customer->id,
            $actor->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            (string) $reservation->id,
            $actor->id,
            'active',
            ['total_amount' => '450000.00', 'currency' => 'SAR'],
        );

        return [$tenantId, $actor, (string) $contract->id];
    }
}
