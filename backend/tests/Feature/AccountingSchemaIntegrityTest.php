<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_preflight_and_physical_types_are_exact(): void
    {
        $facts = DB::selectOne("SELECT current_setting('server_version') version, current_setting('default_transaction_isolation') isolation, current_setting('lock_timeout') lock_timeout, current_setting('statement_timeout') statement_timeout, current_database() database_name");

        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame($this->expectedTestDatabase(), $facts->database_name);
        $this->assertNotSame('', $facts->version);
        $this->assertSame('read committed', $facts->isolation);

        $amounts = DB::select("SELECT column_name,numeric_precision,numeric_scale FROM information_schema.columns WHERE table_schema='public' AND table_name='journal_lines' AND column_name IN ('debit','credit') ORDER BY column_name");
        $this->assertCount(2, $amounts);
        foreach ($amounts as $amount) {
            $this->assertSame(19, $amount->numeric_precision);
            $this->assertSame(2, $amount->numeric_scale);
        }
    }

    public function test_activation_is_sar_only_and_freezes_tenant_currency(): void
    {
        [$usdTenant, $usdActor] = $this->membership('USD');
        DB::beginTransaction();
        try {
            $this->activate($usdTenant, $usdActor);
            $this->fail('USD Tenant activation was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        [$tenant, $actor] = $this->membership('SAR');
        $this->activate($tenant, $actor);
        $this->expectException(QueryException::class);
        DB::table('tenants')->where('id', $tenant)->update(['currency' => 'USD']);
    }

    public function test_tenant_composite_keys_line_xor_and_period_overlap_are_database_enforced(): void
    {
        [$tenant, $actor] = $this->membership('SAR');
        [$otherTenant, $otherActor] = $this->membership('SAR');
        $this->activate($tenant, $actor);
        $this->activate($otherTenant, $otherActor);
        $this->period($tenant, $actor, '2026-01-01', '2026-12-31');

        DB::beginTransaction();
        try {
            $this->period($tenant, $actor, '2026-12-31', '2027-12-31');
            $this->fail('Inclusive overlap was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        $account = $this->account($tenant, $actor, '1000');
        $journal = $this->journal($tenant, $actor);

        $this->expectException(QueryException::class);
        DB::table('journal_lines')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $otherTenant,
            'journal_entry_id' => $journal, 'line_number' => 1,
            'account_id' => $account, 'debit' => '1.00', 'credit' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_direct_sql_posting_requires_contiguous_balanced_lines_and_open_period(): void
    {
        [$tenant, $actor] = $this->membership('SAR');
        $this->activate($tenant, $actor);
        $period = $this->period($tenant, $actor, '2026-01-01', '2026-12-31');
        $debit = $this->account($tenant, $actor, '1000');
        $credit = $this->account($tenant, $actor, '3000', 'equity', 'equity');
        $journal = $this->journal($tenant, $actor);
        $this->line($tenant, $journal, $debit, 1, '100.00', '0.00');
        $this->line($tenant, $journal, $credit, 2, '0.00', '100.00');

        DB::table('journal_entries')->where('id', $journal)->update([
            'status' => 'posted', 'accounting_period_id' => $period,
            'journal_number' => 'JRN-2026-001', 'journal_number_year' => 2026,
            'journal_sequence_number' => 1, 'posted_by' => $actor,
            'posted_at' => now(), 'updated_by' => $actor, 'updated_at' => now(),
        ]);

        $this->assertSame('posted', DB::table('journal_entries')->where('id', $journal)->value('status'));
        $this->expectException(QueryException::class);
        DB::table('journal_lines')->where('journal_entry_id', $journal)->delete();
    }

    public function test_system_draft_guard_is_deferred_to_transaction_commit(): void
    {
        [$tenant, $actor] = $this->membership('SAR');
        $this->activate($tenant, $actor);
        DB::table('accounting_source_types')->insert([
            'origin'=>'business', 'key'=>'payment', 'owner_module'=>'payments',
            'description'=>'Test-only registered payment source',
        ]);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($tenant, $actor): void {
            DB::table('journal_entries')->insert([
                'id'=>(string) Str::ulid(), 'tenant_id'=>$tenant, 'entry_date'=>'2026-01-01',
                'description'=>'Business draft', 'status'=>'draft', 'origin'=>'business',
                'source_type'=>'payment', 'source_id'=>(string) Str::ulid(),
                'created_by'=>$actor, 'updated_by'=>$actor, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            DB::statement('SET CONSTRAINTS journal_entries_system_draft_final_state IMMEDIATE');
        });
    }

    public function test_group_structure_is_frozen_by_posted_history_in_descendant(): void
    {
        [$tenant,$actor]=$this->membership('SAR'); $this->activate($tenant,$actor);
        $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        $group=$this->group($tenant,$actor,'G100');
        $child=$this->account($tenant,$actor,'1100','asset','current_asset',$group);
        $other=$this->account($tenant,$actor,'3100','equity','equity');
        $journal=$this->journal($tenant,$actor);
        $this->line($tenant,$journal,$child,1,'25.00','0.00');
        $this->line($tenant,$journal,$other,2,'0.00','25.00');
        $this->postJournal($journal,$period,$actor,10);

        $this->expectException(QueryException::class);
        DB::table('accounts')->where('tenant_id',$tenant)->where('id',$group)
            ->update(['code'=>'G101','updated_by'=>$actor,'updated_at'=>now()]);
    }

    public function test_group_account_type_cannot_change_while_child_exists(): void
    {
        [$tenant,$actor]=$this->membership('SAR'); $this->activate($tenant,$actor);
        $group=$this->group($tenant,$actor,'G200');
        $this->account($tenant,$actor,'2100','asset','current_asset',$group);

        $this->expectException(QueryException::class);
        DB::table('accounts')->where('tenant_id',$tenant)->where('id',$group)
            ->update(['account_type'=>'liability','updated_by'=>$actor,'updated_at'=>now()]);
    }

    public function test_empty_history_free_group_can_change_account_type(): void
    {
        [$tenant,$actor]=$this->membership('SAR'); $this->activate($tenant,$actor);
        $group=$this->group($tenant,$actor,'G300');

        DB::table('accounts')->where('tenant_id',$tenant)->where('id',$group)
            ->update(['account_type'=>'liability','updated_by'=>$actor,'updated_at'=>now()]);

        $this->assertSame('liability',DB::table('accounts')->where('id',$group)->value('account_type'));
    }

    public function test_stale_opening_projection_is_rejected_when_reversal_posts(): void
    {
        [$tenant,$actor]=$this->membership('SAR'); $this->activate($tenant,$actor);
        $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        $debit=$this->account($tenant,$actor,'1200');
        $credit=$this->account($tenant,$actor,'3200','equity','equity');
        [$operation,$root]=$this->postedOpening($tenant,$actor,$period,$debit,$credit,'2026-01-01',20);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($tenant,$actor,$period,$root): void {
            $this->postExactReversal($tenant,$actor,$period,$root,'2026-02-01',21);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_opening_reactivation_historical_floor_allows_equality_and_rejects_backdating(): void
    {
        [$tenant,$actor]=$this->membership('SAR'); $this->activate($tenant,$actor);
        $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        $debit=$this->account($tenant,$actor,'1300');
        $credit=$this->account($tenant,$actor,'3300','equity','equity');
        [$first,$firstRoot]=$this->postedOpening($tenant,$actor,$period,$debit,$credit,'2026-01-01',30);
        $firstNeutralizer=$this->postExactReversal($tenant,$actor,$period,$firstRoot,'2026-02-01',31);
        $this->projectOpening($first,$firstNeutralizer,'neutralized',$actor);

        [$second,$secondRoot]=$this->postedOpening($tenant,$actor,$period,$debit,$credit,'2026-02-01',32);
        $secondNeutralizer=$this->postExactReversal($tenant,$actor,$period,$secondRoot,'2026-03-01',33);
        $this->projectOpening($second,$secondNeutralizer,'neutralized',$actor);

        DB::beginTransaction();
        try {
            $invalid=$this->postExactReversal($tenant,$actor,$period,$firstNeutralizer,'2026-02-28',34);
            $this->projectOpening($first,$invalid,'effective',$actor);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Backdated Opening reactivation was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }

        $valid=$this->postExactReversal($tenant,$actor,$period,$firstNeutralizer,'2026-03-01',35);
        $this->projectOpening($first,$valid,'effective',$actor);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame($valid,DB::table('opening_balance_operations')->where('id',$first)->value('latest_effect_journal_entry_id'));
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_runtime_role_cannot_bypass_accounting_controls(): void
    {
        $role=(string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege(?, 'public.accounting_source_types', 'INSERT') allowed",[$role])->allowed);
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege(?, 'public.accounts', 'TRUNCATE') allowed",[$role])->allowed);
        $this->assertFalse((bool) DB::selectOne("SELECT has_function_privilege(?, 'public.enforce_journal_entry_mutation()', 'EXECUTE') allowed",[$role])->allowed);

        DB::statement('SET ROLE "'.$role.'"');
        try {
            foreach ([
                "INSERT INTO public.accounting_source_types(origin,key,owner_module,description) VALUES('business','unauthorized','test','unauthorized')",
                'TRUNCATE public.accounts',
                'ALTER TABLE public.accounts DISABLE TRIGGER accounts_hierarchy_guard',
                "CREATE OR REPLACE FUNCTION public.prevent_account_delete() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN OLD; END'",
            ] as $sql) {
                DB::beginTransaction();
                try { DB::unprepared($sql); $this->fail('Runtime role bypass was accepted.'); }
                catch (QueryException) { $this->assertTrue(true); }
                finally { DB::rollBack(); }
            }
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_runtime_role_ownership_of_any_protected_object_fails_preflight(): void
    {
        $runtimeRole=(string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        $migrationOwner=(string) DB::selectOne('SELECT current_user AS name')->name;
        $runtimeIdentifier='"'.str_replace('"','""',$runtimeRole).'"';
        $ownerIdentifier='"'.str_replace('"','""',$migrationOwner).'"';

        foreach ([
            ["ALTER SCHEMA public OWNER TO {$runtimeIdentifier}","ALTER SCHEMA public OWNER TO {$ownerIdentifier}"],
            ["ALTER TABLE public.accounts OWNER TO {$runtimeIdentifier}","ALTER TABLE public.accounts OWNER TO {$ownerIdentifier}"],
            ["ALTER FUNCTION public.enforce_journal_entry_mutation() OWNER TO {$runtimeIdentifier}","ALTER FUNCTION public.enforce_journal_entry_mutation() OWNER TO {$ownerIdentifier}"],
        ] as [$assignOwnership,$restoreOwnership]) {
            DB::unprepared($assignOwnership);
            try {
                $migration=require database_path('migrations/2026_08_25_040000_harden_accounting_runtime_privileges.php');
                $migration->up();
                $this->fail('Runtime ownership of a protected Accounting object was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('must not own',$exception->getMessage());
            } finally {
                DB::unprepared($restoreOwnership);
            }
        }
    }

    /** @return array{string,int} */
    private function membership(string $currency): array
    {
        $tenant = Tenant::factory()->create(['currency' => $currency]);
        $user = User::factory()->create(['role'=>'accountant','status'=>'active']);
        TenantUser::factory()->forTenant($tenant)->forUser($user)->active()->create();
        return [(string) $tenant->id, (int) $user->id];
    }

    private function activate(string $tenant, int $actor): void
    {
        DB::table('accounting_settings')->insert(['id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'ledger_currency'=>'SAR','activated_by'=>$actor,'activated_at'=>now()]);
    }

    private function period(string $tenant, int $actor, string $start, string $end): string
    {
        $id=(string) Str::ulid();
        DB::table('accounting_periods')->insert(['id'=>$id,'tenant_id'=>$tenant,'start_date'=>$start,'end_date'=>$end,'status'=>'open','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function account(string $tenant, int $actor, string $code, string $type='asset', string $class='current_asset', ?string $parent=null): string
    {
        $id=(string) Str::ulid();
        DB::table('accounts')->insert(['id'=>$id,'tenant_id'=>$tenant,'code'=>$code,'name'=>'Account '.$code,'kind'=>'posting','account_type'=>$type,'classification'=>$class,'parent_id'=>$parent,'status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function group(string $tenant,int $actor,string $code): string
    {
        $id=(string) Str::ulid();
        DB::table('accounts')->insert(['id'=>$id,'tenant_id'=>$tenant,'code'=>$code,'name'=>$code,'kind'=>'group','account_type'=>'asset','status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function journal(string $tenant, int $actor): string
    {
        $id=(string) Str::ulid();
        DB::table('journal_entries')->insert(['id'=>$id,'tenant_id'=>$tenant,'entry_date'=>'2026-01-01','description'=>'Manual journal','status'=>'draft','origin'=>'manual','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function line(string $tenant, string $journal, string $account, int $number, string $debit, string $credit): void
    {
        DB::table('journal_lines')->insert(['id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'journal_entry_id'=>$journal,'line_number'=>$number,'account_id'=>$account,'debit'=>$debit,'credit'=>$credit,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function postJournal(string $journal,string $period,int $actor,int $sequence): void
    {
        DB::table('journal_entries')->where('id',$journal)->update(['status'=>'posted','accounting_period_id'=>$period,'journal_number'=>'JRN-2026-'.str_pad((string) $sequence,3,'0',STR_PAD_LEFT),'journal_number_year'=>2026,'journal_sequence_number'=>$sequence,'posted_by'=>$actor,'posted_at'=>now(),'updated_by'=>$actor,'updated_at'=>now()]);
    }

    /** @return array{string,string} */
    private function postedOpening(string $tenant,int $actor,string $period,string $debit,string $credit,string $date,int $sequence): array
    {
        $operation=(string) Str::ulid(); $journal=(string) Str::ulid(); $postedAt=now();
        DB::table('journal_entries')->insert(['id'=>$journal,'tenant_id'=>$tenant,'entry_date'=>$date,'description'=>'Opening','status'=>'draft','origin'=>'opening_balance','source_type'=>'opening_balance_operation','source_id'=>$operation,'created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('opening_balance_operations')->insert(['id'=>$operation,'tenant_id'=>$tenant,'status'=>'draft','accounting_date'=>$date,'journal_entry_id'=>$journal,'created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        $this->line($tenant,$journal,$debit,1,'100.00','0.00'); $this->line($tenant,$journal,$credit,2,'0.00','100.00');
        DB::table('journal_entries')->where('id',$journal)->update(['status'=>'posted','accounting_period_id'=>$period,'journal_number'=>'JRN-2026-'.str_pad((string) $sequence,3,'0',STR_PAD_LEFT),'journal_number_year'=>2026,'journal_sequence_number'=>$sequence,'posted_by'=>$actor,'posted_at'=>$postedAt,'updated_by'=>$actor,'updated_at'=>$postedAt]);
        DB::table('opening_balance_operations')->where('id',$operation)->update(['status'=>'posted','effect_state'=>'effective','latest_effect_journal_entry_id'=>$journal,'posted_by'=>$actor,'posted_at'=>$postedAt,'effect_updated_by'=>$actor,'effect_updated_at'=>$postedAt,'updated_by'=>$actor,'updated_at'=>$postedAt]);
        return [$operation,$journal];
    }

    private function postExactReversal(string $tenant,int $actor,string $period,string $target,string $date,int $sequence): string
    {
        $journal=(string) Str::ulid();
        DB::table('journal_entries')->insert(['id'=>$journal,'tenant_id'=>$tenant,'entry_date'=>$date,'description'=>'Exact reversal','status'=>'draft','origin'=>'reversal','source_type'=>'journal_entry','source_id'=>$target,'reverses_journal_entry_id'=>$target,'reversal_reason'=>'Correction','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        foreach (DB::table('journal_lines')->where('tenant_id',$tenant)->where('journal_entry_id',$target)->orderBy('line_number')->get() as $line) {
            $this->line($tenant,$journal,(string) $line->account_id,(int) $line->line_number,(string) $line->credit,(string) $line->debit);
        }
        $this->postJournal($journal,$period,$actor,$sequence);
        return $journal;
    }

    private function projectOpening(string $operation,string $terminal,string $state,int $actor): void
    {
        $postedAt=DB::table('journal_entries')->where('id',$terminal)->value('posted_at');
        DB::table('opening_balance_operations')->where('id',$operation)->update(['effect_state'=>$state,'latest_effect_journal_entry_id'=>$terminal,'effect_updated_by'=>$actor,'effect_updated_at'=>$postedAt,'updated_by'=>$actor,'updated_at'=>now()]);
    }
}
