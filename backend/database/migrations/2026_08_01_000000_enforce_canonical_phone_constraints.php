<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    private const PATTERN = '^05[0-9]{8}$';
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('Canonical phone constraints require PostgreSQL.');
        DB::transaction(function (): void {
            $this->preflight();
            DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_phone_canonical_check CHECK (phone ~ '^05[0-9]{8}$')");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_phone_canonical_check CHECK (phone IS NULL OR phone ~ '^05[0-9]{8}$')");
            DB::statement("ALTER TABLE system_settings ADD CONSTRAINT system_settings_phone_canonical_check CHECK (phone IS NULL OR phone ~ '^05[0-9]{8}$')");
        }, 1);
    }
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') throw new RuntimeException('Canonical phone constraints require PostgreSQL.');
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE system_settings DROP CONSTRAINT IF EXISTS system_settings_phone_canonical_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_phone_canonical_check');
            DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_phone_canonical_check');
        }, 1);
    }
    private function preflight(): void
    {
        $customerNulls = DB::table('customers')->whereNull('phone')->count();
        $customerInvalid = DB::table('customers')->whereRaw('phone !~ ?', [self::PATTERN])->count();
        $userInvalid = DB::table('users')->whereNotNull('phone')->whereRaw('phone !~ ?', [self::PATTERN])->count();
        $settingsInvalid = DB::table('system_settings')->whereNotNull('phone')->whereRaw('phone !~ ?', [self::PATTERN])->count();
        $collisions = (int) DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate FROM (
              SELECT tenant_id, canonical_phone FROM (
                SELECT tenant_id, translate(btrim(phone, E'\t\n\v\f\r '),
                  '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹','01234567890123456789') AS canonical_phone
                FROM customers
              ) n WHERE canonical_phone ~ '^05[0-9]{8}$'
              GROUP BY tenant_id, canonical_phone HAVING COUNT(*) > 1
            ) c
        SQL)->aggregate;
        if ($customerNulls || $customerInvalid || $userInvalid || $settingsInvalid || $collisions) {
            throw new RuntimeException(sprintf(
                'Cannot install canonical phone constraints: customers_null=%d, customers_noncanonical=%d, users_noncanonical=%d, system_settings_noncanonical=%d, customer_collision_groups=%d.',
                $customerNulls, $customerInvalid, $userInvalid, $settingsInvalid, $collisions
            ));
        }
    }
};
