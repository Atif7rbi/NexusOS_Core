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

        try {
            $this->period($tenant, $actor, '2026-12-31', '2027-12-31');
            $this->fail('Inclusive overlap was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
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
        });
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

    private function account(string $tenant, int $actor, string $code, string $type='asset', string $class='current_asset'): string
    {
        $id=(string) Str::ulid();
        DB::table('accounts')->insert(['id'=>$id,'tenant_id'=>$tenant,'code'=>$code,'name'=>'Account '.$code,'kind'=>'posting','account_type'=>$type,'classification'=>$class,'status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
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
}
