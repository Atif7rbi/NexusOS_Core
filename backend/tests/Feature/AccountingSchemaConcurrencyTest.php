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
