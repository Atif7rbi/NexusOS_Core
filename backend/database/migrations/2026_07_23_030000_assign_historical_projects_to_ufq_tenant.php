<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Phase 2.6 explicit business ownership assignment.
         *
         * Historical single-tenant ownership assignment. The explicit IDs
         * and project numbers are retained for migration compatibility and
         * are not inferred from Units, Reservations, or tenant count.
         */
        $tenantId = '01KXPBAATGZQBH4JRVETAH5SX1';

        $projects = [
            '01kxkhx6y03ge39wahs7cg32j8' => 'PRJ-2026-001',
            '01kxkj755fk2a8ww6k23yk578j' => 'PRJ-2026-002',
            '01kxkvpxwz0drmc3sy9e0a0wsv' => 'PRJ-2026-003',
            '01kxkx9k9b6snmard931m307zm' => 'PRJ-2026-004',
            '01ky1exp9w0m5hnmwsztpb7zsp' => 'PRJ-2026-005',
        ];

        foreach ($projects as $projectId => $projectNumber) {
            DB::table('projects')
                ->where('id', $projectId)
                ->where('project_number', $projectNumber)
                ->whereNull('tenant_id')
                ->update([
                    'tenant_id' => $tenantId,
                ]);
        }
    }

    public function down(): void
    {
        // A business ownership assignment is not reversed automatically.
    }
};
