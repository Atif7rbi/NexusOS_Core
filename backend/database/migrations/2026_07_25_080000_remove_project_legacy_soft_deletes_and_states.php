<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FINAL_STATUSES = [
        'draft',
        'active',
        'completed',
        'cancelled',
    ];

    public function up(): void
    {
        if (DB::table('projects')
            ->whereNotIn('status', self::FINAL_STATUSES)
            ->exists()) {
            throw new \RuntimeException(
                'Cannot remove legacy project states while non-final statuses exist.'
            );
        }

        if (DB::table('projects')->whereNull('tenant_id')->exists()) {
            throw new \RuntimeException(
                'Cannot remove legacy project fields while NULL tenant_id values exist.'
            );
        }

        if (DB::table('projects')
            ->whereNotNull('deleted_at')
            ->whereNull('archived_at')
            ->exists()) {
            throw new \RuntimeException(
                'Cannot remove deleted_at while unarchived soft-deleted projects exist.'
            );
        }

        DB::statement(
            'ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check'
        );

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_status_check
            CHECK (status IN ('draft', 'active', 'completed', 'cancelled'))
        ");

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestampTz('deleted_at')->nullable();
            $table->index('deleted_at');
        });

        DB::statement(
            'ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check'
        );

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_status_check
            CHECK (status IN (
                'draft',
                'planning',
                'active',
                'completed',
                'archived',
                'cancelled'
            ))
        ");
    }
};
