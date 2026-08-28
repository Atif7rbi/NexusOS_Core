<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Payments and Payment Allocations require PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.payments (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,
              payment_operation_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL,
              currency varchar(3) NOT NULL,
              received_on date NOT NULL,
              method varchar(100),
              reference varchar(255),
              status varchar(16) NOT NULL,
              received_by bigint NOT NULL,
              recorded_at timestamptz NOT NULL,
              cancelled_at timestamptz,
              cancelled_by bigint,
              cancellation_reason varchar(500),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT payments_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT payments_tenant_operation_unique UNIQUE (tenant_id,payment_operation_id),
              CONSTRAINT payments_tenant_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payments_customer_foreign FOREIGN KEY (tenant_id,customer_id) REFERENCES public.customers(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payments_received_actor_foreign FOREIGN KEY (tenant_id,received_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payments_cancelled_actor_foreign FOREIGN KEY (tenant_id,cancelled_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payments_amount_positive_check CHECK (amount > 0),
              CONSTRAINT payments_currency_check CHECK (currency IN ('SAR','USD')),
              CONSTRAINT payments_status_check CHECK (status IN ('received','cancelled')),
              CONSTRAINT payments_lifecycle_check CHECK (
                (status='received' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason)<>'')
              )
            );
            CREATE TABLE public.payment_allocations (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              payment_id char(26) NOT NULL,
              receivable_id char(26) NOT NULL,
              allocation_operation_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL,
              status varchar(16) NOT NULL,
              allocated_at timestamptz NOT NULL,
              allocated_by bigint NOT NULL,
              cancelled_at timestamptz,
              cancelled_by bigint,
              cancellation_reason varchar(500),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT payment_allocations_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT payment_allocations_tenant_operation_unique UNIQUE (tenant_id,allocation_operation_id),
              CONSTRAINT payment_allocations_payment_foreign FOREIGN KEY (tenant_id,payment_id) REFERENCES public.payments(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocations_receivable_foreign FOREIGN KEY (tenant_id,receivable_id) REFERENCES public.receivables(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocations_allocated_actor_foreign FOREIGN KEY (tenant_id,allocated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocations_cancelled_actor_foreign FOREIGN KEY (tenant_id,cancelled_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocations_amount_positive_check CHECK (amount > 0),
              CONSTRAINT payment_allocations_status_check CHECK (status IN ('effective','cancelled')),
              CONSTRAINT payment_allocations_lifecycle_check CHECK (
                (status='effective' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason)<>'')
              )
            );
            CREATE TABLE public.payment_allocation_audits (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              event varchar(64) NOT NULL,
              subject_type varchar(16) NOT NULL,
              subject_id char(26) NOT NULL,
              actor_id bigint NOT NULL,
              context jsonb NOT NULL,
              recorded_at timestamptz NOT NULL,
              CONSTRAINT payment_allocation_audits_tenant_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocation_audits_actor_foreign FOREIGN KEY (tenant_id,actor_id) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT payment_allocation_audits_context_check CHECK (jsonb_typeof(context)='object')
            );
            CREATE INDEX payment_allocations_effective_payment_index ON public.payment_allocations(tenant_id,payment_id) WHERE status='effective';
            CREATE INDEX payment_allocations_effective_receivable_index ON public.payment_allocations(tenant_id,receivable_id) WHERE status='effective';
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_payment_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='payment deletion is forbidden'; END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.status<>'received' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment must initially be received'; END IF;
                IF NEW.received_on > CURRENT_DATE THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment received_on cannot be in the future'; END IF;
                RETURN NEW;
              END IF;
              IF (NEW.id,NEW.tenant_id,NEW.customer_id,NEW.payment_operation_id,NEW.amount,NEW.currency,NEW.received_on,NEW.method,NEW.reference,NEW.received_by,NEW.recorded_at,NEW.created_at)
                 IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.customer_id,OLD.payment_operation_id,OLD.amount,OLD.currency,OLD.received_on,OLD.method,OLD.reference,OLD.received_by,OLD.recorded_at,OLD.created_at) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='payment business truth is immutable';
              END IF;
              IF NOT (OLD.status='received' AND NEW.status='cancelled') THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='unsupported payment lifecycle mutation'; END IF;
              IF EXISTS (SELECT 1 FROM public.payment_allocations WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.id AND status='effective') THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment with effective allocations cannot be cancelled';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER payments_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.payments FOR EACH ROW EXECUTE FUNCTION public.enforce_payment_history();
            CREATE OR REPLACE FUNCTION public.enforce_payment_allocation_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE payment_row public.payments%ROWTYPE; receivable_row public.receivables%ROWTYPE; payment_used numeric(19,2); receivable_used numeric(19,2);
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='payment allocation deletion is forbidden'; END IF;
              IF TG_OP='UPDATE' THEN
                IF (NEW.id,NEW.tenant_id,NEW.payment_id,NEW.receivable_id,NEW.allocation_operation_id,NEW.amount,NEW.allocated_at,NEW.allocated_by,NEW.created_at)
                   IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.payment_id,OLD.receivable_id,OLD.allocation_operation_id,OLD.amount,OLD.allocated_at,OLD.allocated_by,OLD.created_at) THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='payment allocation business truth is immutable'; END IF;
                IF NOT (OLD.status='effective' AND NEW.status='cancelled') THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='unsupported payment allocation lifecycle mutation'; END IF;
                RETURN NEW;
              END IF;
              IF NEW.status<>'effective' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment allocation must initially be effective'; END IF;
              SELECT * INTO payment_row FROM public.payments WHERE (tenant_id,id)=(NEW.tenant_id,NEW.payment_id) FOR UPDATE;
              SELECT * INTO receivable_row FROM public.receivables WHERE (tenant_id,id)=(NEW.tenant_id,NEW.receivable_id) FOR UPDATE;
              IF payment_row.status<>'received' OR receivable_row.status<>'recognized' OR payment_row.customer_id<>receivable_row.customer_id OR payment_row.currency<>receivable_row.currency THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment allocation eligibility is inconsistent'; END IF;
              SELECT coalesce(sum(amount),0) INTO payment_used FROM public.payment_allocations WHERE tenant_id=NEW.tenant_id AND payment_id=NEW.payment_id AND status='effective';
              SELECT coalesce(sum(amount),0) INTO receivable_used FROM public.payment_allocations WHERE tenant_id=NEW.tenant_id AND receivable_id=NEW.receivable_id AND status='effective';
              IF payment_used + NEW.amount > payment_row.amount THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment allocation exceeds payment capacity'; END IF;
              IF receivable_used + NEW.amount > receivable_row.recognized_amount THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment allocation exceeds receivable capacity'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER payment_allocations_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.payment_allocations FOR EACH ROW EXECUTE FUNCTION public.enforce_payment_allocation_history();
            REVOKE EXECUTE ON FUNCTION public.enforce_payment_history() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.enforce_payment_allocation_history() FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }
    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS public.payment_allocation_audits; DROP TABLE IF EXISTS public.payment_allocations; DROP TABLE IF EXISTS public.payments; DROP FUNCTION IF EXISTS public.enforce_payment_allocation_history(); DROP FUNCTION IF EXISTS public.enforce_payment_history();');
    }

    private function grantRuntimePrivileges(): void
    {
        if (DB::selectOne('SELECT current_schema() AS name')->name !== 'public') {
            return;
        }

        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! is_string($runtimeRole) || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            throw new RuntimeException('ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.');
        }
        $role = DB::selectOne('SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?', [$runtimeRole]);
        if ($role === null || $role->rolsuper || $role->rolcreaterole || $role->rolcreatedb || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException('Payments runtime role must exist and remain unprivileged.');
        }
        $owner = DB::selectOne("SELECT pg_catalog.pg_get_userbyid(relowner) AS name FROM pg_catalog.pg_class WHERE oid='public.payments'::regclass")->name;
        if ($owner === $runtimeRole) {
            throw new RuntimeException('Payments runtime role must not own protected Payments objects.');
        }
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        DB::unprepared("REVOKE ALL ON TABLE public.payments, public.payment_allocations, public.payment_allocation_audits FROM {$identifier}");
        DB::unprepared("GRANT SELECT, INSERT, UPDATE ON TABLE public.payments, public.payment_allocations TO {$identifier}");
        DB::unprepared("GRANT SELECT, INSERT ON TABLE public.payment_allocation_audits TO {$identifier}");
        DB::unprepared("REVOKE EXECUTE ON FUNCTION public.enforce_payment_history() FROM {$identifier}");
        DB::unprepared("REVOKE EXECUTE ON FUNCTION public.enforce_payment_allocation_history() FROM {$identifier}");
    }
};
