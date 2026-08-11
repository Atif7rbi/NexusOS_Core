<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->ulid('tenant_id')->nullable()->after('id');
        });

        $tenantIds = Tenant::query()->orderBy('id')->pluck('id')->all();
        $existing = DB::table('system_settings')->orderBy('id')->first();

        if ($existing !== null && $tenantIds !== []) {
            $attributes = (array) $existing;
            unset($attributes['id'], $attributes['tenant_id']);

            foreach ($tenantIds as $index => $tenantId) {
                if ($index === 0) {
                    DB::table('system_settings')
                        ->where('id', $existing->id)
                        ->update(['tenant_id' => $tenantId]);

                    continue;
                }

                DB::table('system_settings')->insert([
                    ...$attributes,
                    'tenant_id' => $tenantId,
                ]);
            }
        } elseif ($existing !== null) {
            DB::table('system_settings')->delete();
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->ulid('tenant_id')->nullable(false)->change();
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->unique('tenant_id', 'system_settings_tenant_unique');
        });
    }

    public function down(): void
    {
        $firstId = DB::table('system_settings')->orderBy('id')->value('id');

        if ($firstId !== null) {
            DB::table('system_settings')
                ->where('id', '!=', $firstId)
                ->delete();
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropUnique('system_settings_tenant_unique');
            $table->dropColumn('tenant_id');
        });
    }
};
