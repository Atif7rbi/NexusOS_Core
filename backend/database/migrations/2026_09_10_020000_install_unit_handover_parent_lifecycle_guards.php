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
                'Unit Handover parent lifecycle integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            /*
             * The 00_ prefix is intentional.
             *
             * PostgreSQL fires triggers for the same event/timing in
             * alphabetical trigger-name order. The tenant lock therefore
             * precedes the existing history/source guards, preserving the
             * frozen Tenant -> Contract -> Reservation -> Unit -> Evidence
             * source-lock direction even for direct SQL callers.
             */
            CREATE OR REPLACE FUNCTION public.lock_unit_handover_evidence_tenant()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              tenant_row public.tenants%ROWTYPE;
            BEGIN
              SELECT *
              INTO tenant_row
              FROM public.tenants
              WHERE id = NEW.tenant_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Evidence requires the exact Tenant';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_evidence_00_tenant_lock_guard
            BEFORE INSERT
            ON public.unit_handover_evidence
            FOR EACH ROW
            EXECUTE FUNCTION public.lock_unit_handover_evidence_tenant();


            CREATE OR REPLACE FUNCTION public.validate_unit_handover_acceptance_tenant()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              tenant_row public.tenants%ROWTYPE;
            BEGIN
              SELECT *
              INTO tenant_row
              FROM public.tenants
              WHERE id = NEW.tenant_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Acceptance requires the exact Tenant';
              END IF;

              IF tenant_row.status <> 'active' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Acceptance requires an active Tenant';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_acceptance_00_tenant_eligibility_guard
            BEFORE INSERT
            ON public.unit_handover_acceptances
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_unit_handover_acceptance_tenant();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.prevent_contract_status_with_effective_handover()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF NEW.status IS DISTINCT FROM OLD.status
                 AND NEW.status NOT IN ('active','completed')
                 AND EXISTS (
                   SELECT 1
                   FROM public.unit_handover_acceptances acceptance
                   WHERE acceptance.tenant_id = OLD.tenant_id
                     AND acceptance.contract_id = OLD.id
                     AND acceptance.status = 'effective'
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'effective Unit Handover Acceptance must be reversed before Contract leaves performed lifecycle';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contracts_effective_handover_status_guard
            BEFORE UPDATE OF status
            ON public.contracts
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_contract_status_with_effective_handover();


            CREATE OR REPLACE FUNCTION public.prevent_reservation_status_with_effective_handover()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF NEW.status IS DISTINCT FROM OLD.status
                 AND NEW.status <> 'converted'
                 AND EXISTS (
                   SELECT 1
                   FROM public.unit_handover_acceptances acceptance
                   WHERE acceptance.tenant_id = OLD.tenant_id
                     AND acceptance.reservation_id = OLD.id
                     AND acceptance.status = 'effective'
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'effective Unit Handover Acceptance requires converted Reservation state';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER reservations_effective_handover_status_guard
            BEFORE UPDATE OF status
            ON public.reservations
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_reservation_status_with_effective_handover();


            CREATE OR REPLACE FUNCTION public.prevent_unit_mutation_with_effective_handover()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF (
                   (
                     NEW.status IS DISTINCT FROM OLD.status
                     AND NEW.status <> 'sold'
                   )
                   OR
                   (
                     NEW.archived_at IS DISTINCT FROM OLD.archived_at
                     AND NEW.archived_at IS NOT NULL
                   )
                 )
                 AND EXISTS (
                   SELECT 1
                   FROM public.unit_handover_acceptances acceptance
                   WHERE acceptance.tenant_id = OLD.tenant_id
                     AND acceptance.unit_id = OLD.id
                     AND acceptance.status = 'effective'
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'effective Unit Handover Acceptance requires sold non-archived Unit state';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER units_effective_handover_lifecycle_guard
            BEFORE UPDATE OF status,archived_at
            ON public.units
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_unit_mutation_with_effective_handover();
            SQL);

        DB::unprepared(<<<'SQL'
            REVOKE EXECUTE ON FUNCTION
              public.lock_unit_handover_evidence_tenant(),
              public.validate_unit_handover_acceptance_tenant(),
              public.prevent_contract_status_with_effective_handover(),
              public.prevent_reservation_status_with_effective_handover(),
              public.prevent_unit_mutation_with_effective_handover()
            FROM PUBLIC;
            SQL);

        $this->revokeRuntimeFunctionExecution();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Unit Handover parent lifecycle integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS
              units_effective_handover_lifecycle_guard
              ON public.units;

            DROP TRIGGER IF EXISTS
              reservations_effective_handover_status_guard
              ON public.reservations;

            DROP TRIGGER IF EXISTS
              contracts_effective_handover_status_guard
              ON public.contracts;

            DROP TRIGGER IF EXISTS
              unit_handover_acceptance_00_tenant_eligibility_guard
              ON public.unit_handover_acceptances;

            DROP TRIGGER IF EXISTS
              unit_handover_evidence_00_tenant_lock_guard
              ON public.unit_handover_evidence;

            DROP FUNCTION IF EXISTS
              public.prevent_unit_mutation_with_effective_handover();

            DROP FUNCTION IF EXISTS
              public.prevent_reservation_status_with_effective_handover();

            DROP FUNCTION IF EXISTS
              public.prevent_contract_status_with_effective_handover();

            DROP FUNCTION IF EXISTS
              public.validate_unit_handover_acceptance_tenant();

            DROP FUNCTION IF EXISTS
              public.lock_unit_handover_evidence_tenant();
            SQL);
    }

    private function revokeRuntimeFunctionExecution(): void
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

        $role = DB::selectOne(
            'SELECT
               rolname,
               rolsuper,
               rolcreaterole,
               rolcreatedb,
               rolreplication,
               rolbypassrls
             FROM pg_catalog.pg_roles
             WHERE rolname=?',
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
                'Unit Handover runtime role must exist and remain unprivileged.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(<<<SQL
            REVOKE EXECUTE ON FUNCTION
              public.lock_unit_handover_evidence_tenant(),
              public.validate_unit_handover_acceptance_tenant(),
              public.prevent_contract_status_with_effective_handover(),
              public.prevent_reservation_status_with_effective_handover(),
              public.prevent_unit_mutation_with_effective_handover()
            FROM {$identifier};
            SQL);
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
            'Unit Handover parent lifecycle integrity requires the public PostgreSQL schema.',
        );
    }
};
