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
            Schema::table('leads', function (Blueprint $table): void {
                $table->string('next_action_type', 30)
                    ->nullable()
                    ->after('next_follow_up_at');
                $table->text('next_action_note')
                    ->nullable()
                    ->after('next_action_type');
            });

            DB::statement(<<<'SQL'
                UPDATE leads
                SET next_action_type = 'waiting_response'
                WHERE next_follow_up_at IS NOT NULL
                  AND next_action_type IS NULL
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE leads
                ADD CONSTRAINT leads_next_action_type_check
                CHECK (
                    next_action_type IS NULL
                    OR next_action_type IN (
                        'call',
                        'whatsapp',
                        'meeting',
                        'site_visit',
                        'send_offer',
                        'waiting_response',
                        'other'
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE leads
                ADD CONSTRAINT leads_follow_up_state_check
                CHECK (
                    (
                        next_follow_up_at IS NULL
                        AND next_action_type IS NULL
                        AND next_action_note IS NULL
                    )
                    OR
                    (
                        next_follow_up_at IS NOT NULL
                        AND next_action_type IS NOT NULL
                    )
                )
            SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE leads
                ADD CONSTRAINT leads_next_action_note_check
                CHECK (
                    (
                        next_action_type = 'other'
                        AND next_action_note IS NOT NULL
                        AND btrim(next_action_note) <> ''
                    )
                    OR
                    (
                        next_action_type IS NOT NULL
                        AND next_action_type <> 'other'
                        AND (
                            next_action_note IS NULL
                            OR btrim(next_action_note) <> ''
                        )
                    )
                    OR
                    (
                        next_action_type IS NULL
                        AND next_action_note IS NULL
                    )
                )
            SQL);
        }, 1);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement(
                'ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_next_action_note_check'
            );
            DB::statement(
                'ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_follow_up_state_check'
            );
            DB::statement(
                'ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_next_action_type_check'
            );

            Schema::table('leads', function (Blueprint $table): void {
                $table->dropColumn([
                    'next_action_type',
                    'next_action_note',
                ]);
            });
        }, 1);
    }
};
