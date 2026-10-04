<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Accounting\Actions\ManageCashFlowSemanticsAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountingCashFlowSchemaIntegrityTest extends AccountingApiTestCase
{
    public function test_cash_role_is_limited_to_current_asset_posting_accounts(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CR');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');

        $this->expectException(QueryException::class);
        DB::table('account_cash_roles')->insert($this->cashRole((string) $tenant->id, $revenue, $actor->id));
        self::assertNotEmpty($cash);
    }

    public function test_posted_semantic_is_immutable_at_the_database_boundary(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CM');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Cash receipt', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')], 'operating');
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);

        $this->expectException(QueryException::class);
        DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $journal)->update(['activity' => 'investing']);
    }

    public function test_deferred_final_state_rejects_semantic_for_zero_cash_posted_journal(): void
    {
        [$tenant, $actor] = $this->ready('CZ');
        [$asset, $equity] = $this->accounts($tenant, $actor, 'Z');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Non-cash', [new JournalLineData($asset, '10.00', '0'), new JournalLineData($equity, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($tenant, $actor, $journal): void {
            DB::table('journal_cash_flow_semantics')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $journal,
                'activity' => 'operating', 'semantic_operation_id' => (string) Str::ulid(),
                'assigned_by' => $actor->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS journal_cash_flow_semantic_final_state IMMEDIATE');
        });
    }

    public function test_direct_sql_cannot_mutate_a_cash_role_or_its_account_structure(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CI');
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);

        foreach ([
            static fn () => DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $cash)->update(['role' => 'cash_equivalent']),
            static fn () => DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $cash)->delete(),
            static fn () => DB::table('accounts')->where('tenant_id', $tenant->id)->where('id', $cash)->update(['classification' => 'non_current_asset']),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('Direct SQL bypassed Cash Flow structural integrity.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_cash_flow_actor_provenance_is_enforced_for_both_canonical_tables(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('AP');
        [, $otherActor] = $this->ready('AQ');
        $cashTwo = $this->account($tenant, $actor, '1010', 'asset', 'current_asset');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Draft cash journal', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        $crossJournal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Other draft cash journal', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);

        DB::table('account_cash_roles')->insert($this->cashRole((string) $tenant->id, $cash, $actor->id));
        DB::table('journal_cash_flow_semantics')->insert($this->semantic((string) $tenant->id, $journal, $actor->id));
        self::assertSame(1, DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $cash)->count());
        self::assertSame(1, DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $journal)->count());

        foreach ([
            fn () => DB::table('account_cash_roles')->insert($this->cashRole((string) $tenant->id, $cashTwo, $otherActor->id)),
            fn () => DB::table('journal_cash_flow_semantics')->insert($this->semantic((string) $tenant->id, $crossJournal, $otherActor->id)),
            fn () => DB::table('account_cash_roles')->insert($this->cashRole((string) $tenant->id, $cashTwo, 999999999)),
            fn () => DB::table('journal_cash_flow_semantics')->insert($this->semantic((string) $tenant->id, $crossJournal, 999999999)),
        ] as $write) {
            try {
                DB::transaction($write);
                self::fail('Direct SQL bypassed tenant-scoped Cash Flow actor provenance.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cashTwo, 'cash_equivalent', (string) Str::ulid(), $actor);
        $applicationJournal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Application cash journal', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')], 'operating');
        self::assertSame(1, DB::table('account_cash_roles')->where('tenant_id', $tenant->id)->where('account_id', $cashTwo)->count());
        self::assertSame($actor->id, DB::table('journal_cash_flow_semantics')->where('tenant_id', $tenant->id)->where('journal_entry_id', $applicationJournal)->value('assigned_by'));
    }

    public function test_cash_flow_audit_events_require_known_vocabulary_and_same_tenant_subjects(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CA');
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        self::assertSame(1, DB::table('accounting_audits')->where('tenant_id', $tenant->id)->where('event', 'cash_flow.cash_role_assigned')->where('subject_id', $cash)->count());

        $otherTenant = Tenant::factory()->create();
        foreach ([
            ['tenant_id' => $tenant->id, 'event' => 'cash_flow.invalid_event', 'subject_id' => $cash],
            ['tenant_id' => $otherTenant->id, 'event' => 'cash_flow.cash_role_assigned', 'subject_id' => $cash],
        ] as $facts) {
            try {
                DB::table('accounting_audits')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $facts['tenant_id'],
                    'event' => $facts['event'],
                    'subject_type' => 'account',
                    'subject_id' => $facts['subject_id'],
                    'actor_id' => $actor->id,
                    'context' => json_encode((object) []),
                    'recorded_at' => now(),
                ]);
                self::fail('Direct SQL bypassed Accounting audit integrity.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    private function cashRole(string $tenantId, string $accountId, int $actorId): array
    {
        $at = now();

        return ['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'account_id' => $accountId, 'role' => 'cash', 'assignment_operation_id' => (string) Str::ulid(), 'assigned_by' => $actorId, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at];
    }

    private function semantic(string $tenantId, string $journalId, int $actorId): array
    {
        $at = now();

        return ['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $journalId, 'activity' => 'operating', 'semantic_operation_id' => (string) Str::ulid(), 'assigned_by' => $actorId, 'assigned_at' => $at, 'created_at' => $at, 'updated_at' => $at];
    }
}
