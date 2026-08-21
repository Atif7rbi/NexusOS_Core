<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name', 255);
            $table->string('slug', 120)->unique();

            $table->string('status', 20)
                ->default('active');

            $table->string('timezone', 64)
                ->default('Asia/Riyadh');

            $table->string('locale', 10)
                ->default('ar-SA');

            $table->string('currency', 3)
                ->default('SAR');

            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("
            ALTER TABLE tenants
            ADD CONSTRAINT tenants_status_check
            CHECK (
                status IN (
                    'invited',
                    'active',
                    'paused',
                    'suspended',
                    'removed'
                )
            )
        ");

        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 20)
                ->default('invited');

            $table->timestampTz('invited_at')
                ->nullable();

            $table->timestampTz('joined_at')
                ->nullable();

            $table->timestampTz('removed_at')
                ->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'user_id'],
                'tenant_users_tenant_user_unique'
            );

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("
            ALTER TABLE tenant_users
            ADD CONSTRAINT tenant_users_status_check
            CHECK (
                status IN (
                    'invited',
                    'active',
                    'paused',
                    'suspended',
                    'removed'
                )
            )
        ");

        $tenantId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'NexusOS Default Tenant',
            'slug' => 'nexusos-default',
            'status' => 'active',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar-SA',
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $users = DB::table('users')
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            if ($user->role === 'system_owner') {
                continue;
            }

            $membershipStatus = match ($user->status) {
                'suspended' => 'suspended',
                'archived' => 'removed',
                default => 'active',
            };

            DB::table('tenant_users')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'status' => $membershipStatus,
                'invited_at' => null,
                'joined_at' => $membershipStatus === 'active'
                    ? ($user->created_at ?? now())
                    : null,
                'removed_at' => $membershipStatus === 'removed'
                    ? now()
                    : null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE tenant_users
             DROP CONSTRAINT IF EXISTS tenant_users_status_check'
        );

        DB::statement(
            'ALTER TABLE tenants
             DROP CONSTRAINT IF EXISTS tenants_status_check'
        );

        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
