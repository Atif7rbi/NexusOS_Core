<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivables Foundations requires PostgreSQL.');
        }

        DB::statement('ALTER TABLE public.customers ADD CONSTRAINT customers_tenant_id_id_unique UNIQUE (tenant_id,id)');
        DB::statement('ALTER TABLE public.collections ADD CONSTRAINT collections_tenant_id_id_unique UNIQUE (tenant_id,id)');

        DB::statement(<<<'SQL'
            CREATE TABLE public.receivables (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,
              contract_id char(26),
              collection_id char(26),
              currency varchar(3) NOT NULL,
              recognized_amount numeric(19,2) NOT NULL,
              due_date date NOT NULL,
              status varchar(16) NOT NULL,
              recognized_at timestamptz NOT NULL,
              recognized_by bigint NOT NULL,
              cancelled_at timestamptz,
              cancelled_by bigint,
              cancellation_reason varchar(500),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT receivables_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT receivables_tenant_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_customer_foreign FOREIGN KEY (tenant_id,customer_id) REFERENCES public.customers(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_contract_foreign FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_collection_foreign FOREIGN KEY (tenant_id,collection_id) REFERENCES public.collections(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_recognized_actor_foreign FOREIGN KEY (tenant_id,recognized_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_cancelled_actor_foreign FOREIGN KEY (tenant_id,cancelled_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivables_amount_positive_check CHECK (recognized_amount > 0),
              CONSTRAINT receivables_currency_check CHECK (currency IN ('SAR','USD')),
              CONSTRAINT receivables_status_check CHECK (status IN ('recognized','cancelled')),
              CONSTRAINT receivables_lifecycle_check CHECK (
                (status='recognized' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                OR
                (status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason)<>'')
              )
            )
            SQL);

        DB::statement('CREATE INDEX receivables_tenant_status_due_date_index ON public.receivables(tenant_id,status,due_date,id)');
        DB::statement('CREATE INDEX receivables_tenant_customer_due_date_index ON public.receivables(tenant_id,customer_id,due_date,id)');
        DB::statement('CREATE INDEX receivables_tenant_contract_index ON public.receivables(tenant_id,contract_id) WHERE contract_id IS NOT NULL');
        DB::statement('CREATE INDEX receivables_tenant_collection_index ON public.receivables(tenant_id,collection_id) WHERE collection_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.enforce_receivable_history() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='receivable deletion is forbidden';
              END IF;
              IF (NEW.id,NEW.tenant_id,NEW.customer_id,NEW.contract_id,NEW.collection_id,NEW.currency,NEW.recognized_amount,NEW.due_date,NEW.recognized_at,NEW.recognized_by,NEW.created_at)
                 IS DISTINCT FROM
                 (OLD.id,OLD.tenant_id,OLD.customer_id,OLD.contract_id,OLD.collection_id,OLD.currency,OLD.recognized_amount,OLD.due_date,OLD.recognized_at,OLD.recognized_by,OLD.created_at) THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='recognized receivable truth is immutable';
              END IF;
              IF NOT (OLD.status='recognized' AND NEW.status='cancelled') THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported receivable lifecycle mutation';
              END IF;
              RETURN NEW;
            END;
            $$;
            CREATE TRIGGER receivables_history_guard
            BEFORE UPDATE OR DELETE ON public.receivables
            FOR EACH ROW EXECUTE FUNCTION public.enforce_receivable_history();
            REVOKE EXECUTE ON FUNCTION public.enforce_receivable_history() FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS receivables_history_guard ON public.receivables');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_receivable_history()');
        DB::statement('DROP TABLE IF EXISTS public.receivables');
        DB::statement('ALTER TABLE public.collections DROP CONSTRAINT IF EXISTS collections_tenant_id_id_unique');
        DB::statement('ALTER TABLE public.customers DROP CONSTRAINT IF EXISTS customers_tenant_id_id_unique');
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
        $role = DB::selectOne('SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?', [$runtimeRole]);
        if ($role === null || $role->rolsuper || $role->rolcreaterole || $role->rolcreatedb || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException('Receivables runtime role must exist and remain unprivileged.');
        }
        $owner = DB::selectOne("SELECT pg_catalog.pg_get_userbyid(relowner) AS name FROM pg_catalog.pg_class WHERE oid='public.receivables'::regclass")->name;
        if ($owner === $runtimeRole) {
            throw new RuntimeException('Receivables runtime role must not own protected Receivables objects.');
        }
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        DB::unprepared("REVOKE ALL ON TABLE public.receivables FROM {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE public.receivables TO {$identifier}");
        DB::unprepared("REVOKE EXECUTE ON FUNCTION public.enforce_receivable_history() FROM {$identifier}");
    }
};
