<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_reservation_id_unique');

        DB::statement(
            "CREATE UNIQUE INDEX contracts_reservation_id_open_unique
             ON contracts (reservation_id)
             WHERE status IN ('draft', 'active', 'completed')"
        );
    }

    public function down(): void
    {
        $duplicateReservations = (int) DB::table('contracts')
            ->select('reservation_id')
            ->groupBy('reservation_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        if ($duplicateReservations > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$duplicateReservations} reservation(s) have "
                .'more than one contract, which is not permitted by the '
                .'previous full UNIQUE(reservation_id) constraint. Resolve or '
                .'remove the historical duplicate contracts before rolling back '
                .'this migration. No data was changed.'
            );
        }

        DB::statement('DROP INDEX contracts_reservation_id_open_unique');
        DB::statement('ALTER TABLE contracts ADD CONSTRAINT contracts_reservation_id_unique UNIQUE (reservation_id)');
    }
};
