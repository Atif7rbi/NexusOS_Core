<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_status_check');
        DB::statement(
            "ALTER TABLE reservations
             ADD CONSTRAINT reservations_status_check
             CHECK (status IN ('active', 'converted', 'cancelled', 'expired'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_status_check');
        DB::statement(
            "ALTER TABLE reservations
             ADD CONSTRAINT reservations_status_check
             CHECK (status IN ('active', 'cancelled', 'expired'))"
        );
    }
};
