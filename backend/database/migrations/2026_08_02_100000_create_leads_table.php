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
            Schema::create('leads', function (Blueprint $table): void {
                $table->ulid('id')->primary();

                $table->foreignUlid('tenant_id')
                    ->constrained('tenants')
                    ->restrictOnDelete();

                $table->string('name', 255);
                $table->string('phone', 30);
                $table->string('email', 255)->nullable();
                $table->string('source', 30);
                $table->text('source_detail')->nullable();

                $table->foreignUlid('project_id')
                    ->nullable()
                    ->constrained('projects')
                    ->restrictOnDelete();

                $table->foreignUlid('unit_id')
                    ->nullable()
                    ->constrained('units')
                    ->restrictOnDelete();

                $table->string('stage', 20)->default('new');

                $table->foreignId('assigned_to')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestampTz('next_follow_up_at')->nullable();

                $table->string('lost_reason', 30)->nullable();
                $table->text('lost_reason_detail')->nullable();
                $table->timestampTz('lost_at')->nullable();

                $table->foreignId('lost_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->foreignUlid('customer_id')
                    ->nullable()
                    ->constrained('customers')
                    ->restrictOnDelete();

                $table->timestampTz('converted_at')->nullable();

                $table->foreignId('converted_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->string('conversion_mode', 30)->nullable();
                $table->timestampTz('archived_at')->nullable();

                $table->foreignId('archived_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->string('archive_reason', 30)->nullable();
                $table->text('archive_reason_detail')->nullable();

                $table->foreignId('restored_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestampTz('restored_at')->nullable();

                $table->foreignId('created_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestampsTz();

                $table->index(
                    ['tenant_id', 'phone'],
                    'leads_tenant_phone_index'
                );

                $table->index(
                    ['tenant_id', 'assigned_to'],
                    'leads_tenant_assigned_to_index'
                );

                $table->index(
                    ['tenant_id', 'stage'],
                    'leads_tenant_stage_index'
                );

                $table->index(
                    ['tenant_id', 'created_at'],
                    'leads_tenant_created_at_index'
                );

                $table->index(
                    ['tenant_id', 'project_id'],
                    'leads_tenant_project_id_index'
                );

                $table->index('archived_at', 'leads_archived_at_index');
            });

            $this->addCheckConstraints();

            DB::statement(<<<'SQL'
                CREATE INDEX leads_overdue_follow_up_index
                ON leads (tenant_id, next_follow_up_at)
                WHERE next_follow_up_at IS NOT NULL
                  AND archived_at IS NULL
                  AND stage IN ('new', 'qualified', 'viewing', 'negotiation')
            SQL);
        }, 1);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::dropIfExists('leads');
        }, 1);
    }

    private function addCheckConstraints(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_phone_canonical_check
            CHECK (phone ~ '^05[0-9]{8}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_stage_check
            CHECK (stage IN ('new', 'qualified', 'viewing', 'negotiation', 'won', 'lost'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_source_check
            CHECK (
                source IN (
                    'whatsapp',
                    'phone_call',
                    'website',
                    'social_media',
                    'referral',
                    'walk_in',
                    'advertising',
                    'exhibition',
                    'other'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_source_detail_check
            CHECK (
                (
                    source = 'other'
                    AND source_detail IS NOT NULL
                    AND btrim(source_detail) <> ''
                )
                OR
                (
                    source <> 'other'
                    AND source_detail IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_lost_reason_check
            CHECK (
                lost_reason IS NULL
                OR lost_reason IN (
                    'price_too_high',
                    'no_suitable_unit',
                    'financing_unavailable',
                    'competitor',
                    'not_ready',
                    'unresponsive',
                    'duplicate',
                    'not_qualified',
                    'other'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_lost_state_check
            CHECK (
                (
                    stage = 'lost'
                    AND lost_reason IS NOT NULL
                    AND lost_at IS NOT NULL
                    AND lost_by IS NOT NULL
                )
                OR
                (
                    stage <> 'lost'
                    AND lost_reason IS NULL
                    AND lost_reason_detail IS NULL
                    AND lost_at IS NULL
                    AND lost_by IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_lost_reason_detail_check
            CHECK (
                (
                    lost_reason = 'other'
                    AND lost_reason_detail IS NOT NULL
                    AND btrim(lost_reason_detail) <> ''
                )
                OR
                (
                    lost_reason IS DISTINCT FROM 'other'
                    AND lost_reason_detail IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_conversion_mode_check
            CHECK (
                conversion_mode IS NULL
                OR conversion_mode IN ('created', 'linked', 'linked_and_promoted')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_won_state_check
            CHECK (
                (
                    stage = 'won'
                    AND customer_id IS NOT NULL
                    AND converted_at IS NOT NULL
                    AND converted_by IS NOT NULL
                    AND conversion_mode IS NOT NULL
                )
                OR
                (
                    stage <> 'won'
                    AND customer_id IS NULL
                    AND converted_at IS NULL
                    AND converted_by IS NULL
                    AND conversion_mode IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_archive_reason_check
            CHECK (
                archive_reason IS NULL
                OR archive_reason IN (
                    'duplicate',
                    'invalid_record',
                    'created_by_mistake',
                    'other'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_archive_state_check
            CHECK (
                (
                    archived_at IS NOT NULL
                    AND archived_by IS NOT NULL
                    AND archive_reason IS NOT NULL
                )
                OR
                (
                    archived_at IS NULL
                    AND archived_by IS NULL
                    AND archive_reason IS NULL
                    AND archive_reason_detail IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_archive_reason_detail_check
            CHECK (
                (
                    archive_reason = 'other'
                    AND archive_reason_detail IS NOT NULL
                    AND btrim(archive_reason_detail) <> ''
                )
                OR
                (
                    archive_reason IS DISTINCT FROM 'other'
                    AND archive_reason_detail IS NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE leads
            ADD CONSTRAINT leads_unit_requires_project_check
            CHECK (unit_id IS NULL OR project_id IS NOT NULL)
        SQL);
    }
};
