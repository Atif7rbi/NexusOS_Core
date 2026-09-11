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
                'Unit Handover Performance Source integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_unit_handover_evidence_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unit handover evidence deletion is forbidden';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'effective' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'unit handover evidence must initially be effective';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.contract_id,
                NEW.reservation_id,
                NEW.unit_id,
                NEW.customer_id,
                NEW.handover_evidence_operation_id,
                NEW.handover_evidence_reference,
                NEW.readiness_reference,
                NEW.readiness_effective_date,
                NEW.acceptance_basis,
                NEW.customer_acceptance_reference,
                NEW.customer_acceptance_effective_date,
                NEW.effective_date,
                NEW.recorded_by,
                NEW.recorded_at,
                NEW.created_at
              )
              IS DISTINCT FROM
              (
                OLD.id,
                OLD.tenant_id,
                OLD.contract_id,
                OLD.reservation_id,
                OLD.unit_id,
                OLD.customer_id,
                OLD.handover_evidence_operation_id,
                OLD.handover_evidence_reference,
                OLD.readiness_reference,
                OLD.readiness_effective_date,
                OLD.acceptance_basis,
                OLD.customer_acceptance_reference,
                OLD.customer_acceptance_effective_date,
                OLD.effective_date,
                OLD.recorded_by,
                OLD.recorded_at,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unit handover evidence canonical history is immutable';
              END IF;

              IF NOT (
                OLD.status = 'effective'
                AND NEW.status = 'reversed'
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unsupported unit handover evidence lifecycle mutation';
              END IF;

              IF EXISTS (
                SELECT 1
                FROM public.unit_handover_acceptances acceptance
                WHERE acceptance.tenant_id = OLD.tenant_id
                  AND acceptance.handover_evidence_id = OLD.id
                  AND acceptance.status = 'effective'
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'effective unit handover acceptance must be reversed before its evidence';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_evidence_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.unit_handover_evidence
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_unit_handover_evidence_history();


            CREATE OR REPLACE FUNCTION public.validate_unit_handover_evidence_source()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              contract_row public.contracts%ROWTYPE;
              reservation_row public.reservations%ROWTYPE;
              unit_row public.units%ROWTYPE;
            BEGIN
              SELECT *
              INTO contract_row
              FROM public.contracts
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.contract_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence requires the exact tenant Contract';
              END IF;

              SELECT *
              INTO reservation_row
              FROM public.reservations
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.reservation_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence requires the exact tenant Reservation';
              END IF;

              SELECT *
              INTO unit_row
              FROM public.units
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.unit_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence requires the exact tenant Unit';
              END IF;

              IF contract_row.reservation_id <> reservation_row.id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence Contract and Reservation provenance is inconsistent';
              END IF;

              IF reservation_row.unit_id <> unit_row.id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence Reservation and Unit provenance is inconsistent';
              END IF;

              IF reservation_row.customer_id <> NEW.customer_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover evidence customer provenance is inconsistent';
              END IF;

              IF contract_row.currency <> 'SAR' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover performance source v1 requires a SAR Contract';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_evidence_source_guard
            BEFORE INSERT
            ON public.unit_handover_evidence
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_unit_handover_evidence_source();


            CREATE OR REPLACE FUNCTION public.enforce_unit_handover_acceptance_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unit handover acceptance deletion is forbidden';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'effective' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'unit handover acceptance must initially be effective';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.handover_evidence_id,
                NEW.contract_id,
                NEW.reservation_id,
                NEW.unit_id,
                NEW.customer_id,
                NEW.handover_acceptance_operation_id,
                NEW.performance_date,
                NEW.performance_amount,
                NEW.currency,
                NEW.created_by,
                NEW.created_at
              )
              IS DISTINCT FROM
              (
                OLD.id,
                OLD.tenant_id,
                OLD.handover_evidence_id,
                OLD.contract_id,
                OLD.reservation_id,
                OLD.unit_id,
                OLD.customer_id,
                OLD.handover_acceptance_operation_id,
                OLD.performance_date,
                OLD.performance_amount,
                OLD.currency,
                OLD.created_by,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unit handover acceptance economic truth is immutable';
              END IF;

              IF NOT (
                OLD.status = 'effective'
                AND NEW.status = 'reversed'
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unsupported unit handover acceptance lifecycle mutation';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_acceptance_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.unit_handover_acceptances
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_unit_handover_acceptance_history();


            CREATE OR REPLACE FUNCTION public.validate_unit_handover_acceptance_source()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              contract_row public.contracts%ROWTYPE;
              reservation_row public.reservations%ROWTYPE;
              unit_row public.units%ROWTYPE;
              evidence_row public.unit_handover_evidence%ROWTYPE;
            BEGIN
              SELECT *
              INTO contract_row
              FROM public.contracts
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.contract_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires the exact tenant Contract';
              END IF;

              SELECT *
              INTO reservation_row
              FROM public.reservations
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.reservation_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires the exact tenant Reservation';
              END IF;

              SELECT *
              INTO unit_row
              FROM public.units
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.unit_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires the exact tenant Unit';
              END IF;

              SELECT *
              INTO evidence_row
              FROM public.unit_handover_evidence
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.handover_evidence_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires the exact handover Evidence';
              END IF;

              IF contract_row.status NOT IN ('active','completed') THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires an active or completed Contract';
              END IF;

              IF contract_row.currency <> 'SAR' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover performance v1 requires a SAR Contract';
              END IF;

              IF reservation_row.status <> 'converted' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires a converted Reservation';
              END IF;

              IF unit_row.status <> 'sold'
                 OR unit_row.archived_at IS NOT NULL THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires a non-archived sold Unit';
              END IF;

              IF evidence_row.status <> 'effective' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance requires effective Evidence';
              END IF;

              IF contract_row.reservation_id <> reservation_row.id
                 OR reservation_row.unit_id <> unit_row.id
                 OR reservation_row.customer_id <> NEW.customer_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance source chain is inconsistent';
              END IF;

              IF (
                evidence_row.contract_id,
                evidence_row.reservation_id,
                evidence_row.unit_id,
                evidence_row.customer_id
              )
              IS DISTINCT FROM
              (
                NEW.contract_id,
                NEW.reservation_id,
                NEW.unit_id,
                NEW.customer_id
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover acceptance must preserve exact Evidence provenance';
              END IF;

              IF NEW.performance_date <> evidence_row.effective_date THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover performance date must equal Evidence effective date';
              END IF;

              IF NEW.performance_amount <> contract_row.total_amount THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover performance amount must equal Contract total amount';
              END IF;

              IF NEW.currency <> contract_row.currency
                 OR NEW.currency <> 'SAR' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover performance currency must equal SAR Contract currency';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER unit_handover_acceptance_source_guard
            BEFORE INSERT
            ON public.unit_handover_acceptances
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_unit_handover_acceptance_source();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.validate_unit_handover_evidence_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              evidence_row public.unit_handover_evidence%ROWTYPE;
              contract_row public.contracts%ROWTYPE;
              reservation_row public.reservations%ROWTYPE;
              unit_row public.units%ROWTYPE;
            BEGIN
              SELECT *
              INTO evidence_row
              FROM public.unit_handover_evidence
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.id;

              IF NOT FOUND THEN
                RETURN NULL;
              END IF;

              SELECT *
              INTO contract_row
              FROM public.contracts
              WHERE tenant_id = evidence_row.tenant_id
                AND id = evidence_row.contract_id;

              SELECT *
              INTO reservation_row
              FROM public.reservations
              WHERE tenant_id = evidence_row.tenant_id
                AND id = evidence_row.reservation_id;

              SELECT *
              INTO unit_row
              FROM public.units
              WHERE tenant_id = evidence_row.tenant_id
                AND id = evidence_row.unit_id;

              IF contract_row.id IS NULL
                 OR reservation_row.id IS NULL
                 OR unit_row.id IS NULL
                 OR contract_row.reservation_id <> reservation_row.id
                 OR reservation_row.unit_id <> unit_row.id
                 OR reservation_row.customer_id <> evidence_row.customer_id
                 OR contract_row.currency <> 'SAR'
                 OR evidence_row.effective_date <>
                    evidence_row.customer_acceptance_effective_date THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Evidence final-state provenance is inconsistent';
              END IF;

              IF evidence_row.status = 'reversed'
                 AND EXISTS (
                   SELECT 1
                   FROM public.unit_handover_acceptances acceptance
                   WHERE acceptance.tenant_id = evidence_row.tenant_id
                     AND acceptance.handover_evidence_id = evidence_row.id
                     AND acceptance.status = 'effective'
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'reversed Evidence cannot retain an effective Acceptance';
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER unit_handover_evidence_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.unit_handover_evidence
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_unit_handover_evidence_final_state();


            CREATE OR REPLACE FUNCTION public.validate_unit_handover_acceptance_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              acceptance_row public.unit_handover_acceptances%ROWTYPE;
              evidence_row public.unit_handover_evidence%ROWTYPE;
              contract_row public.contracts%ROWTYPE;
              reservation_row public.reservations%ROWTYPE;
              unit_row public.units%ROWTYPE;
            BEGIN
              SELECT *
              INTO acceptance_row
              FROM public.unit_handover_acceptances
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.id;

              IF NOT FOUND THEN
                RETURN NULL;
              END IF;

              SELECT *
              INTO evidence_row
              FROM public.unit_handover_evidence
              WHERE tenant_id = acceptance_row.tenant_id
                AND id = acceptance_row.handover_evidence_id;

              SELECT *
              INTO contract_row
              FROM public.contracts
              WHERE tenant_id = acceptance_row.tenant_id
                AND id = acceptance_row.contract_id;

              SELECT *
              INTO reservation_row
              FROM public.reservations
              WHERE tenant_id = acceptance_row.tenant_id
                AND id = acceptance_row.reservation_id;

              SELECT *
              INTO unit_row
              FROM public.units
              WHERE tenant_id = acceptance_row.tenant_id
                AND id = acceptance_row.unit_id;

              IF evidence_row.id IS NULL
                 OR contract_row.id IS NULL
                 OR reservation_row.id IS NULL
                 OR unit_row.id IS NULL THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Acceptance final-state source is missing';
              END IF;

              IF (
                evidence_row.contract_id,
                evidence_row.reservation_id,
                evidence_row.unit_id,
                evidence_row.customer_id
              )
              IS DISTINCT FROM
              (
                acceptance_row.contract_id,
                acceptance_row.reservation_id,
                acceptance_row.unit_id,
                acceptance_row.customer_id
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Acceptance final-state provenance is inconsistent';
              END IF;

              IF contract_row.reservation_id <> reservation_row.id
                 OR reservation_row.unit_id <> unit_row.id
                 OR reservation_row.customer_id <> acceptance_row.customer_id
                 OR contract_row.currency <> 'SAR'
                 OR acceptance_row.currency <> 'SAR'
                 OR acceptance_row.performance_amount <> contract_row.total_amount
                 OR acceptance_row.performance_date <> evidence_row.effective_date THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'unit handover Acceptance final-state economics are inconsistent';
              END IF;

              IF acceptance_row.status = 'effective'
                 AND evidence_row.status <> 'effective' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'effective Acceptance requires effective Evidence';
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER unit_handover_acceptance_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.unit_handover_acceptances
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_unit_handover_acceptance_final_state();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.prevent_contract_handover_provenance_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF (
                NEW.reservation_id,
                NEW.total_amount,
                NEW.currency
              )
              IS DISTINCT FROM
              (
                OLD.reservation_id,
                OLD.total_amount,
                OLD.currency
              )
              AND EXISTS (
                SELECT 1
                FROM public.unit_handover_evidence evidence
                WHERE evidence.tenant_id = OLD.tenant_id
                  AND evidence.contract_id = OLD.id
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract handover provenance is immutable after Unit Handover history';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contracts_handover_provenance_guard
            BEFORE UPDATE OF reservation_id,total_amount,currency
            ON public.contracts
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_contract_handover_provenance_mutation();


            CREATE OR REPLACE FUNCTION public.prevent_reservation_handover_provenance_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF (
                NEW.unit_id,
                NEW.customer_id
              )
              IS DISTINCT FROM
              (
                OLD.unit_id,
                OLD.customer_id
              )
              AND EXISTS (
                SELECT 1
                FROM public.unit_handover_evidence evidence
                WHERE evidence.tenant_id = OLD.tenant_id
                  AND evidence.reservation_id = OLD.id
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Reservation handover provenance is immutable after Unit Handover history';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER reservations_handover_provenance_guard
            BEFORE UPDATE OF unit_id,customer_id
            ON public.reservations
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_reservation_handover_provenance_mutation();


            REVOKE EXECUTE ON FUNCTION
              public.enforce_unit_handover_evidence_history(),
              public.validate_unit_handover_evidence_source(),
              public.enforce_unit_handover_acceptance_history(),
              public.validate_unit_handover_acceptance_source(),
              public.validate_unit_handover_evidence_final_state(),
              public.validate_unit_handover_acceptance_final_state(),
              public.prevent_contract_handover_provenance_mutation(),
              public.prevent_reservation_handover_provenance_mutation()
            FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Unit Handover Performance Source integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $this->revokeRuntimePrivileges();

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS
              reservations_handover_provenance_guard
              ON public.reservations;

            DROP TRIGGER IF EXISTS
              contracts_handover_provenance_guard
              ON public.contracts;

            DROP TRIGGER IF EXISTS
              unit_handover_acceptance_final_state_guard
              ON public.unit_handover_acceptances;

            DROP TRIGGER IF EXISTS
              unit_handover_evidence_final_state_guard
              ON public.unit_handover_evidence;

            DROP TRIGGER IF EXISTS
              unit_handover_acceptance_source_guard
              ON public.unit_handover_acceptances;

            DROP TRIGGER IF EXISTS
              unit_handover_acceptance_history_guard
              ON public.unit_handover_acceptances;

            DROP TRIGGER IF EXISTS
              unit_handover_evidence_source_guard
              ON public.unit_handover_evidence;

            DROP TRIGGER IF EXISTS
              unit_handover_evidence_history_guard
              ON public.unit_handover_evidence;

            DROP FUNCTION IF EXISTS
              public.prevent_reservation_handover_provenance_mutation();

            DROP FUNCTION IF EXISTS
              public.prevent_contract_handover_provenance_mutation();

            DROP FUNCTION IF EXISTS
              public.validate_unit_handover_acceptance_final_state();

            DROP FUNCTION IF EXISTS
              public.validate_unit_handover_evidence_final_state();

            DROP FUNCTION IF EXISTS
              public.validate_unit_handover_acceptance_source();

            DROP FUNCTION IF EXISTS
              public.enforce_unit_handover_acceptance_history();

            DROP FUNCTION IF EXISTS
              public.validate_unit_handover_evidence_source();

            DROP FUNCTION IF EXISTS
              public.enforce_unit_handover_evidence_history();
            SQL);
    }

    private function grantRuntimePrivileges(): void
    {
        if (DB::selectOne(
            'SELECT current_schema() AS name',
        )->name !== 'public') {
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

        $ownsProtectedObject = (bool) DB::selectOne(
            <<<'SQL'
                SELECT EXISTS(
                  SELECT 1
                  FROM pg_catalog.pg_class relation
                  JOIN pg_catalog.pg_namespace schema
                    ON schema.oid = relation.relnamespace
                  WHERE schema.nspname = 'public'
                    AND relation.relname IN (
                      'unit_handover_evidence',
                      'unit_handover_acceptances'
                    )
                    AND pg_catalog.pg_get_userbyid(relation.relowner) = ?
                ) AS owns
                SQL,
            [$runtimeRole],
        )->owns;

        if ($ownsProtectedObject) {
            throw new RuntimeException(
                'Unit Handover runtime role must not own protected objects.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE ALL ON TABLE
               public.unit_handover_evidence,
               public.unit_handover_acceptances
             FROM {$identifier}",
        );

        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE
               public.unit_handover_evidence,
               public.unit_handover_acceptances
             TO {$identifier}",
        );

        DB::unprepared(<<<SQL
            REVOKE EXECUTE ON FUNCTION
              public.enforce_unit_handover_evidence_history(),
              public.validate_unit_handover_evidence_source(),
              public.enforce_unit_handover_acceptance_history(),
              public.validate_unit_handover_acceptance_source(),
              public.validate_unit_handover_evidence_final_state(),
              public.validate_unit_handover_acceptance_final_state(),
              public.prevent_contract_handover_provenance_mutation(),
              public.prevent_reservation_handover_provenance_mutation()
            FROM {$identifier};
            SQL);
    }

    private function revokeRuntimePrivileges(): void
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (
            is_string($runtimeRole)
            && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)
        ) {
            $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

            DB::unprepared(
                "REVOKE ALL ON TABLE
                   public.unit_handover_evidence,
                   public.unit_handover_acceptances
                 FROM {$identifier}",
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
            'Unit Handover Performance Source integrity requires the public PostgreSQL schema.',
        );
    }
};
