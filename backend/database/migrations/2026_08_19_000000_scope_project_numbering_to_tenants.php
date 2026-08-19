<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasColumn(
                'business_number_sequences',
                'tenant_id'
            )) {
                DB::statement(
                    'ALTER TABLE business_number_sequences
                     ADD COLUMN tenant_id CHAR(26) NULL'
                );
            }

            /*
             * The legacy sequence table was global.
             *
             * Rebuild its state from projects, which are the authoritative
             * source for the highest sequence already consumed by each tenant.
             */
            DB::table('business_number_sequences')->delete();

            DB::statement("
                INSERT INTO business_number_sequences (
                    tenant_id,
                    prefix,
                    year,
                    current_value,
                    created_at,
                    updated_at
                )
                SELECT
                    tenant_id,
                    'PRJ',
                    project_number_year,
                    MAX(project_sequence_number),
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                FROM projects
                GROUP BY tenant_id, project_number_year
            ");

            DB::statement(
                'ALTER TABLE business_number_sequences
                 ALTER COLUMN tenant_id SET NOT NULL'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 DROP CONSTRAINT IF EXISTS
                 business_number_sequences_prefix_year_unique'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 ADD CONSTRAINT
                 business_number_sequences_tenant_prefix_year_unique
                 UNIQUE (tenant_id, prefix, year)'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 ADD CONSTRAINT
                 business_number_sequences_tenant_id_foreign
                 FOREIGN KEY (tenant_id)
                 REFERENCES tenants(id)
                 ON UPDATE RESTRICT
                 ON DELETE RESTRICT'
            );

            DB::statement(
                'ALTER TABLE projects
                 DROP CONSTRAINT IF EXISTS projects_project_number_unique'
            );

            DB::statement(
                'ALTER TABLE projects
                 DROP CONSTRAINT IF EXISTS projects_year_sequence_unique'
            );

            DB::statement(
                'ALTER TABLE projects
                 ADD CONSTRAINT projects_tenant_project_number_unique
                 UNIQUE (tenant_id, project_number)'
            );

            DB::statement(
                'ALTER TABLE projects
                 ADD CONSTRAINT projects_tenant_year_sequence_unique
                 UNIQUE (
                    tenant_id,
                    project_number_year,
                    project_sequence_number
                 )'
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $duplicateNumbers = DB::table('projects')
                ->select('project_number')
                ->groupBy('project_number')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            $duplicateSequences = DB::table('projects')
                ->select(
                    'project_number_year',
                    'project_sequence_number'
                )
                ->groupBy(
                    'project_number_year',
                    'project_sequence_number'
                )
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateNumbers || $duplicateSequences) {
                throw new RuntimeException(
                    'Cannot restore global project numbering constraints '
                    .'while tenant-scoped duplicate project numbers exist.'
                );
            }

            DB::statement(
                'ALTER TABLE projects
                 DROP CONSTRAINT IF EXISTS
                 projects_tenant_project_number_unique'
            );

            DB::statement(
                'ALTER TABLE projects
                 DROP CONSTRAINT IF EXISTS
                 projects_tenant_year_sequence_unique'
            );

            DB::statement(
                'ALTER TABLE projects
                 ADD CONSTRAINT projects_project_number_unique
                 UNIQUE (project_number)'
            );

            DB::statement(
                'ALTER TABLE projects
                 ADD CONSTRAINT projects_year_sequence_unique
                 UNIQUE (
                    project_number_year,
                    project_sequence_number
                 )'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 DROP CONSTRAINT IF EXISTS
                 business_number_sequences_tenant_id_foreign'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 DROP CONSTRAINT IF EXISTS
                 business_number_sequences_tenant_prefix_year_unique'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 ALTER COLUMN tenant_id DROP NOT NULL'
            );

            DB::statement("
                CREATE TEMP TABLE legacy_business_number_sequences AS
                SELECT
                    prefix,
                    year,
                    MAX(current_value) AS current_value
                FROM business_number_sequences
                GROUP BY prefix, year
            ");

            DB::table('business_number_sequences')->delete();

            DB::statement("
                INSERT INTO business_number_sequences (
                    tenant_id,
                    prefix,
                    year,
                    current_value,
                    created_at,
                    updated_at
                )
                SELECT
                    NULL,
                    prefix,
                    year,
                    current_value,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                FROM legacy_business_number_sequences
            ");

            DB::statement(
                'ALTER TABLE business_number_sequences
                 DROP COLUMN tenant_id'
            );

            DB::statement(
                'ALTER TABLE business_number_sequences
                 ADD CONSTRAINT
                 business_number_sequences_prefix_year_unique
                 UNIQUE (prefix, year)'
            );
        });
    }
};
