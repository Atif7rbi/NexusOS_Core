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
        Schema::create('contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();

            $table->foreignUlid('reservation_id')
                ->constrained('reservations')
                ->restrictOnDelete();

            $table->string('status')
                ->default('draft');

            $table->decimal('total_amount', 12, 2);

            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique('reservation_id');

            $table->index(['tenant_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE contracts
             ADD CONSTRAINT contracts_status_check
             CHECK (status IN ('draft', 'active', 'completed', 'cancelled'))"
        );

        DB::statement(
            "ALTER TABLE contracts
             ADD CONSTRAINT contracts_total_amount_check
             CHECK (total_amount >= 0)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
