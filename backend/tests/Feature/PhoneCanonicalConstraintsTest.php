<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
final class PhoneCanonicalConstraintsTest extends TestCase
{
    use RefreshDatabase;
    private const NAMES = ['customers_phone_canonical_check','users_phone_canonical_check','system_settings_phone_canonical_check'];
    public function test_constraints_are_present_and_validated(): void
    {
        foreach (self::NAMES as $name) { $row=DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conname=?',[$name]); self::assertNotNull($row); self::assertTrue($row->convalidated); }
    }
    public function test_database_rejects_noncanonical_phone(): void
    {
        $this->createSystemSettingsFixture();

        $this->expectException(QueryException::class); DB::table('system_settings')->update(['phone'=>'+966501234567']);
    }
    public function test_forced_install_failure_is_atomic(): void
    {
        $m=$this->migration(); $m->down();
        DB::statement('ALTER TABLE system_settings ADD CONSTRAINT system_settings_phone_canonical_check CHECK (true)');
        try { $m->up(); self::fail('Expected failure.'); }
        catch (QueryException) { self::assertFalse($this->exists(self::NAMES[0])); self::assertFalse($this->exists(self::NAMES[1])); self::assertTrue($this->exists(self::NAMES[2])); }
        finally { DB::statement('ALTER TABLE system_settings DROP CONSTRAINT IF EXISTS system_settings_phone_canonical_check'); $m->up(); }
    }
    public function test_preflight_does_not_modify_invalid_data(): void
    {
        $this->createSystemSettingsFixture();

        $m=$this->migration(); $m->down(); DB::table('system_settings')->update(['phone'=>'+966501234567']);
        try { $m->up(); self::fail('Expected preflight failure.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('system_settings_noncanonical=1',$e->getMessage()); self::assertSame('+966501234567',DB::table('system_settings')->value('phone')); foreach(self::NAMES as $n) self::assertFalse($this->exists($n)); }
        finally { DB::table('system_settings')->update(['phone'=>null]); $m->up(); }
    }
    private function migration(): Migration { return require database_path('migrations/2026_08_01_000000_enforce_canonical_phone_constraints.php'); }
    private function exists(string $name): bool
    {
        return DB::table('pg_constraint as constraint')
            ->join('pg_class as relation', 'relation.oid', '=', 'constraint.conrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->where('constraint.conname', $name)
            ->whereRaw('namespace.nspname = current_schema()')
            ->exists();
    }
    private function createSystemSettingsFixture(): void
    {
        $tenant = Tenant::factory()->create();

        SystemSetting::forTenant((string) $tenant->id);
    }
}
