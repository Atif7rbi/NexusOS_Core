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
        $convertedCount = (int) DB::table('reservations')
            ->where('status', 'converted')
            ->count();

        if ($convertedCount > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$convertedCount} reservation(s) have status "
                ."'converted', which is not permitted by the previous check "
                .'constraint. Resolve or migrate these records to a valid prior '
                ."status before rolling back this migration. No data was changed."
            );
        }

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_status_check');
        DB::statement(
            "ALTER TABLE reservations
             ADD CONSTRAINT reservations_status_check
             CHECK (status IN ('active', 'cancelled', 'expired'))"
        );
    }
};
