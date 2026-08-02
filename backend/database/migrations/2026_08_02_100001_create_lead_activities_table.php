<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('lead_activities', function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table->foreignUlid('tenant_id')
                    ->constrained('tenants')
                    ->restrictOnDelete();

                $table->foreignUlid('lead_id')
                    ->constrained('leads')
                    ->restrictOnDelete();

                $table->string('type', 20);
                $table->text('body')->nullable();
                $table->jsonb('payload')->nullable();
                $table->timestampTz('occurred_at');

                $table->foreignId('created_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestampTz('created_at');

                $table->index(
                    ['lead_id', 'occurred_at'],
                    'lead_activities_lead_occurred_at_index'
                );

                $table->index(
                    'tenant_id',
                    'lead_activities_tenant_id_index'
                );
            });

            DB::statement(<<<'SQL'
                ALTER TABLE lead_activities
                ADD CONSTRAINT lead_activities_type_check
                CHECK (type IN ('note', 'stage_change', 'assignment', 'archive', 'restore'))
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE lead_activities
                ADD CONSTRAINT lead_activities_content_check
                CHECK (
                    (
                        type = 'note'
                        AND body IS NOT NULL
                        AND btrim(body) <> ''
                        AND payload IS NULL
                    )
                    OR
                    (
                        type <> 'note'
                        AND body IS NULL
                        AND payload IS NOT NULL
                    )
                )
            SQL);
        }, 1);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::dropIfExists('lead_activities');
        }, 1);
    }
};
