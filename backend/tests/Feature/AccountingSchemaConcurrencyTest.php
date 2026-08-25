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

    /** @return array{string,int} */
    private function context(): array
    {
        $tenant=Tenant::factory()->create();
        $user=User::factory()->create(['role'=>'accountant','status'=>'active']);
        TenantUser::factory()->forTenant($tenant)->forUser($user)->active()->create();
        DB::table('accounting_settings')->insert(['id'=>(string) Str::ulid(),'tenant_id'=>$tenant->id,'ledger_currency'=>'SAR','activated_by'=>$user->id,'activated_at'=>now()]);
        return [(string) $tenant->id,(int) $user->id];
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
