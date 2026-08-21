<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('company_tagline_ar', 200)
                ->nullable()
                ->after('short_name_en');

            $table->string('company_tagline_en', 200)
                ->nullable()
                ->after('company_tagline_ar');
        });

        // Company identity is configured per tenant after tenant creation.
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'company_tagline_ar',
                'company_tagline_en',
            ]);
        });
    }
};
