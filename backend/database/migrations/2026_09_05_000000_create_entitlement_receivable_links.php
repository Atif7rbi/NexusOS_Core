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
                'Entitlement Receivable Link v1 requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE public.entitlement_receivable_links (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              entitlement_id char(26) NOT NULL,
              receivable_id char(26) NOT NULL,
              receivable_establishment_operation_id char(26) NOT NULL,
              source_correction_operation_id char(26),
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT entitlement_receivable_links_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT entitlement_receivable_links_entitlement_history_unique
                UNIQUE (tenant_id,entitlement_id),

              CONSTRAINT entitlement_receivable_links_receivable_history_unique
                UNIQUE (tenant_id,receivable_id),

              CONSTRAINT entitlement_receivable_links_operation_unique
                UNIQUE (
                  tenant_id,
                  receivable_establishment_operation_id
                ),

              CONSTRAINT entitlement_receivable_links_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT entitlement_receivable_links_entitlement_foreign
                FOREIGN KEY (tenant_id,entitlement_id)
                REFERENCES public.contractual_billing_entitlements(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT entitlement_receivable_links_receivable_foreign
                FOREIGN KEY (tenant_id,receivable_id)
                REFERENCES public.receivables(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT entitlement_receivable_links_created_actor_foreign
                FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT entitlement_receivable_links_operation_ulid_check
                CHECK (
                  receivable_establishment_operation_id
                    ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT entitlement_receivable_links_source_correction_ulid_check
                CHECK (
                  source_correction_operation_id IS NULL
                  OR source_correction_operation_id
                    ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT entitlement_receivable_links_timestamp_order_check
                CHECK (updated_at >= created_at)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX entitlement_receivable_links_source_correction_index
              ON public.entitlement_receivable_links(
                tenant_id,
                source_correction_operation_id,
                entitlement_id
              )
              WHERE source_correction_operation_id IS NOT NULL
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_entitlement_receivable_link_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              source_entitlement public.contractual_billing_entitlements%ROWTYPE;
              target_receivable public.receivables%ROWTYPE;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'entitlement receivable links cannot be deleted';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.source_correction_operation_id IS NOT NULL THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'entitlement receivable link must initially be uncorrected';
                END IF;

                SELECT *
                INTO source_entitlement
                FROM public.contractual_billing_entitlements
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.entitlement_id
                FOR UPDATE;

                IF NOT FOUND
                   OR source_entitlement.status <> 'effective' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'entitlement receivable link requires an effective Entitlement';
                END IF;

                SELECT *
                INTO target_receivable
                FROM public.receivables
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.receivable_id
                FOR UPDATE;

                IF NOT FOUND
                   OR target_receivable.status <> 'recognized'
                   OR target_receivable.collection_id IS NOT NULL
                   OR target_receivable.contract_id
                        IS DISTINCT FROM source_entitlement.contract_id
                   OR target_receivable.customer_id
                        IS DISTINCT FROM source_entitlement.customer_id
                   OR target_receivable.recognized_amount
                        IS DISTINCT FROM source_entitlement.amount
                   OR target_receivable.currency
                        IS DISTINCT FROM source_entitlement.currency
                   OR target_receivable.currency <> 'SAR'
                   OR target_receivable.due_date
                        IS DISTINCT FROM source_entitlement.economic_date
                   OR target_receivable.recognition_operation_id
                        IS DISTINCT FROM NEW.receivable_establishment_operation_id THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'entitlement receivable link canonical provenance is inconsistent';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.entitlement_id,
                NEW.receivable_id,
                NEW.receivable_establishment_operation_id,
                NEW.created_by,
                NEW.created_at
              ) IS DISTINCT FROM (
                OLD.id,
                OLD.tenant_id,
                OLD.entitlement_id,
                OLD.receivable_id,
                OLD.receivable_establishment_operation_id,
                OLD.created_by,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'entitlement receivable link provenance is immutable';
              END IF;

              IF OLD.source_correction_operation_id IS NOT NULL
                 AND NEW.source_correction_operation_id
                      IS DISTINCT FROM OLD.source_correction_operation_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'entitlement receivable link correction evidence is immutable';
              END IF;

              IF OLD.source_correction_operation_id IS NULL
                 AND NEW.source_correction_operation_id IS NULL
                 AND NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'uncorrected entitlement receivable link cannot be edited';
              END IF;

              IF OLD.source_correction_operation_id IS NULL
                 AND NEW.source_correction_operation_id IS NOT NULL
                 AND NEW.updated_at < OLD.updated_at THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'entitlement receivable link correction timestamp is invalid';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER entitlement_receivable_links_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.entitlement_receivable_links
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_entitlement_receivable_link_history();


            CREATE OR REPLACE FUNCTION public.guard_entitlement_linked_receivable_cancellation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              link_row public.entitlement_receivable_links%ROWTYPE;
            BEGIN
              IF TG_OP <> 'UPDATE'
                 OR OLD.status <> 'recognized'
                 OR NEW.status <> 'cancelled' THEN
                RETURN NEW;
              END IF;

              SELECT *
              INTO link_row
              FROM public.entitlement_receivable_links
              WHERE tenant_id = NEW.tenant_id
                AND receivable_id = NEW.id;

              IF FOUND
                 AND link_row.source_correction_operation_id IS NULL THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'linked entitlement-backed Receivable requires authoritative source correction';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER receivables_entitlement_cancellation_guard
            BEFORE UPDATE OF status
            ON public.receivables
            FOR EACH ROW
            EXECUTE FUNCTION public.guard_entitlement_linked_receivable_cancellation();


            CREATE OR REPLACE FUNCTION public.guard_linked_entitlement_reversal()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              link_row public.entitlement_receivable_links%ROWTYPE;
              target_receivable public.receivables%ROWTYPE;
            BEGIN
              IF TG_OP <> 'UPDATE'
                 OR OLD.status <> 'effective'
                 OR NEW.status <> 'reversed' THEN
                RETURN NEW;
              END IF;

              SELECT *
              INTO link_row
              FROM public.entitlement_receivable_links
              WHERE tenant_id = NEW.tenant_id
                AND entitlement_id = NEW.id;

              IF NOT FOUND THEN
                RETURN NEW;
              END IF;

              SELECT *
              INTO target_receivable
              FROM public.receivables
              WHERE tenant_id = NEW.tenant_id
                AND id = link_row.receivable_id;

              IF NOT FOUND
                 OR target_receivable.status <> 'cancelled'
                 OR link_row.source_correction_operation_id IS NULL
                 OR link_row.source_correction_operation_id
                      IS DISTINCT FROM NEW.source_correction_operation_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'linked Entitlement reversal requires coherent Receivable cancellation provenance';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contractual_billing_entitlements_linked_reversal_guard
            BEFORE UPDATE OF status
            ON public.contractual_billing_entitlements
            FOR EACH ROW
            EXECUTE FUNCTION public.guard_linked_entitlement_reversal();


            CREATE OR REPLACE FUNCTION public.validate_entitlement_receivable_link_state(
              p_tenant_id char(26),
              p_entitlement_id char(26),
              p_receivable_id char(26)
            ) RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              link_row public.entitlement_receivable_links%ROWTYPE;
              entitlement_row public.contractual_billing_entitlements%ROWTYPE;
              receivable_row public.receivables%ROWTYPE;
              schedule_row public.contractual_billing_schedules%ROWTYPE;
            BEGIN
              SELECT *
              INTO link_row
              FROM public.entitlement_receivable_links
              WHERE tenant_id = p_tenant_id
                AND entitlement_id = p_entitlement_id
                AND receivable_id = p_receivable_id;

              IF NOT FOUND THEN
                RETURN;
              END IF;

              SELECT *
              INTO entitlement_row
              FROM public.contractual_billing_entitlements
              WHERE tenant_id = p_tenant_id
                AND id = p_entitlement_id;

              SELECT *
              INTO receivable_row
              FROM public.receivables
              WHERE tenant_id = p_tenant_id
                AND id = p_receivable_id;

              IF entitlement_row.id IS NULL
                 OR receivable_row.id IS NULL THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'entitlement receivable link final state is orphaned';
              END IF;

              SELECT *
              INTO schedule_row
              FROM public.contractual_billing_schedules
              WHERE tenant_id = p_tenant_id
                AND id = entitlement_row.schedule_id;

              IF schedule_row.id IS NULL THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'entitlement receivable link Schedule provenance is unavailable';
              END IF;

              IF entitlement_row.status = 'effective' THEN
                IF receivable_row.status <> 'recognized'
                   OR link_row.source_correction_operation_id IS NOT NULL THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'effective linked Entitlement requires recognized Receivable';
                END IF;

                RETURN;
              END IF;

              IF entitlement_row.status = 'reversed' THEN
                IF receivable_row.status <> 'cancelled'
                   OR link_row.source_correction_operation_id IS NULL
                   OR entitlement_row.source_correction_operation_id IS NULL
                   OR schedule_row.source_correction_operation_id IS NULL
                   OR link_row.source_correction_operation_id
                        IS DISTINCT FROM entitlement_row.source_correction_operation_id
                   OR link_row.source_correction_operation_id
                        IS DISTINCT FROM schedule_row.source_correction_operation_id
                   OR schedule_row.status NOT IN ('cancelled','superseded') THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'reversed linked Entitlement requires coherent terminal source correction';
                END IF;

                RETURN;
              END IF;

              RAISE EXCEPTION USING
                ERRCODE = '23514',
                MESSAGE = 'entitlement receivable link has unsupported Entitlement state';
            END;
            $$;


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
                  COALESCE(NEW.tenant_id, OLD.tenant_id),
                  COALESCE(NEW.entitlement_id, OLD.entitlement_id),
                  COALESCE(NEW.receivable_id, OLD.receivable_id)
                );

                RETURN NULL;
              END IF;

              IF TG_TABLE_NAME = 'contractual_billing_entitlements' THEN
                FOR link_row IN
                  SELECT *
                  FROM public.entitlement_receivable_links
                  WHERE tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id)
                    AND entitlement_id = COALESCE(NEW.id, OLD.id)
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
                FOR link_row IN
                  SELECT *
                  FROM public.entitlement_receivable_links
                  WHERE tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id)
                    AND receivable_id = COALESCE(NEW.id, OLD.id)
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
                  WHERE entitlement.tenant_id
                          = COALESCE(NEW.tenant_id, OLD.tenant_id)
                    AND entitlement.schedule_id
                          = COALESCE(NEW.id, OLD.id)
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

            CREATE CONSTRAINT TRIGGER entitlement_receivable_links_final_state
            AFTER INSERT OR UPDATE
            ON public.entitlement_receivable_links
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            CREATE CONSTRAINT TRIGGER contractual_billing_entitlements_receivable_final_state
            AFTER UPDATE
            ON public.contractual_billing_entitlements
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            CREATE CONSTRAINT TRIGGER receivables_entitlement_final_state
            AFTER UPDATE
            ON public.receivables
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            CREATE CONSTRAINT TRIGGER contractual_billing_schedules_receivable_final_state
            AFTER UPDATE
            ON public.contractual_billing_schedules
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.check_entitlement_receivable_link_final_state();

            REVOKE EXECUTE ON FUNCTION public.enforce_entitlement_receivable_link_history() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.guard_entitlement_linked_receivable_cancellation() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.guard_linked_entitlement_reversal() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.validate_entitlement_receivable_link_state(char(26),char(26),char(26)) FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.check_entitlement_receivable_link_final_state() FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS contractual_billing_schedules_receivable_final_state
              ON public.contractual_billing_schedules;
            DROP TRIGGER IF EXISTS receivables_entitlement_final_state
              ON public.receivables;
            DROP TRIGGER IF EXISTS contractual_billing_entitlements_receivable_final_state
              ON public.contractual_billing_entitlements;
            DROP TRIGGER IF EXISTS entitlement_receivable_links_final_state
              ON public.entitlement_receivable_links;
            DROP TRIGGER IF EXISTS contractual_billing_entitlements_linked_reversal_guard
              ON public.contractual_billing_entitlements;
            DROP TRIGGER IF EXISTS receivables_entitlement_cancellation_guard
              ON public.receivables;
            DROP TRIGGER IF EXISTS entitlement_receivable_links_history_guard
              ON public.entitlement_receivable_links;

            DROP FUNCTION IF EXISTS public.check_entitlement_receivable_link_final_state();
            DROP FUNCTION IF EXISTS public.validate_entitlement_receivable_link_state(char(26),char(26),char(26));
            DROP FUNCTION IF EXISTS public.guard_linked_entitlement_reversal();
            DROP FUNCTION IF EXISTS public.guard_entitlement_linked_receivable_cancellation();
            DROP FUNCTION IF EXISTS public.enforce_entitlement_receivable_link_history();

            DROP TABLE IF EXISTS public.entitlement_receivable_links;
            SQL);
    }

    private function grantRuntimePrivileges(): void
    {
        if (DB::selectOne('SELECT current_schema() AS name')->name !== 'public') {
            return;
        }

        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (
            ! is_string($runtimeRole)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)
        ) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.',
            );
        }

        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?',
            [$runtimeRole],
        );

        if (
            $role === null
            || $role->rolsuper
            || $role->rolcreaterole
            || $role->rolcreatedb
            || $role->rolreplication
            || $role->rolbypassrls
        ) {
            throw new RuntimeException(
                'Entitlement Receivable Link runtime role must exist and remain unprivileged.',
            );
        }

        $owner = DB::selectOne(
            "SELECT pg_catalog.pg_get_userbyid(relowner) AS name FROM pg_catalog.pg_class WHERE oid='public.entitlement_receivable_links'::regclass",
        )->name;

        if ($owner === $runtimeRole) {
            throw new RuntimeException(
                'Entitlement Receivable Link runtime role must not own protected objects.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE ALL ON TABLE public.entitlement_receivable_links FROM {$identifier}",
        );
        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE public.entitlement_receivable_links TO {$identifier}",
        );

        foreach ([
            'public.enforce_entitlement_receivable_link_history()',
            'public.guard_entitlement_linked_receivable_cancellation()',
            'public.guard_linked_entitlement_reversal()',
            'public.validate_entitlement_receivable_link_state(char(26),char(26),char(26))',
            'public.check_entitlement_receivable_link_final_state()',
        ] as $function) {
            DB::unprepared(
                "REVOKE EXECUTE ON FUNCTION {$function} FROM {$identifier}",
            );
        }
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
            'Entitlement Receivable Link v1 requires the public PostgreSQL schema.',
        );
    }
};
