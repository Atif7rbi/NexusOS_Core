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
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('modules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name_ar', 160);
            $table->string('name_en', 160);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("
            ALTER TABLE modules
            ADD CONSTRAINT modules_status_check
            CHECK (status IN ('active', 'inactive'))
        ");

        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name_ar', 160);
            $table->string('name_en', 160);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("
            ALTER TABLE plans
            ADD CONSTRAINT plans_status_check
            CHECK (status IN ('active', 'inactive'))
        ");

        Schema::create('plan_modules', function (Blueprint $table): void {
            $table->foreignUlid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();
            $table->foreignUlid('module_id')
                ->constrained('modules')
                ->restrictOnDelete();

            $table->unique(
                ['plan_id', 'module_id'],
                'plan_modules_plan_module_unique',
            );
        });

        Schema::create('tenant_licenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $table->foreignUlid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();
            $table->string('status', 20);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['tenant_id', 'status'],
                'tenant_licenses_tenant_status_index',
            );
            $table->index(
                ['tenant_id', 'starts_at', 'ends_at'],
                'tenant_licenses_tenant_period_index',
            );
        });

        DB::statement("
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_status_check
            CHECK (status IN ('trial', 'active', 'grace', 'expired', 'cancelled'))
        ");
        DB::statement("
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_period_check
            CHECK (starts_at < ends_at)
        ");
        DB::statement("
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_grace_period_check
            CHECK (
                (status = 'grace' AND grace_ends_at IS NOT NULL AND grace_ends_at > ends_at)
                OR
                (status <> 'grace' AND grace_ends_at IS NULL)
            )
        ");
        DB::statement("
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_effective_period_exclusion
            EXCLUDE USING gist (
                tenant_id WITH =,
                tstzrange(
                    starts_at,
                    CASE
                        WHEN status = 'grace' THEN grace_ends_at
                        ELSE ends_at
                    END,
                    '[]'
                ) WITH &&
            )
            WHERE (status IN ('trial', 'active', 'grace'))
        ");

        Schema::create('tenant_modules', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $table->foreignUlid('module_id')
                ->constrained('modules')
                ->restrictOnDelete();
            $table->string('state', 20);
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'module_id'],
                'tenant_modules_tenant_module_unique',
            );
        });

        DB::statement("
            ALTER TABLE tenant_modules
            ADD CONSTRAINT tenant_modules_state_check
            CHECK (state IN ('enabled', 'disabled'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenant_licenses');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('modules');
    }
};
