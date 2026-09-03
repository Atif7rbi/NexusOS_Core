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
                'Contractual Billing Source integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_contractual_billing_schedule_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing schedules cannot be deleted';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'draft' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing schedule must initially be draft';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.contract_id,
                NEW.schedule_operation_id,
                NEW.billing_model,
                NEW.replaces_schedule_id,
                NEW.created_by,
                NEW.created_at
              ) IS DISTINCT FROM (
                OLD.id,
                OLD.tenant_id,
                OLD.contract_id,
                OLD.schedule_operation_id,
                OLD.billing_model,
                OLD.replaces_schedule_id,
                OLD.created_by,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing schedule identity is immutable';
              END IF;

              IF OLD.status IN ('superseded', 'cancelled') THEN
                IF NEW IS DISTINCT FROM OLD THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'terminal contractual billing schedule is immutable';
                END IF;

                RETURN NEW;
              END IF;

              IF OLD.status = 'draft' THEN
                IF NEW.status NOT IN ('draft', 'finalized', 'cancelled') THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'unsupported contractual billing schedule lifecycle mutation';
                END IF;

                IF NEW.status = 'draft' THEN
                  IF (
                    NEW.contractual_timezone,
                    NEW.finalization_operation_id,
                    NEW.finalized_by,
                    NEW.finalized_at,
                    NEW.draft_cancellation_operation_id,
                    NEW.draft_cancelled_by,
                    NEW.draft_cancelled_at,
                    NEW.draft_cancellation_reason,
                    NEW.draft_cancellation_reference,
                    NEW.source_correction_operation_id,
                    NEW.source_corrected_by,
                    NEW.source_corrected_at,
                    NEW.source_correction_reason,
                    NEW.source_correction_reference
                  ) IS DISTINCT FROM (
                    OLD.contractual_timezone,
                    OLD.finalization_operation_id,
                    OLD.finalized_by,
                    OLD.finalized_at,
                    OLD.draft_cancellation_operation_id,
                    OLD.draft_cancelled_by,
                    OLD.draft_cancelled_at,
                    OLD.draft_cancellation_reason,
                    OLD.draft_cancellation_reference,
                    OLD.source_correction_operation_id,
                    OLD.source_corrected_by,
                    OLD.source_corrected_at,
                    OLD.source_correction_reason,
                    OLD.source_correction_reference
                  ) THEN
                    RAISE EXCEPTION USING
                      ERRCODE = '55000',
                      MESSAGE = 'draft contractual billing schedule lifecycle evidence cannot be edited';
                  END IF;
                END IF;

                RETURN NEW;
              END IF;

              IF OLD.status = 'finalized' THEN
                IF NEW.status NOT IN ('finalized', 'superseded', 'cancelled') THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'unsupported finalized contractual billing schedule lifecycle mutation';
                END IF;

                IF (
                  NEW.contractual_timezone,
                  NEW.finalization_operation_id,
                  NEW.finalized_by,
                  NEW.finalized_at
                ) IS DISTINCT FROM (
                  OLD.contractual_timezone,
                  OLD.finalization_operation_id,
                  OLD.finalized_by,
                  OLD.finalized_at
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'finalized contractual billing schedule authority is immutable';
                END IF;

                IF NEW.status = 'finalized'
                   AND NEW IS DISTINCT FROM OLD THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'finalized contractual billing schedule cannot be edited';
                END IF;

                RETURN NEW;
              END IF;

              RAISE EXCEPTION USING
                ERRCODE = '55000',
                MESSAGE = 'unsupported contractual billing schedule mutation';
            END;
            $$;

            CREATE TRIGGER contractual_billing_schedules_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contractual_billing_schedules
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contractual_billing_schedule_history();


            CREATE OR REPLACE FUNCTION public.validate_contractual_billing_schedule_finalization()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              source_tenant public.tenants%ROWTYPE;
              source_contract public.contracts%ROWTYPE;
              included_count bigint;
              included_total numeric(19,2);
            BEGIN
              IF TG_OP <> 'UPDATE'
                 OR OLD.status <> 'draft'
                 OR NEW.status <> 'finalized' THEN
                RETURN NEW;
              END IF;

              SELECT *
              INTO source_tenant
              FROM public.tenants
              WHERE id = NEW.tenant_id
              FOR UPDATE NOWAIT;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule Tenant is unavailable';
              END IF;

              IF source_tenant.timezone IS NULL
                 OR btrim(source_tenant.timezone) = ''
                 OR NOT EXISTS (
                   SELECT 1
                   FROM pg_catalog.pg_timezone_names
                   WHERE name = source_tenant.timezone
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'Tenant timezone is not a valid contractual timezone authority';
              END IF;

              IF NEW.contractual_timezone IS DISTINCT FROM source_tenant.timezone THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing timezone must snapshot Tenant timezone';
              END IF;

              SELECT *
              INTO source_contract
              FROM public.contracts
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.contract_id
              FOR UPDATE NOWAIT;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule Contract is unavailable';
              END IF;

              IF source_contract.status NOT IN ('active', 'completed') THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule requires an active or completed Contract';
              END IF;

              IF source_contract.currency <> 'SAR' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule v1 requires a SAR Contract';
              END IF;

              PERFORM obligation.id
              FROM public.contractual_billing_obligations obligation
              WHERE obligation.tenant_id = NEW.tenant_id
                AND obligation.schedule_id = NEW.id
              ORDER BY obligation.id
              FOR UPDATE;

              SELECT
                count(*),
                COALESCE(sum(amount), 0)
              INTO
                included_count,
                included_total
              FROM public.contractual_billing_obligations
              WHERE tenant_id = NEW.tenant_id
                AND schedule_id = NEW.id
                AND draft_membership_status = 'included';

              IF included_count < 1 THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule requires at least one included obligation';
              END IF;

              IF included_total IS DISTINCT FROM source_contract.total_amount::numeric(19,2) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing schedule total must equal Contract total_amount';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contractual_billing_schedules_finalization_guard
            BEFORE UPDATE OF status
            ON public.contractual_billing_schedules
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contractual_billing_schedule_finalization();


            CREATE OR REPLACE FUNCTION public.enforce_contractual_billing_obligation_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              source_contract public.contracts%ROWTYPE;
              source_schedule public.contractual_billing_schedules%ROWTYPE;
              reservation_customer_id char(26);
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing obligations cannot be deleted';
              END IF;

              SELECT *
              INTO source_contract
              FROM public.contracts
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.contract_id
              FOR UPDATE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing obligation Contract is unavailable';
              END IF;

              SELECT customer_id
              INTO reservation_customer_id
              FROM public.reservations
              WHERE tenant_id = NEW.tenant_id
                AND id = source_contract.reservation_id
              FOR KEY SHARE;

              IF NOT FOUND THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing obligation Reservation provenance is unavailable';
              END IF;

              SELECT *
              INTO source_schedule
              FROM public.contractual_billing_schedules
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.schedule_id
              FOR UPDATE;

              IF NOT FOUND
                 OR source_schedule.contract_id <> NEW.contract_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing obligation Schedule provenance is inconsistent';
              END IF;

              IF source_schedule.status <> 'draft' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing obligations are mutable only while Schedule is draft';
              END IF;

              IF NEW.customer_id <> reservation_customer_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'contractual billing obligation customer must derive from Contract Reservation';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.draft_membership_status <> 'included' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing obligation must initially be included';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.schedule_id,
                NEW.contract_id,
                NEW.obligation_operation_id,
                NEW.customer_id,
                NEW.created_by,
                NEW.created_at
              ) IS DISTINCT FROM (
                OLD.id,
                OLD.tenant_id,
                OLD.schedule_id,
                OLD.contract_id,
                OLD.obligation_operation_id,
                OLD.customer_id,
                OLD.created_by,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing obligation identity is immutable';
              END IF;

              IF OLD.draft_membership_status = 'removed' THEN
                IF NEW IS DISTINCT FROM OLD THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'removed contractual billing obligation is immutable';
                END IF;

                RETURN NEW;
              END IF;

              IF OLD.draft_membership_status = 'included'
                 AND NEW.draft_membership_status NOT IN ('included', 'removed') THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unsupported contractual billing obligation membership mutation';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contractual_billing_obligations_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contractual_billing_obligations
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contractual_billing_obligation_history();


            CREATE OR REPLACE FUNCTION public.enforce_contractual_billing_entitlement_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              source_contract public.contracts%ROWTYPE;
              source_schedule public.contractual_billing_schedules%ROWTYPE;
              source_obligation public.contractual_billing_obligations%ROWTYPE;
              source_customer_id char(26);
              current_business_date date;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing entitlements cannot be deleted';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NEW.status <> 'effective' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement must initially be effective';
                END IF;

                SELECT *
                INTO source_contract
                FROM public.contracts
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.contract_id
                FOR UPDATE;

                IF NOT FOUND
                   OR source_contract.status NOT IN ('active', 'completed') THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement requires an active or completed Contract';
                END IF;

                SELECT customer_id
                INTO source_customer_id
                FROM public.reservations
                WHERE tenant_id = NEW.tenant_id
                  AND id = source_contract.reservation_id
                FOR KEY SHARE;

                IF NOT FOUND
                   OR source_customer_id <> NEW.customer_id THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement customer provenance is inconsistent';
                END IF;

                SELECT *
                INTO source_schedule
                FROM public.contractual_billing_schedules
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.schedule_id
                FOR UPDATE;

                IF NOT FOUND
                   OR source_schedule.contract_id <> NEW.contract_id
                   OR source_schedule.status <> 'finalized' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement requires the current finalized Schedule';
                END IF;

                SELECT *
                INTO source_obligation
                FROM public.contractual_billing_obligations
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.obligation_id
                FOR UPDATE;

                IF NOT FOUND
                   OR source_obligation.schedule_id <> NEW.schedule_id
                   OR source_obligation.contract_id <> NEW.contract_id
                   OR source_obligation.customer_id <> NEW.customer_id
                   OR source_obligation.draft_membership_status <> 'included' THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement Obligation provenance is inconsistent';
                END IF;

                IF NEW.amount IS DISTINCT FROM source_obligation.amount
                   OR NEW.currency IS DISTINCT FROM source_obligation.currency
                   OR NEW.economic_date IS DISTINCT FROM source_obligation.contractual_due_date THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement canonical facts must derive from Obligation';
                END IF;

                current_business_date :=
                  (CURRENT_TIMESTAMP AT TIME ZONE source_schedule.contractual_timezone)::date;

                IF current_business_date < source_obligation.contractual_due_date THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'contractual billing entitlement cannot become effective before contractual due date';
                END IF;

                RETURN NEW;
              END IF;

              IF (
                NEW.id,
                NEW.tenant_id,
                NEW.billing_entitlement_operation_id,
                NEW.schedule_id,
                NEW.obligation_id,
                NEW.contract_id,
                NEW.customer_id,
                NEW.amount,
                NEW.currency,
                NEW.economic_date,
                NEW.effective_at,
                NEW.recognized_by,
                NEW.recognized_at,
                NEW.created_at
              ) IS DISTINCT FROM (
                OLD.id,
                OLD.tenant_id,
                OLD.billing_entitlement_operation_id,
                OLD.schedule_id,
                OLD.obligation_id,
                OLD.contract_id,
                OLD.customer_id,
                OLD.amount,
                OLD.currency,
                OLD.economic_date,
                OLD.effective_at,
                OLD.recognized_by,
                OLD.recognized_at,
                OLD.created_at
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'contractual billing entitlement canonical history is immutable';
              END IF;

              IF OLD.status = 'reversed' THEN
                IF NEW IS DISTINCT FROM OLD THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = 'reversed contractual billing entitlement is immutable';
                END IF;

                RETURN NEW;
              END IF;

              IF NOT (
                OLD.status = 'effective'
                AND NEW.status = 'reversed'
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'unsupported contractual billing entitlement lifecycle mutation';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contractual_billing_entitlements_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contractual_billing_entitlements
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contractual_billing_entitlement_history();


            CREATE OR REPLACE FUNCTION public.validate_contractual_billing_schedule_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              successor_count bigint;
              predecessor public.contractual_billing_schedules%ROWTYPE;
            BEGIN
              IF NEW.status IN ('superseded', 'cancelled')
                 AND NEW.finalization_operation_id IS NOT NULL
                 AND EXISTS (
                   SELECT 1
                   FROM public.contractual_billing_entitlements entitlement
                   WHERE entitlement.tenant_id = NEW.tenant_id
                     AND entitlement.schedule_id = NEW.id
                     AND entitlement.status = 'effective'
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'source-corrected Schedule cannot retain effective Entitlements';
              END IF;

              IF NEW.status = 'superseded' THEN
                SELECT count(*)
                INTO successor_count
                FROM public.contractual_billing_schedules successor
                WHERE successor.tenant_id = NEW.tenant_id
                  AND successor.replaces_schedule_id = NEW.id
                  AND successor.status = 'finalized';

                IF successor_count <> 1 THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'superseded Schedule requires exactly one finalized successor';
                END IF;
              END IF;

              IF NEW.status = 'finalized'
                 AND NEW.replaces_schedule_id IS NOT NULL THEN
                SELECT *
                INTO predecessor
                FROM public.contractual_billing_schedules
                WHERE tenant_id = NEW.tenant_id
                  AND id = NEW.replaces_schedule_id;

                IF NOT FOUND
                   OR predecessor.contract_id <> NEW.contract_id
                   OR predecessor.status <> 'superseded'
                   OR predecessor.source_correction_operation_id IS NULL THEN
                  RAISE EXCEPTION USING
                    ERRCODE = '23514',
                    MESSAGE = 'replacement Schedule requires a coherently superseded predecessor';
                END IF;
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER contractual_billing_schedules_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.contractual_billing_schedules
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contractual_billing_schedule_final_state();


            CREATE OR REPLACE FUNCTION public.validate_contractual_billing_entitlement_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              source_schedule public.contractual_billing_schedules%ROWTYPE;
            BEGIN
              IF NEW.status <> 'reversed' THEN
                RETURN NULL;
              END IF;

              SELECT *
              INTO source_schedule
              FROM public.contractual_billing_schedules
              WHERE tenant_id = NEW.tenant_id
                AND id = NEW.schedule_id;

              IF NOT FOUND
                 OR source_schedule.status NOT IN ('superseded', 'cancelled')
                 OR source_schedule.finalization_operation_id IS NULL
                 OR source_schedule.source_correction_operation_id IS NULL
                 OR source_schedule.source_correction_operation_id
                    IS DISTINCT FROM NEW.source_correction_operation_id THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'Entitlement reversal requires matching Schedule source correction';
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER contractual_billing_entitlements_final_state_guard
            AFTER UPDATE
            ON public.contractual_billing_entitlements
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contractual_billing_entitlement_final_state();


            CREATE OR REPLACE FUNCTION public.prevent_contract_total_change_after_billing_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF NEW.total_amount IS DISTINCT FROM OLD.total_amount
                 AND EXISTS (
                   SELECT 1
                   FROM public.contractual_billing_schedules schedule
                   WHERE schedule.tenant_id = OLD.tenant_id
                     AND schedule.contract_id = OLD.id
                     AND schedule.finalization_operation_id IS NOT NULL
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract total_amount is immutable after finalized contractual billing history';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contracts_total_after_billing_history_guard
            BEFORE UPDATE OF total_amount
            ON public.contracts
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_contract_total_change_after_billing_history();


            REVOKE EXECUTE ON FUNCTION
              public.enforce_contractual_billing_schedule_history(),
              public.validate_contractual_billing_schedule_finalization(),
              public.enforce_contractual_billing_obligation_history(),
              public.enforce_contractual_billing_entitlement_history(),
              public.validate_contractual_billing_schedule_final_state(),
              public.validate_contractual_billing_entitlement_final_state(),
              public.prevent_contract_total_change_after_billing_history()
            FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS
              contracts_total_after_billing_history_guard
              ON public.contracts;

            DROP TRIGGER IF EXISTS
              contractual_billing_entitlements_final_state_guard
              ON public.contractual_billing_entitlements;

            DROP TRIGGER IF EXISTS
              contractual_billing_schedules_final_state_guard
              ON public.contractual_billing_schedules;

            DROP TRIGGER IF EXISTS
              contractual_billing_entitlements_history_guard
              ON public.contractual_billing_entitlements;

            DROP TRIGGER IF EXISTS
              contractual_billing_obligations_history_guard
              ON public.contractual_billing_obligations;

            DROP TRIGGER IF EXISTS
              contractual_billing_schedules_finalization_guard
              ON public.contractual_billing_schedules;

            DROP TRIGGER IF EXISTS
              contractual_billing_schedules_history_guard
              ON public.contractual_billing_schedules;

            DROP FUNCTION IF EXISTS
              public.prevent_contract_total_change_after_billing_history();

            DROP FUNCTION IF EXISTS
              public.validate_contractual_billing_entitlement_final_state();

            DROP FUNCTION IF EXISTS
              public.validate_contractual_billing_schedule_final_state();

            DROP FUNCTION IF EXISTS
              public.enforce_contractual_billing_entitlement_history();

            DROP FUNCTION IF EXISTS
              public.enforce_contractual_billing_obligation_history();

            DROP FUNCTION IF EXISTS
              public.validate_contractual_billing_schedule_finalization();

            DROP FUNCTION IF EXISTS
              public.enforce_contractual_billing_schedule_history();
            SQL);
    }

    private function grantRuntimePrivileges(): void
    {
        if (DB::selectOne('SELECT current_schema() AS name')->name !== 'public') {
            return;
        }

        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (! is_string($runtimeRole)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.',
            );
        }

        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls
             FROM pg_catalog.pg_roles
             WHERE rolname=?',
            [$runtimeRole],
        );

        if ($role === null
            || $role->rolsuper
            || $role->rolcreaterole
            || $role->rolcreatedb
            || $role->rolreplication
            || $role->rolbypassrls) {
            throw new RuntimeException(
                'Contractual Billing runtime role must exist and remain unprivileged.',
            );
        }

        $owner = DB::selectOne(
            "SELECT pg_catalog.pg_get_userbyid(relowner) AS name
             FROM pg_catalog.pg_class
             WHERE oid='public.contractual_billing_schedules'::regclass",
        )->name;

        if ($owner === $runtimeRole) {
            throw new RuntimeException(
                'Contractual Billing runtime role must not own protected objects.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE ALL ON TABLE
               public.contractual_billing_schedules,
               public.contractual_billing_obligations,
               public.contractual_billing_entitlements
             FROM {$identifier}",
        );

        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE
               public.contractual_billing_schedules,
               public.contractual_billing_obligations,
               public.contractual_billing_entitlements
             TO {$identifier}",
        );

        DB::unprepared(<<<SQL
            REVOKE EXECUTE ON FUNCTION
              public.enforce_contractual_billing_schedule_history(),
              public.validate_contractual_billing_schedule_finalization(),
              public.enforce_contractual_billing_obligation_history(),
              public.enforce_contractual_billing_entitlement_history(),
              public.validate_contractual_billing_schedule_final_state(),
              public.validate_contractual_billing_entitlement_final_state(),
              public.prevent_contract_total_change_after_billing_history()
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
            'Contractual Billing Source integrity requires the public PostgreSQL schema.',
        );
    }
};
