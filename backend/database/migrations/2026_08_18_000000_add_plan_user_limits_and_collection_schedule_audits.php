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
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('users_limit')->nullable()->after('status');
        });

        DB::statement("
            ALTER TABLE plans
            ADD CONSTRAINT plans_users_limit_check
            CHECK (users_limit IS NULL OR users_limit > 0)
        ");

        DB::table('plans')
            ->where('key', 'business_full')
            ->update(['users_limit' => 5]);

        Schema::create('collection_schedule_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('contract_id');
            $table->string('event', 80);
            $table->foreignId('actor_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->jsonb('context')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('tenant_id', 'collection_schedule_audits_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->foreign(
                ['tenant_id', 'contract_id'],
                'collection_schedule_audits_tenant_contract_foreign',
            )
                ->references(['tenant_id', 'id'])
                ->on('contracts')
                ->onUpdate('restrict')
                ->onDelete('restrict');

            $table->index(
                ['tenant_id', 'contract_id', 'recorded_at'],
                'collection_schedule_audits_contract_time_index',
            );
            $table->index(
                ['tenant_id', 'event'],
                'collection_schedule_audits_event_index',
            );
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_collection_schedule_audit_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'collection_schedule_audits is immutable';
            END;
            $$;

            CREATE TRIGGER collection_schedule_audits_immutable_update
            BEFORE UPDATE ON collection_schedule_audits
            FOR EACH ROW
            EXECUTE PROCEDURE prevent_collection_schedule_audit_mutation();

            CREATE TRIGGER collection_schedule_audits_immutable_delete
            BEFORE DELETE ON collection_schedule_audits
            FOR EACH ROW
            EXECUTE PROCEDURE prevent_collection_schedule_audit_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS collection_schedule_audits_immutable_update
            ON collection_schedule_audits;

            DROP TRIGGER IF EXISTS collection_schedule_audits_immutable_delete
            ON collection_schedule_audits;

            DROP FUNCTION IF EXISTS prevent_collection_schedule_audit_mutation();
            SQL);

        Schema::dropIfExists('collection_schedule_audits');

        DB::statement(
            'ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_users_limit_check'
        );

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('users_limit');
        });
    }
};
