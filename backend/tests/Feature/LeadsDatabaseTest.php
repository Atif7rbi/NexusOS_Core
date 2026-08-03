<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Exceptions\LeadActivityIsAppendOnlyException;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesLeadFixtures;

final class LeadsDatabaseTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    public function test_all_lead_check_constraints_are_present_and_validated(): void
    {
        $expected = [
            'leads_archive_reason_check',
            'leads_archive_reason_detail_check',
            'leads_archive_state_check',
            'leads_conversion_mode_check',
            'leads_lost_reason_check',
            'leads_lost_reason_detail_check',
            'leads_lost_state_check',
            'leads_phone_canonical_check',
            'leads_source_check',
            'leads_source_detail_check',
            'leads_stage_check',
            'leads_unit_requires_project_check',
            'leads_won_state_check',
        ];

        $constraints = collect(DB::select(<<<'SQL'
            SELECT conname, convalidated
            FROM pg_constraint
            WHERE conrelid = 'leads'::regclass
              AND contype = 'c'
            ORDER BY conname
        SQL));

        $this->assertSame($expected, $constraints->pluck('conname')->all());
        $this->assertTrue($constraints->every(
            static fn (object $constraint): bool => $constraint->convalidated,
        ));
    }

    public function test_phone_and_all_frozen_enum_constraints_reject_invalid_values(): void
    {
        [$lead, $tenant, $actor] = $this->leadContext();

        $this->assertLeadUpdateFails($lead, ['phone' => '+966501234567']);
        $this->assertLeadUpdateFails($lead, ['stage' => 'invalid']);
        $this->assertLeadUpdateFails($lead, ['source' => 'invalid']);
        $this->assertLeadUpdateFails($lead, ['lost_reason' => 'invalid']);
        $this->assertLeadUpdateFails($lead, [
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => 'invalid',
        ]);

        $customer = $this->createLeadCustomer($tenant, $actor);
        $this->assertLeadUpdateFails($lead, [
            'stage' => 'won',
            'customer_id' => $customer->id,
            'converted_at' => now(),
            'converted_by' => $actor->id,
            'conversion_mode' => 'invalid',
            'next_follow_up_at' => null,
        ]);
    }

    public function test_source_detail_invariant_is_enforced_by_postgresql(): void
    {
        [$lead] = $this->leadContext();

        $this->assertLeadUpdateFails($lead, [
            'source' => 'other',
            'source_detail' => null,
        ]);
        $this->assertLeadUpdateFails($lead, [
            'source' => 'other',
            'source_detail' => '   ',
        ]);
        $this->assertLeadUpdateFails($lead, [
            'source' => 'website',
            'source_detail' => 'not allowed',
        ]);
    }

    public function test_lost_state_and_reason_detail_invariants_are_enforced(): void
    {
        [$lead, , $actor] = $this->leadContext();

        $this->assertLeadUpdateFails($lead, ['stage' => 'lost']);
        $this->assertLeadUpdateFails($lead, [
            'stage' => 'lost',
            'lost_reason' => 'other',
            'lost_reason_detail' => null,
            'lost_at' => now(),
            'lost_by' => $actor->id,
            'next_follow_up_at' => null,
        ]);
        $this->assertLeadUpdateFails($lead, [
            'stage' => 'lost',
            'lost_reason' => 'not_ready',
            'lost_reason_detail' => 'not allowed',
            'lost_at' => now(),
            'lost_by' => $actor->id,
            'next_follow_up_at' => null,
        ]);
        $this->assertLeadUpdateFails($lead, [
            'lost_reason' => 'not_ready',
            'lost_at' => now(),
            'lost_by' => $actor->id,
        ]);
    }

    public function test_won_state_invariant_is_enforced(): void
    {
        [$lead, $tenant, $actor] = $this->leadContext();

        $this->assertLeadUpdateFails($lead, ['stage' => 'won']);

        $customer = $this->createLeadCustomer($tenant, $actor);
        $this->assertLeadUpdateFails($lead, [
            'customer_id' => $customer->id,
            'converted_at' => now(),
            'converted_by' => $actor->id,
            'conversion_mode' => 'linked',
        ]);
    }

    public function test_archive_state_and_reason_detail_invariants_are_enforced(): void
    {
        [$lead, , $actor] = $this->leadContext();

        $this->assertLeadUpdateFails($lead, ['archived_at' => now()]);
        $this->assertLeadUpdateFails($lead, [
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => 'other',
            'archive_reason_detail' => null,
        ]);
        $this->assertLeadUpdateFails($lead, [
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => 'duplicate',
            'archive_reason_detail' => 'not allowed',
        ]);
        $this->assertLeadUpdateFails($lead, [
            'archived_by' => $actor->id,
            'archive_reason' => 'duplicate',
        ]);
    }

    public function test_unit_requires_project_constraint_is_enforced(): void
    {
        [$lead, $tenant, $actor] = $this->leadContext();
        $project = $this->createLeadProject($tenant, $actor);
        $unit = $this->createLeadUnit($tenant, $project, $actor);

        $this->assertLeadUpdateFails($lead, [
            'project_id' => null,
            'unit_id' => $unit->id,
        ]);
    }

    public function test_activity_type_and_body_payload_contract_are_enforced(): void
    {
        [$lead, , $actor] = $this->leadContext();

        $this->assertActivityInsertFails($lead, $actor, [
            'type' => 'invalid',
            'body' => null,
            'payload' => ['schema_version' => 1],
        ]);
        $this->assertActivityInsertFails($lead, $actor, [
            'type' => 'note',
            'body' => '   ',
            'payload' => null,
        ]);
        $this->assertActivityInsertFails($lead, $actor, [
            'type' => 'note',
            'body' => 'A note',
            'payload' => ['not' => 'allowed'],
        ]);
        $this->assertActivityInsertFails($lead, $actor, [
            'type' => 'stage_change',
            'body' => 'not allowed',
            'payload' => ['schema_version' => 1],
        ]);
        $this->assertActivityInsertFails($lead, $actor, [
            'type' => 'assignment',
            'body' => null,
            'payload' => null,
        ]);
    }

    public function test_required_indexes_are_present(): void
    {
        $indexes = collect(DB::select(<<<'SQL'
            SELECT indexname
            FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename IN ('leads', 'lead_activities')
        SQL))->pluck('indexname');

        foreach ([
            'leads_tenant_phone_index',
            'leads_tenant_assigned_to_index',
            'leads_tenant_stage_index',
            'leads_tenant_created_at_index',
            'leads_tenant_project_id_index',
            'leads_archived_at_index',
            'leads_overdue_follow_up_index',
            'lead_activities_lead_occurred_at_index',
            'lead_activities_tenant_id_index',
        ] as $index) {
            $this->assertTrue($indexes->contains($index), "Missing index: {$index}");
        }
    }

    public function test_lead_and_activity_foreign_keys_use_the_frozen_delete_actions(): void
    {
        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT conrelid::regclass::text AS table_name, conname, confdeltype
            FROM pg_constraint
            WHERE conrelid IN ('leads'::regclass, 'lead_activities'::regclass)
              AND contype = 'f'
            ORDER BY table_name, conname
        SQL));

        $this->assertCount(14, $foreignKeys);
        $updatedByForeignKey = $foreignKeys->first(
            static fn (object $foreignKey): bool => $foreignKey->table_name === 'leads'
                && $foreignKey->conname === 'leads_updated_by_foreign',
        );

        $this->assertNotNull($updatedByForeignKey);
        $this->assertSame('n', $updatedByForeignKey->confdeltype);

        $restrictForeignKeys = $foreignKeys->reject(
            static fn (object $foreignKey): bool => $foreignKey->table_name === 'leads'
                && $foreignKey->conname === 'leads_updated_by_foreign',
        );

        $this->assertCount(13, $restrictForeignKeys);
        $this->assertTrue($restrictForeignKeys->every(
            static fn (object $foreignKey): bool => $foreignKey->confdeltype === 'r',
        ));
    }

    public function test_foreign_keys_prevent_deleting_a_lead_with_activities(): void
    {
        [$lead, , $actor] = $this->leadContext();
        $this->createLeadActivity($lead, $actor);

        try {
            DB::transaction(static function () use ($lead): void {
                DB::table('leads')->where('id', $lead->id)->delete();
            });
            $this->fail('Expected ON DELETE RESTRICT to protect Lead Activities.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_lead_activities_are_append_only_at_the_model_boundary(): void
    {
        [$lead, , $actor] = $this->leadContext();
        $activity = $this->createLeadActivity($lead, $actor);

        try {
            $activity->forceFill(['body' => 'Changed'])->save();
            $this->fail('Expected Lead Activity update to be rejected.');
        } catch (LeadActivityIsAppendOnlyException) {
            $this->addToAssertionCount(1);
        }

        try {
            $activity->delete();
            $this->fail('Expected Lead Activity deletion to be rejected.');
        } catch (LeadActivityIsAppendOnlyException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('lead_activities', [
            'id' => $activity->id,
            'body' => 'CRM note',
        ]);
    }

    public function test_migrations_roll_back_and_run_again_in_dependency_order(): void
    {
        $activitiesMigration = require database_path(
            'migrations/2026_08_02_100001_create_lead_activities_table.php'
        );
        $leadsMigration = require database_path(
            'migrations/2026_08_02_100000_create_leads_table.php'
        );

        $activitiesMigration->down();
        $leadsMigration->down();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('lead_activities'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('leads'));

        $leadsMigration->up();
        $activitiesMigration->up();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('leads'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('lead_activities'));
    }

    /**
     * Milestone B — verifies that the new leads_conversion_mode_check constraint
     * (applied by 2026_08_02_200000_update_leads_conversion_mode_check.php)
     * accepts the three valid values and rejects any other string.
     */
    public function test_conversion_mode_check_accepts_valid_values_and_rejects_invalid(): void
    {
        [$lead, $tenant, $actor] = $this->leadContext();

        $customer = $this->createLeadCustomer($tenant, $actor);
        $wonBase  = [
            'stage'        => 'won',
            'customer_id'  => $customer->id,
            'converted_at' => now(),
            'converted_by' => $actor->id,
        ];

        // --- accepted values ---

        foreach (['created', 'linked_existing', 'linked_and_promoted'] as $mode) {
            DB::table('leads')->where('id', $lead->id)->update(
                array_merge($wonBase, ['conversion_mode' => $mode])
            );
            $this->assertSame(
                $mode,
                DB::table('leads')->where('id', $lead->id)->value('conversion_mode'),
                "Expected conversion_mode '{$mode}' to be accepted by the constraint."
            );

            // Reset back to open stage for next iteration
            DB::table('leads')->where('id', $lead->id)->update([
                'stage'           => 'new',
                'customer_id'     => null,
                'converted_at'    => null,
                'converted_by'    => null,
                'conversion_mode' => null,
            ]);
        }

        // --- rejected values ---

        foreach (['linked', 'CREATED', 'other', ''] as $invalid) {
            $this->assertLeadUpdateFails(
                $lead,
                array_merge($wonBase, ['conversion_mode' => $invalid]),
            );
        }
    }

    /**
     * @return array{0: Lead, 1: \App\Models\Tenant, 2: User}
     */
    private function leadContext(): array
    {
        $tenant = $this->createLeadTenant();
        $actor = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR)['user'];

        return [$this->createLead($tenant, $actor), $tenant, $actor];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function assertLeadUpdateFails(Lead $lead, array $attributes): void
    {
        try {
            DB::transaction(static function () use ($lead, $attributes): void {
                DB::table('leads')->where('id', $lead->id)->update($attributes);
            });
            $this->fail('Expected the Lead database constraint to reject the update.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function assertActivityInsertFails(
        Lead $lead,
        User $actor,
        array $attributes,
    ): void {
        $row = array_merge([
            'id' => (string) Str::ulid(),
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'type' => ActivityType::Note->value,
            'body' => 'A note',
            'payload' => null,
            'occurred_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
        ], $attributes);

        if (is_array($row['payload'])) {
            $row['payload'] = json_encode($row['payload'], JSON_THROW_ON_ERROR);
        }

        try {
            DB::transaction(static function () use ($row): void {
                DB::table('lead_activities')->insert($row);
            });
            $this->fail('Expected the Lead Activity database constraint to reject the row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
