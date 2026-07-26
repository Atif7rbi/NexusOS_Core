<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check'
        );

        DB::statement(
            "ALTER TABLE units
             ADD CONSTRAINT units_status_check
             CHECK (status IN ('available', 'reserved', 'sold'))"
        );
    }

    public function down(): void
    {
        $reservedCount = DB::table('units')
            ->where('status', 'reserved')
            ->count();

        if ($reservedCount > 0) {
            throw new RuntimeException(
                'Cannot rollback units status constraint while reserved units exist.'
            );
        }

        DB::statement(
            'ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check'
        );

        DB::statement(
            "ALTER TABLE units
             ADD CONSTRAINT units_status_check
             CHECK (status IN ('available', 'sold'))"
        );
    }
};
