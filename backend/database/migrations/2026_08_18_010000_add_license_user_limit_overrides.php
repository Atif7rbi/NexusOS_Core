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
        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->unsignedInteger('users_limit_override')
                ->nullable()
                ->after('grace_ends_at');
        });

        DB::statement("
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_users_limit_override_check
            CHECK (users_limit_override IS NULL OR users_limit_override > 0)
        ");
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE tenant_licenses DROP CONSTRAINT IF EXISTS tenant_licenses_users_limit_override_check'
        );

        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->dropColumn('users_limit_override');
        });
    }
};
