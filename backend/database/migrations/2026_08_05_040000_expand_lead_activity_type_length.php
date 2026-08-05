<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE lead_activities ALTER COLUMN type TYPE VARCHAR(30)'
        );
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM lead_activities
                    WHERE char_length(type) > 20
                ) THEN
                    RAISE EXCEPTION
                        'Cannot reduce lead_activities.type to VARCHAR(20) while longer activity types exist.';
                END IF;
            END
            $$
        SQL);

        DB::statement(
            'ALTER TABLE lead_activities ALTER COLUMN type TYPE VARCHAR(20)'
        );
    }
};
