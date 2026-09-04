<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Entitlement Receivable deferred guard scoping requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS receivables_entitlement_final_state
              ON public.receivables;

            CREATE OR REPLACE FUNCTION public.receivable_has_entitlement_link(
              p_tenant_id text,
              p_receivable_id text
            ) RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
              SELECT EXISTS (
                SELECT 1
                FROM public.entitlement_receivable_links link
                WHERE link.tenant_id = p_tenant_id
                  AND link.receivable_id = p_receivable_id
              )
            $$;

            CREATE CONSTRAINT TRIGGER receivables_entitlement_final_state
            AFTER UPDATE
            ON public.receivables
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            WHEN (
              public.receivable_has_entitlement_link(
                NEW.tenant_id,
                NEW.id
              )
            )
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            REVOKE EXECUTE ON FUNCTION public.receivable_has_entitlement_link(text,text)
              FROM PUBLIC;
            SQL);

        $this->revokeRuntimeHelperExecution();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS receivables_entitlement_final_state
              ON public.receivables;

            DROP FUNCTION IF EXISTS public.receivable_has_entitlement_link(text,text);

            CREATE CONSTRAINT TRIGGER receivables_entitlement_final_state
            AFTER UPDATE
            ON public.receivables
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();
            SQL);
    }

    private function revokeRuntimeHelperExecution(): void
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (
            ! is_string($runtimeRole)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)
        ) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE EXECUTE ON FUNCTION public.receivable_has_entitlement_link(text,text) FROM {$identifier}",
        );
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema = DB::selectOne(
            'SELECT current_schema() AS name',
        )->name;

        if ($schema === 'public') {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException(
            'Entitlement Receivable deferred guard scoping requires the public PostgreSQL schema.',
        );
    }
};
