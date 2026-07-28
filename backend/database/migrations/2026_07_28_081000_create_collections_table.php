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
        Schema::create('collections', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('tenant_id');
            $table->ulid('contract_id');

            $table->unsignedInteger('sequence');
            $table->string('title', 150);
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft');

            $table->timestampTz('scheduled_at')->nullable();
            $table->unsignedBigInteger('scheduled_by')->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('tenant_id', 'collections_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign(
                ['tenant_id', 'contract_id'],
                'collections_tenant_contract_foreign'
            )
                ->references(['tenant_id', 'id'])
                ->on('contracts')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign('created_by', 'collections_created_by_foreign')
                ->references('id')
                ->on('users')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign('updated_by', 'collections_updated_by_foreign')
                ->references('id')
                ->on('users')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign('scheduled_by', 'collections_scheduled_by_foreign')
                ->references('id')
                ->on('users')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign('cancelled_by', 'collections_cancelled_by_foreign')
                ->references('id')
                ->on('users')
                ->onUpdate('restrict')
                ->onDelete('restrict');
        });

        DB::statement(
            'ALTER TABLE collections
             ADD CONSTRAINT collections_sequence_positive_check
             CHECK (sequence > 0)'
        );

        DB::statement(
            'ALTER TABLE collections
             ADD CONSTRAINT collections_amount_positive_check
             CHECK (amount > 0)'
        );

        DB::statement(
            "ALTER TABLE collections
             ADD CONSTRAINT collections_title_not_blank_check
             CHECK (btrim(title) <> '')"
        );

        DB::statement(
            "ALTER TABLE collections
             ADD CONSTRAINT collections_status_check
             CHECK (status IN ('draft', 'scheduled', 'cancelled'))"
        );

        DB::statement(
            'ALTER TABLE collections
             ADD CONSTRAINT collections_scheduling_actor_pair_check
             CHECK (
                (scheduled_at IS NULL AND scheduled_by IS NULL)
                OR
                (scheduled_at IS NOT NULL AND scheduled_by IS NOT NULL)
             )'
        );

        DB::statement(
            'ALTER TABLE collections
             ADD CONSTRAINT collections_cancellation_actor_pair_check
             CHECK (
                (cancelled_at IS NULL AND cancelled_by IS NULL)
                OR
                (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
             )'
        );

        DB::statement(
            "ALTER TABLE collections
             ADD CONSTRAINT collections_lifecycle_fields_check
             CHECK (
                (
                    status = 'draft'
                    AND scheduled_at IS NULL
                    AND scheduled_by IS NULL
                    AND cancelled_at IS NULL
                    AND cancelled_by IS NULL
                    AND cancellation_reason IS NULL
                )
                OR
                (
                    status = 'scheduled'
                    AND scheduled_at IS NOT NULL
                    AND scheduled_by IS NOT NULL
                    AND cancelled_at IS NULL
                    AND cancelled_by IS NULL
                    AND cancellation_reason IS NULL
                )
                OR
                (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                    AND cancelled_by IS NOT NULL
                    AND cancellation_reason IS NOT NULL
                    AND btrim(cancellation_reason) <> ''
                )
             )"
        );

        DB::statement(
            "CREATE UNIQUE INDEX collections_active_sequence_unique
             ON collections (tenant_id, contract_id, sequence)
             WHERE status <> 'cancelled'"
        );

        DB::statement(
            'CREATE INDEX collections_tenant_contract_status_due_date_index
             ON collections (tenant_id, contract_id, status, due_date)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
