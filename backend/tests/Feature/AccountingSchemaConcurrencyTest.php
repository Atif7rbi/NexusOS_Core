<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingSchemaConcurrencyTest extends TestCase
{
    public function test_overlapping_period_race_serializes_and_allows_one_winner(): void
    {
        [$tenant,$actor]=$this->context();
        $results=$this->workers([
            ['action'=>'period','id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'actor_id'=>$actor,'start'=>'2026-01-01','end'=>'2026-06-30'],
            ['action'=>'period','id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'actor_id'=>$actor,'start'=>'2026-06-30','end'=>'2026-12-31'],
        ]);
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])));
        $this->assertSame(1, DB::table('accounting_periods')->where('tenant_id',$tenant)->count());
    }

    public function test_number_allocation_race_returns_distinct_monotonic_values(): void
    {
        [$tenant]=$this->context();
        $results=$this->workers([
            ['action'=>'number','tenant_id'=>$tenant],
            ['action'=>'number','tenant_id'=>$tenant],
        ]);
        $this->assertTrue($results[0]['ok']); $this->assertTrue($results[1]['ok']);
        $values=[$results[0]['value'],$results[1]['value']]; sort($values);
        $this->assertSame([1,2],$values);
    }

    public function test_activation_and_currency_change_race_cannot_split_currency_truth(): void
    {
        $tenant=Tenant::factory()->create(['currency'=>'SAR']);
        $user=User::factory()->create(['role'=>'accountant','status'=>'active']);
        TenantUser::factory()->forTenant($tenant)->forUser($user)->active()->create();
        $results=$this->workers([
            ['action'=>'activate','id'=>(string) Str::ulid(),'tenant_id'=>(string) $tenant->id,'actor_id'=>(int) $user->id],
            ['action'=>'currency','tenant_id'=>(string) $tenant->id],
        ]);
        $this->assertSame(1,count(array_filter($results,fn ($r)=>$r['ok'])));
        $active=DB::table('accounting_settings')->where('tenant_id',$tenant->id)->exists();
        $this->assertSame($active ? 'SAR' : 'USD',DB::table('tenants')->where('id',$tenant->id)->value('currency'));
    }

    public function test_opening_balance_slot_race_allows_one_draft(): void
    {
        [$tenant,$actor]=$this->context();
        $results=$this->workers(array_map(fn () => ['action'=>'opening','operation_id'=>(string) Str::ulid(),'journal_id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'actor_id'=>$actor],[1,2]));
        $this->assertSame(1,count(array_filter($results,fn ($r)=>$r['ok'])));
        $this->assertSame(1,DB::table('opening_balance_operations')->where('tenant_id',$tenant)->count());
    }

    public function test_opposite_parent_updates_cannot_create_account_cycle(): void
    {
        [$tenant,$actor]=$this->context();
        $first=$this->group($tenant,$actor,'G1'); $second=$this->group($tenant,$actor,'G2');
        $results=$this->workers([
            ['action'=>'account_parent','tenant_id'=>$tenant,'actor_id'=>$actor,'id'=>$first,'parent_id'=>$second],
            ['action'=>'account_parent','tenant_id'=>$tenant,'actor_id'=>$actor,'id'=>$second,'parent_id'=>$first],
        ]);
        $this->assertSame(1,count(array_filter($results,fn ($r)=>$r['ok'])));
    }

    public function test_normal_postings_do_not_wait_for_settings_coordination_lock(): void
    {
        [$tenant,$actor]=$this->context();
        $firstPeriod=$this->period($tenant,$actor,'2026-01-01','2026-06-30');
        $secondPeriod=$this->period($tenant,$actor,'2026-07-01','2026-12-31');
        [$debit,$credit]=$this->accounts($tenant,$actor,'A');
        $first=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-03-01');
        $second=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-09-01');
        $results=$this->workers([
            ['action'=>'lock_settings','tenant_id'=>$tenant,'hold_ms'=>1500],
            $this->postPayload($tenant,$actor,$first,$firstPeriod,101)+['pre_delay_ms'=>150,'lock_timeout_ms'=>700],
            $this->postPayload($tenant,$actor,$second,$secondPeriod,102)+['pre_delay_ms'=>150,'lock_timeout_ms'=>700],
        ]);
        $this->assertTrue($results[0]['ok']); $this->assertTrue($results[1]['ok']); $this->assertTrue($results[2]['ok']);
    }

    public function test_posting_vs_period_close_leaves_one_authoritative_state(): void
    {
        [$tenant,$actor]=$this->context(); $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        [$debit,$credit]=$this->accounts($tenant,$actor,'B'); $journal=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-04-01');
        $results=$this->workers([
            $this->postPayload($tenant,$actor,$journal,$period,110),
            ['action'=>'close_period','tenant_id'=>$tenant,'actor_id'=>$actor,'period_id'=>$period],
        ]);
        $this->assertTrue($results[1]['ok']);
        $this->assertSame('closed',DB::table('accounting_periods')->where('id',$period)->value('status'));
        $this->assertSame($results[0]['ok'] ? 'posted' : 'draft',DB::table('journal_entries')->where('id',$journal)->value('status'));
    }

    public function test_posting_vs_account_archive_leaves_posted_account_active(): void
    {
        [$tenant,$actor]=$this->context(); $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        [$debit,$credit]=$this->accounts($tenant,$actor,'C'); $journal=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-04-01');
        $results=$this->workers([
            $this->postPayload($tenant,$actor,$journal,$period,120),
            ['action'=>'archive_account','tenant_id'=>$tenant,'actor_id'=>$actor,'account_id'=>$debit],
        ]);
        $this->assertTrue($results[1]['ok']);
        $this->assertSame('archived',DB::table('accounts')->where('id',$debit)->value('status'));
        $this->assertSame($results[0]['ok'] ? 'posted' : 'draft',DB::table('journal_entries')->where('id',$journal)->value('status'));
    }

    public function test_structural_mutation_vs_first_descendant_posting_preserves_history_truth(): void
    {
        [$tenant,$actor]=$this->context(); $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        $group=$this->group($tenant,$actor,'D-GROUP'); [$debit,$credit]=$this->accounts($tenant,$actor,'D',$group);
        $journal=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-04-01');
        $results=$this->workers([
            $this->postPayload($tenant,$actor,$journal,$period,130),
            ['action'=>'account_code','tenant_id'=>$tenant,'actor_id'=>$actor,'account_id'=>$group,'actor_id'=>$actor,'code'=>'D-GROUP-NEW'],
        ]);
        $this->assertTrue($results[0]['ok']);
        if ($results[1]['ok']) {
            $this->assertSame('D-GROUP-NEW',DB::table('accounts')->where('id',$group)->value('code'));
        } else {
            $this->assertSame('D-GROUP',DB::table('accounts')->where('id',$group)->value('code'));
        }
    }

    public function test_same_reversal_target_race_allows_one_posted_reversal(): void
    {
        [$tenant,$actor]=$this->context(); $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        [$debit,$credit]=$this->accounts($tenant,$actor,'E'); $target=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-04-01');
        $this->postDirect($target,$period,$actor,140);
        $results=$this->workers([
            $this->reversalPayload($tenant,$actor,$period,$target,141),
            $this->reversalPayload($tenant,$actor,$period,$target,142),
        ]);
        $this->assertSame(1,count(array_filter($results,fn ($r)=>$r['ok'])));
        $this->assertSame(1,DB::table('journal_entries')->where('tenant_id',$tenant)->where('reverses_journal_entry_id',$target)->where('status','posted')->count());
    }

    public function test_opening_reactivation_vs_new_slot_race_has_one_effective_owner(): void
    {
        [$tenant,$actor]=$this->context(); $period=$this->period($tenant,$actor,'2026-01-01','2026-12-31');
        [$debit,$credit]=$this->accounts($tenant,$actor,'F');
        [$operation,$root]=$this->postedOpening($tenant,$actor,$period,$debit,$credit,150);
        $neutralizerPayload=$this->reversalPayload($tenant,$actor,$period,$root,151)+['operation_id'=>$operation,'effect_state'=>'neutralized'];
        $neutralizerResult=$this->workers([$neutralizerPayload])[0]; $this->assertTrue($neutralizerResult['ok']);
        $neutralizer=$neutralizerPayload['journal_id'];
        $reactivation=$this->reversalPayload($tenant,$actor,$period,$neutralizer,152)+['operation_id'=>$operation,'effect_state'=>'effective'];
        $results=$this->workers([
            $reactivation,
            ['action'=>'opening','operation_id'=>(string) Str::ulid(),'journal_id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'actor_id'=>$actor,'opening_date'=>'2026-06-01'],
        ]);
        $this->assertSame(1,count(array_filter($results,fn ($r)=>$r['ok'])));
        $this->assertSame(1,DB::table('opening_balance_operations')->where('tenant_id',$tenant)->where(function ($query): void { $query->where('status','draft')->orWhere(fn ($nested)=>$nested->where('status','posted')->where('effect_state','effective')); })->count());
    }

    /** @return array{string,int} */
    private function context(): array
    {
        $tenant=Tenant::factory()->create();
        $user=User::factory()->create(['role'=>'accountant','status'=>'active']);
        TenantUser::factory()->forTenant($tenant)->forUser($user)->active()->create();
        DB::table('accounting_settings')->insert(['id'=>(string) Str::ulid(),'tenant_id'=>$tenant->id,'ledger_currency'=>'SAR','activated_by'=>$user->id,'activated_at'=>now()]);
        return [(string) $tenant->id,(int) $user->id];
    }

    private function group(string $tenant,int $actor,string $code): string
    {
        $id=(string) Str::ulid();
        DB::table('accounts')->insert(['id'=>$id,'tenant_id'=>$tenant,'code'=>$code,'name'=>$code,'kind'=>'group','account_type'=>'asset','status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    private function period(string $tenant,int $actor,string $start,string $end): string
    {
        $id=(string) Str::ulid(); DB::table('accounting_periods')->insert(['id'=>$id,'tenant_id'=>$tenant,'start_date'=>$start,'end_date'=>$end,'status'=>'open','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]); return $id;
    }

    /** @return array{string,string} */
    private function accounts(string $tenant,int $actor,string $prefix,?string $parent=null): array
    {
        $debit=(string) Str::ulid(); $credit=(string) Str::ulid();
        DB::table('accounts')->insert([
            ['id'=>$debit,'tenant_id'=>$tenant,'code'=>$prefix.'100','name'=>'Debit','kind'=>'posting','account_type'=>'asset','classification'=>'current_asset','parent_id'=>$parent,'status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>$credit,'tenant_id'=>$tenant,'code'=>$prefix.'300','name'=>'Credit','kind'=>'posting','account_type'=>'equity','classification'=>'equity','parent_id'=>null,'status'=>'active','created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()],
        ]); return [$debit,$credit];
    }

    private function draftJournal(string $tenant,int $actor,string $debit,string $credit,string $date,string $origin='manual',?string $sourceId=null,?string $target=null): string
    {
        $id=(string) Str::ulid();
        DB::table('journal_entries')->insert(['id'=>$id,'tenant_id'=>$tenant,'entry_date'=>$date,'description'=>'Concurrent journal','status'=>'draft','origin'=>$origin,'source_type'=>$origin==='manual'?null:($origin==='opening_balance'?'opening_balance_operation':'journal_entry'),'source_id'=>$sourceId,'reverses_journal_entry_id'=>$target,'reversal_reason'=>$origin==='reversal'?'Correction':null,'created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('journal_lines')->insert([
            ['id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'journal_entry_id'=>$id,'line_number'=>1,'account_id'=>$debit,'debit'=>'10.00','credit'=>'0.00','created_at'=>now(),'updated_at'=>now()],
            ['id'=>(string) Str::ulid(),'tenant_id'=>$tenant,'journal_entry_id'=>$id,'line_number'=>2,'account_id'=>$credit,'debit'=>'0.00','credit'=>'10.00','created_at'=>now(),'updated_at'=>now()],
        ]); return $id;
    }

    /** @return array<string,mixed> */
    private function postPayload(string $tenant,int $actor,string $journal,string $period,int $sequence): array
    {
        return ['action'=>'post','tenant_id'=>$tenant,'actor_id'=>$actor,'journal_id'=>$journal,'period_id'=>$period,'sequence'=>$sequence,'number'=>'JRN-2026-'.str_pad((string) $sequence,3,'0',STR_PAD_LEFT)];
    }

    private function postDirect(string $journal,string $period,int $actor,int $sequence): void
    {
        DB::table('journal_entries')->where('id',$journal)->update(['status'=>'posted','accounting_period_id'=>$period,'journal_number'=>'JRN-2026-'.str_pad((string) $sequence,3,'0',STR_PAD_LEFT),'journal_number_year'=>2026,'journal_sequence_number'=>$sequence,'posted_by'=>$actor,'posted_at'=>now(),'updated_by'=>$actor,'updated_at'=>now()]);
    }

    /** @return array<string,mixed> */
    private function reversalPayload(string $tenant,int $actor,string $period,string $target,int $sequence): array
    {
        return ['action'=>'reverse','journal_id'=>(string) Str::ulid(),'line_ids'=>[1=>(string) Str::ulid(),2=>(string) Str::ulid()],'tenant_id'=>$tenant,'actor_id'=>$actor,'target_id'=>$target,'entry_date'=>'2026-06-01','period_id'=>$period,'sequence'=>$sequence,'number'=>'JRN-2026-'.str_pad((string) $sequence,3,'0',STR_PAD_LEFT)];
    }

    /** @return array{string,string} */
    private function postedOpening(string $tenant,int $actor,string $period,string $debit,string $credit,int $sequence): array
    {
        return DB::transaction(function () use ($tenant,$actor,$period,$debit,$credit,$sequence): array {
            $operation=(string) Str::ulid(); $journal=$this->draftJournal($tenant,$actor,$debit,$credit,'2026-01-01','opening_balance',$operation); $postedAt=now();
            DB::table('opening_balance_operations')->insert(['id'=>$operation,'tenant_id'=>$tenant,'status'=>'draft','accounting_date'=>'2026-01-01','journal_entry_id'=>$journal,'created_by'=>$actor,'updated_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            $this->postDirect($journal,$period,$actor,$sequence);
            DB::table('opening_balance_operations')->where('id',$operation)->update(['status'=>'posted','effect_state'=>'effective','latest_effect_journal_entry_id'=>$journal,'posted_by'=>$actor,'posted_at'=>$postedAt,'effect_updated_by'=>$actor,'effect_updated_at'=>DB::table('journal_entries')->where('id',$journal)->value('posted_at'),'updated_by'=>$actor,'updated_at'=>$postedAt]);
            return [$operation,$journal];
        });
    }

    /** @param array<int,array<string,mixed>> $payloads @return array<int,array<string,mixed>> */
    private function workers(array $payloads): array
    {
        $config=config('database.connections.'.DB::getDefaultConnection());
        $dsn=sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'],$config['port'],$config['database']);
        $running=[];
        foreach ($payloads as $payload) {
            $payload+=['dsn'=>$dsn,'username'=>$config['username'],'password'=>$config['password'],'schema'=>'public'];
            $process=proc_open([PHP_BINARY,base_path('tests/Support/accounting_schema_worker.php'),base64_encode(json_encode($payload,JSON_THROW_ON_ERROR))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $this->assertIsResource($process); fclose($pipes[0]); $running[]=[$process,$pipes];
        }
        $results=[];
        foreach ($running as [$process,$pipes]) {
            $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $code=proc_close($process); $this->assertSame(0,$code,$stderr); $results[]=json_decode($stdout,true,flags:JSON_THROW_ON_ERROR);
        }
        return $results;
    }
}
