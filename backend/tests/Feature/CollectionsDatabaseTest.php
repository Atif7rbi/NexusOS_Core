<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Customers\Models\Customer;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class CollectionsDatabaseTest extends ApiTestCase
{
    private int $projectCount = 0;
    private int $customerCount = 0;
    private int $unitCount = 0;

    public function test_contract_currency_is_a_tenant_snapshot_and_is_immutable(): void
    {
        $context = $this->createContractContext();

        $this->assertDatabaseHas('contracts', [
            'id' => $context['contract_id'],
            'currency' => 'SAR',
        ]);

        Tenant::query()->whereKey($context['tenant_id'])->update(['currency' => 'USD']);

        $this->assertDatabaseHas('contracts', [
            'id' => $context['contract_id'],
            'currency' => 'SAR',
        ]);

        $this->expectException(QueryException::class);

        DB::table('contracts')
            ->where('id', $context['contract_id'])
            ->update(['currency' => 'USD']);
    }

    public function test_new_contract_copies_the_current_tenant_currency(): void
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->tenantIdFor($user->id);

        Tenant::query()->whereKey($tenantId)->update(['currency' => 'USD']);

        Sanctum::actingAs($user);

        $contractId = $this->createContract();

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'tenant_id' => $tenantId,
            'currency' => 'USD',
        ]);
    }

    public function test_collection_schema_uses_frozen_identifier_actor_and_timestamp_types(): void
    {
        $columns = collect(DB::select(<<<'SQL'
            SELECT column_name, data_type, character_maximum_length, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'collections'
        SQL))->keyBy('column_name');

        foreach (['id', 'tenant_id', 'contract_id'] as $column) {
            $this->assertSame('character', $columns[$column]->data_type);
            $this->assertSame(26, $columns[$column]->character_maximum_length);
            $this->assertSame('NO', $columns[$column]->is_nullable);
        }

        foreach (['created_by', 'updated_by', 'scheduled_by', 'cancelled_by'] as $column) {
            $this->assertSame('bigint', $columns[$column]->data_type);
        }

        foreach (['scheduled_at', 'cancelled_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame('timestamp with time zone', $columns[$column]->data_type);
        }

        $this->assertSame(150, $columns['title']->character_maximum_length);
        $this->assertSame(500, $columns['cancellation_reason']->character_maximum_length);
        $this->assertSame('NO', $columns['created_at']->is_nullable);
        $this->assertSame('NO', $columns['updated_at']->is_nullable);

        $contractCurrency = DB::selectOne(<<<'SQL'
            SELECT is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'contracts'
              AND column_name = 'currency'
        SQL);

        $this->assertSame('NO', $contractCurrency->is_nullable);
        $this->assertNull($contractCurrency->column_default);
    }

    public function test_collection_foreign_keys_are_restrictive_and_no_standalone_contract_key_exists(): void
    {
        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT conname, confupdtype, confdeltype
            FROM pg_constraint
            WHERE conrelid = 'collections'::regclass
              AND contype = 'f'
            ORDER BY conname
        SQL))->keyBy('conname');

        $expected = [
            'collections_cancelled_by_foreign',
            'collections_created_by_foreign',
            'collections_scheduled_by_foreign',
            'collections_tenant_contract_foreign',
            'collections_tenant_id_foreign',
            'collections_updated_by_foreign',
        ];

        $this->assertSame($expected, $foreignKeys->keys()->all());

        foreach ($foreignKeys as $foreignKey) {
            $this->assertSame('r', $foreignKey->confupdtype);
            $this->assertSame('r', $foreignKey->confdeltype);
        }
    }

    public function test_collection_check_constraints_reject_invalid_draft_values(): void
    {
        $context = $this->createContractContext();

        $this->assertCollectionInsertFails(array_merge(
            $this->draftCollection($context),
            ['title' => '   ']
        ));

        $this->assertCollectionInsertFails(array_merge(
            $this->draftCollection($context),
            ['amount' => '0.00']
        ));

        $this->assertCollectionInsertFails(array_merge(
            $this->draftCollection($context),
            ['status' => 'paid']
        ));

        $this->assertCollectionInsertFails(array_merge(
            $this->draftCollection($context),
            ['scheduled_at' => now()]
        ));
    }

    public function test_composite_contract_foreign_key_rejects_cross_tenant_collection(): void
    {
        $first = $this->createContractContext();
        $secondUser = $this->createActiveUser();
        $secondTenantId = $this->tenantIdFor($secondUser->id);

        $this->assertCollectionInsertFails(array_merge(
            $this->draftCollection($first),
            [
                'tenant_id' => $secondTenantId,
                'created_by' => $secondUser->id,
            ]
        ));
    }

    public function test_active_sequence_is_unique_and_cancelled_history_can_reuse_it(): void
    {
        $context = $this->createContractContext();

        DB::table('collections')->insert($this->scheduledCollection($context, 1));

        $this->assertCollectionInsertFails($this->scheduledCollection($context, 1));

        DB::table('collections')->where([
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'sequence' => 1,
        ])->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $context['user_id'],
            'cancellation_reason' => 'استبدال القسط ضمن اختبار المخطط',
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        DB::table('collections')->insert($this->scheduledCollection($context, 1));

        $this->assertDatabaseCount('collections', 2);
    }

    public function test_contract_currency_migration_can_roll_back_only_without_contract_records(): void
    {
        $collectionsMigration = require database_path(
            'migrations/2026_07_28_081000_create_collections_table.php'
        );
        $currencyMigration = require database_path(
            'migrations/2026_07_28_080000_prepare_contract_currency_for_collections.php'
        );
        $baselineContractCount = DB::table('contracts')->count();
        $originalSchema = (string) DB::selectOne(
            'SELECT current_schema() AS name'
        )->name;
        $isolatedSchema = 'collections_migration_'.strtolower((string) Str::ulid());

        DB::statement("CREATE SCHEMA {$isolatedSchema}");
        DB::selectOne(
            "SELECT set_config('search_path', ?, false)",
            [$isolatedSchema],
        );

        try {
            DB::statement(<<<'SQL'
                CREATE TABLE tenants (
                    id CHAR(26) PRIMARY KEY,
                    currency VARCHAR(3) NOT NULL
                )
            SQL);
            DB::statement(<<<'SQL'
                CREATE TABLE users (
                    id BIGINT PRIMARY KEY
                )
            SQL);
            DB::statement(<<<'SQL'
                CREATE TABLE contracts (
                    id CHAR(26) PRIMARY KEY,
                    tenant_id CHAR(26) NOT NULL
                )
            SQL);

            $currencyMigration->up();
            $collectionsMigration->up();

            $tenantId = (string) Str::ulid();
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'currency' => 'SAR',
            ]);
            DB::table('contracts')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'currency' => 'SAR',
            ]);

            $collectionsMigration->down();

            try {
                $currencyMigration->down();
                $this->fail(
                    'Expected contract records to block the currency migration rollback.'
                );
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Cannot safely roll back contract currency preparation while '
                    .'contracts exist. Use a forward-fix migration instead.',
                    $exception->getMessage(),
                );
            }

            DB::table('contracts')->delete();
            $currencyMigration->down();

            $this->assertFalse(
                collect(DB::select(<<<'SQL'
                    SELECT column_name
                    FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'contracts'
                      AND column_name = 'currency'
                SQL))->isNotEmpty()
            );

            $currencyMigration->up();
            $collectionsMigration->up();

            $this->assertSame('NO', DB::selectOne(<<<'SQL'
                SELECT is_nullable
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'contracts'
                  AND column_name = 'currency'
            SQL)->is_nullable);
        } finally {
            DB::selectOne(
                "SELECT set_config('search_path', ?, false)",
                [$originalSchema],
            );
            DB::statement("DROP SCHEMA IF EXISTS {$isolatedSchema} CASCADE");
        }

        $this->assertSame(
            $baselineContractCount,
            DB::table('contracts')->count(),
        );
    }

    /**
     * @return array{tenant_id: string, contract_id: string, user_id: int}
     */
    private function createContractContext(): array
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);

        Sanctum::actingAs($user);

        return [
            'tenant_id' => $this->tenantIdFor($user->id),
            'contract_id' => $this->createContract(),
            'user_id' => $user->id,
        ];
    }

    /**
     * @param array{tenant_id: string, contract_id: string, user_id: int} $context
     * @return array<string, mixed>
     */
    private function draftCollection(array $context): array
    {
        return [
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'sequence' => 1,
            'title' => 'القسط الأول',
            'amount' => '100.00',
            'due_date' => '2026-08-01',
            'notes' => null,
            'status' => 'draft',
            'scheduled_at' => null,
            'scheduled_by' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'created_by' => $context['user_id'],
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array{tenant_id: string, contract_id: string, user_id: int} $context
     * @return array<string, mixed>
     */
    private function scheduledCollection(array $context, int $sequence): array
    {
        return array_merge($this->draftCollection($context), [
            'sequence' => $sequence,
            'status' => 'scheduled',
            'scheduled_at' => now(),
            'scheduled_by' => $context['user_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $collection
     */
    private function assertCollectionInsertFails(array $collection): void
    {
        try {
            DB::transaction(function () use ($collection): void {
                DB::table('collections')->insert($collection);
            });

            $this->fail('Expected the collection database constraint to reject the row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createContract(): string
    {
        $userId = (int) auth()->id();
        $contract = new Contract([
            'tenant_id' => $this->tenantIdFor($userId),
            'reservation_id' => $this->createReservation(),
            'status' => 'draft',
            'total_amount' => '450000.00',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $contract->currency = Tenant::query()->findOrFail($contract->tenant_id)->currency;
        $contract->save();

        return (string) $contract->id;
    }

    private function createReservation(): string
    {
        $userId = (int) auth()->id();

        return (string) Reservation::query()->create([
            'tenant_id' => $this->tenantIdFor($userId),
            'unit_id' => $this->createAvailableUnit(),
            'customer_id' => $this->createCustomer(),
            'status' => 'active',
            'reserved_at' => now(),
            'expires_at' => now()->addHours(48),
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->id;
    }

    private function createAvailableUnit(): string
    {
        $this->unitCount++;
        $projectId = $this->createProject();

        $userId = (int) auth()->id();

        return (string) Unit::query()->create([
            'tenant_id' => $this->tenantIdFor($userId),
            'project_id' => $projectId,
            'unit_number' => "COL-{$this->unitCount}",
            'unit_type' => 'apartment',
            'status' => 'reserved',
            'selling_price' => 500000,
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->id;
    }

    private function createCustomer(): string
    {
        $this->customerCount++;

        $userId = (int) auth()->id();

        return (string) Customer::query()->create([
            'tenant_id' => $this->tenantIdFor($userId),
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'customer',
            'name' => "عميل التحصيل {$this->customerCount}",
            'phone' => '056000'.str_pad((string) $this->customerCount, 4, '0', STR_PAD_LEFT),
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->id;
    }

    private function createProject(): string
    {
        $this->projectCount++;

        $userId = (int) auth()->id();
        $tenantId = $this->tenantIdFor($userId);
        $year = now('Asia/Riyadh')->year;
        $sequence = (int) Project::query()
            ->where('project_number_year', $year)
            ->max('project_sequence_number') + 1;

        return (string) Project::query()->create([
            'tenant_id' => $tenantId,
            'project_number' => sprintf('COL-%d-%03d', $year, $sequence),
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => "مشروع التحصيل {$this->projectCount}",
            'project_type' => 'residential',
            'city' => 'الرياض',
            'country_code' => 'SA',
            'currency' => Tenant::query()->findOrFail($tenantId)->currency,
            'data_origin' => 'user',
            'status' => ProjectStatus::Active->value,
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->id;
    }

    private function tenantIdFor(int $userId): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $userId)
            ->value('tenant_id');
    }
}
