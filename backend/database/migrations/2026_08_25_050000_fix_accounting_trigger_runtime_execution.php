<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Accounting runtime trigger remediation requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            ALTER FUNCTION public.validate_opening_balance_final_state()
              SECURITY DEFINER
              SET search_path = pg_catalog, public;

            ALTER FUNCTION public.schedule_opening_balance_validation()
              SECURITY DEFINER
              SET search_path = pg_catalog, public;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER FUNCTION public.validate_opening_balance_final_state()
              SECURITY INVOKER
              SET search_path = pg_catalog, public;

            ALTER FUNCTION public.schedule_opening_balance_validation()
              SECURITY INVOKER
              SET search_path = pg_catalog, public;
            SQL);
    }
};
