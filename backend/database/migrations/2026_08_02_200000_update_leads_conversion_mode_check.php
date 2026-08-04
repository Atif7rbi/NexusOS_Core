<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Milestone B — Lead → Customer Conversion
 *
 * Updates leads_conversion_mode_check to reflect the renamed and extended
 * ConversionMode enum values introduced in Milestone B.
 *
 * Before: 'created' | 'linked' | 'linked_and_promoted'
 * After:  'created' | 'linked_existing' | 'linked_and_promoted'
 *
 * The old value 'linked' is retired. All new conversion records will use
 * 'linked_existing' or 'linked_and_promoted'. Existing rows are unaffected
 * because the Milestone A codebase never produced conversion_mode values
 * (the won state was only reachable via the fixture in tests, not through
 * the API).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_conversion_mode_check');

        DB::statement("
            ALTER TABLE leads
            ADD CONSTRAINT leads_conversion_mode_check
            CHECK (
                conversion_mode IS NULL
                OR conversion_mode IN ('created', 'linked_existing', 'linked_and_promoted')
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_conversion_mode_check');

        // Atomically rename any linked_existing values back to linked before
        // the old constraint (which only accepts 'linked') is re-added.
        // This prevents the constraint from being violated by existing rows.
        DB::statement("
            UPDATE leads
            SET conversion_mode = 'linked'
            WHERE conversion_mode = 'linked_existing'
        ");

        DB::statement("
            ALTER TABLE leads
            ADD CONSTRAINT leads_conversion_mode_check
            CHECK (
                conversion_mode IS NULL
                OR conversion_mode IN ('created', 'linked', 'linked_and_promoted')
            )
        ");
    }
};
