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
            SET CONSTRAINTS receivables_entitlement_final_state IMMEDIATE;

            DROP TRIGGER IF EXISTS receivables_entitlement_final_state
              ON public.receivables;

            DROP FUNCTION IF EXISTS public.receivable_has_entitlement_link(text,text);

            CREATE OR REPLACE FUNCTION public.check_entitlement_receivable_link_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              link_row public.entitlement_receivable_links%ROWTYPE;
            BEGIN
              IF TG_TABLE_NAME = 'entitlement_receivable_links' THEN
                PERFORM public.validate_entitlement_receivable_link_state(
                  NEW.tenant_id,
                  NEW.entitlement_id,
                  NEW.receivable_id
                );

                RETURN NULL;
              END IF;

              IF TG_TABLE_NAME = 'contractual_billing_entitlements' THEN
                FOR link_row IN
                  SELECT *
                  FROM public.entitlement_receivable_links
                  WHERE tenant_id = NEW.tenant_id
                    AND entitlement_id = NEW.id
                LOOP
                  PERFORM public.validate_entitlement_receivable_link_state(
                    link_row.tenant_id,
                    link_row.entitlement_id,
                    link_row.receivable_id
                  );
                END LOOP;

                RETURN NULL;
              END IF;

              IF TG_TABLE_NAME = 'receivables' THEN
                IF NOT EXISTS (
                  SELECT 1
                  FROM public.entitlement_receivable_links
                  WHERE tenant_id = NEW.tenant_id
                    AND receivable_id = NEW.id
                ) THEN
                  RETURN NULL;
                END IF;

                FOR link_row IN
                  SELECT *
                  FROM public.entitlement_receivable_links
                  WHERE tenant_id = NEW.tenant_id
                    AND receivable_id = NEW.id
                LOOP
                  PERFORM public.validate_entitlement_receivable_link_state(
                    link_row.tenant_id,
                    link_row.entitlement_id,
                    link_row.receivable_id
                  );
                END LOOP;

                RETURN NULL;
              END IF;

              IF TG_TABLE_NAME = 'contractual_billing_schedules' THEN
                FOR link_row IN
                  SELECT link.*
                  FROM public.entitlement_receivable_links link
                  JOIN public.contractual_billing_entitlements entitlement
                    ON (entitlement.tenant_id,entitlement.id)
                     = (link.tenant_id,link.entitlement_id)
                  WHERE entitlement.tenant_id = NEW.tenant_id
                    AND entitlement.schedule_id = NEW.id
                  ORDER BY link.entitlement_id, link.id
                LOOP
                  PERFORM public.validate_entitlement_receivable_link_state(
                    link_row.tenant_id,
                    link_row.entitlement_id,
                    link_row.receivable_id
                  );
                END LOOP;

                RETURN NULL;
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER receivables_entitlement_final_state
            AFTER UPDATE
            ON public.receivables
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            REVOKE EXECUTE ON FUNCTION public.check_entitlement_receivable_link_final_state()
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
            SET CONSTRAINTS receivables_entitlement_final_state IMMEDIATE;

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

        $this->revokeRuntimeHelperExecution('public.receivable_has_entitlement_link(text,text)');
    }

    private function revokeRuntimeHelperExecution(
        string $function = 'public.check_entitlement_receivable_link_final_state()',
    ): void {
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
            "REVOKE EXECUTE ON FUNCTION {$function} FROM {$identifier}",
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
