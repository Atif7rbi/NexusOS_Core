<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receipt Evidence requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.approved_receiving_accounts (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              receiving_account_operation_id char(26) NOT NULL,
              institution_identifier varchar(128) NOT NULL,
              account_identity varchar(255) NOT NULL,
              masked_account_identity varchar(255) NOT NULL,
              valid_from date NOT NULL,
              retired_from date,
              status varchar(16) NOT NULL,
              approved_by bigint NOT NULL,
              approved_at timestamptz NOT NULL,
              retirement_operation_id char(26),
              retired_by bigint,
              retired_at timestamptz,
              retirement_reason varchar(500),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT approved_receiving_accounts_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT approved_receiving_accounts_tenant_operation_unique UNIQUE (tenant_id,receiving_account_operation_id),
              CONSTRAINT approved_receiving_accounts_tenant_identity_unique UNIQUE (tenant_id,institution_identifier,account_identity),
              CONSTRAINT approved_receiving_accounts_tenant_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT approved_receiving_accounts_approved_actor_foreign FOREIGN KEY (tenant_id,approved_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT approved_receiving_accounts_retired_actor_foreign FOREIGN KEY (tenant_id,retired_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT approved_receiving_accounts_status_check CHECK (status IN ('approved','retired')),
              CONSTRAINT approved_receiving_accounts_lifecycle_check CHECK (
                (status='approved' AND retired_from IS NULL AND retirement_operation_id IS NULL AND retired_by IS NULL AND retired_at IS NULL AND retirement_reason IS NULL)
                OR (status='retired' AND retired_from IS NOT NULL AND retirement_operation_id IS NOT NULL AND retired_by IS NOT NULL AND retired_at IS NOT NULL AND btrim(retirement_reason)<>'')
              ),
              CONSTRAINT approved_receiving_accounts_temporal_check CHECK (retired_from IS NULL OR valid_from < retired_from)
            );
            CREATE UNIQUE INDEX approved_receiving_accounts_retirement_operation_unique
              ON public.approved_receiving_accounts(tenant_id,retirement_operation_id)
              WHERE retirement_operation_id IS NOT NULL;

            CREATE TABLE public.bank_receipt_evidence (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              receipt_operation_id char(26) NOT NULL,
              receiving_account_id char(26) NOT NULL,
              channel varchar(32) NOT NULL,
              source_identity_kind varchar(64) NOT NULL,
              source_identity_version smallint NOT NULL,
              source_identity varchar(512) NOT NULL,
              amount numeric(19,2) NOT NULL,
              currency varchar(3) NOT NULL,
              control_date date NOT NULL,
              evidence_reference varchar(500) NOT NULL,
              evidence_locator varchar(500),
              verification_method varchar(64) NOT NULL,
              verified_by bigint NOT NULL,
              verified_at timestamptz NOT NULL,
              status varchar(16) NOT NULL,
              invalidation_operation_id char(26),
              invalidated_by bigint,
              invalidated_at timestamptz,
              invalidation_reason varchar(500),
              replaces_receipt_id char(26),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT bank_receipt_evidence_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT bank_receipt_evidence_tenant_operation_unique UNIQUE (tenant_id,receipt_operation_id),
              CONSTRAINT bank_receipt_evidence_account_foreign FOREIGN KEY (tenant_id,receiving_account_id) REFERENCES public.approved_receiving_accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT bank_receipt_evidence_verifier_foreign FOREIGN KEY (tenant_id,verified_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT bank_receipt_evidence_invalidated_actor_foreign FOREIGN KEY (tenant_id,invalidated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT bank_receipt_evidence_replaces_foreign FOREIGN KEY (tenant_id,replaces_receipt_id) REFERENCES public.bank_receipt_evidence(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT bank_receipt_evidence_channel_check CHECK (channel='bank_transfer'),
              CONSTRAINT bank_receipt_evidence_source_kind_check CHECK (source_identity_kind IN ('bank_transaction_id','statement_line_fingerprint_v1')),
              CONSTRAINT bank_receipt_evidence_source_identity_check CHECK (source_identity_version > 0 AND btrim(source_identity)<>''),
              CONSTRAINT bank_receipt_evidence_amount_check CHECK (amount > 0),
              CONSTRAINT bank_receipt_evidence_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
              CONSTRAINT bank_receipt_evidence_status_check CHECK (status IN ('effective','invalidated')),
              CONSTRAINT bank_receipt_evidence_lifecycle_check CHECK (
                (status='effective' AND invalidation_operation_id IS NULL AND invalidated_by IS NULL AND invalidated_at IS NULL AND invalidation_reason IS NULL)
                OR (status='invalidated' AND invalidation_operation_id IS NOT NULL AND invalidated_by IS NOT NULL AND invalidated_at IS NOT NULL AND btrim(invalidation_reason)<>'')
              )
            );
            CREATE UNIQUE INDEX bank_receipt_evidence_invalidation_operation_unique
              ON public.bank_receipt_evidence(tenant_id,invalidation_operation_id)
              WHERE invalidation_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX bank_receipt_evidence_effective_source_unique
              ON public.bank_receipt_evidence(tenant_id,receiving_account_id,source_identity_kind,source_identity_version,source_identity)
              WHERE status='effective';

            CREATE TABLE public.receipt_payment_associations (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              association_operation_id char(26) NOT NULL,
              receipt_id char(26) NOT NULL,
              payment_id char(26) NOT NULL,
              associated_amount numeric(19,2) NOT NULL,
              currency varchar(3) NOT NULL,
              associated_by bigint NOT NULL,
              associated_at timestamptz NOT NULL,
              status varchar(16) NOT NULL,
              cancellation_operation_id char(26),
              cancelled_by bigint,
              cancelled_at timestamptz,
              cancellation_reason varchar(500),
              replaces_association_id char(26),
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,
              CONSTRAINT receipt_payment_associations_tenant_id_id_unique UNIQUE (tenant_id,id),
              CONSTRAINT receipt_payment_associations_tenant_operation_unique UNIQUE (tenant_id,association_operation_id),
              CONSTRAINT receipt_payment_associations_receipt_foreign FOREIGN KEY (tenant_id,receipt_id) REFERENCES public.bank_receipt_evidence(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receipt_payment_associations_payment_foreign FOREIGN KEY (tenant_id,payment_id) REFERENCES public.payments(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receipt_payment_associations_actor_foreign FOREIGN KEY (tenant_id,associated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receipt_payment_associations_cancelled_actor_foreign FOREIGN KEY (tenant_id,cancelled_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receipt_payment_associations_replaces_foreign FOREIGN KEY (tenant_id,replaces_association_id) REFERENCES public.receipt_payment_associations(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receipt_payment_associations_amount_check CHECK (associated_amount > 0),
              CONSTRAINT receipt_payment_associations_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
              CONSTRAINT receipt_payment_associations_status_check CHECK (status IN ('effective','cancelled')),
              CONSTRAINT receipt_payment_associations_lifecycle_check CHECK (
                (status='effective' AND cancellation_operation_id IS NULL AND cancelled_by IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                OR (status='cancelled' AND cancellation_operation_id IS NOT NULL AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND btrim(cancellation_reason)<>'')
              )
            );
            CREATE UNIQUE INDEX receipt_payment_associations_cancellation_operation_unique
              ON public.receipt_payment_associations(tenant_id,cancellation_operation_id)
              WHERE cancellation_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX receipt_payment_associations_effective_receipt_unique
              ON public.receipt_payment_associations(tenant_id,receipt_id) WHERE status='effective';
            CREATE UNIQUE INDEX receipt_payment_associations_effective_payment_unique
              ON public.receipt_payment_associations(tenant_id,payment_id) WHERE status='effective';
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_receiving_account_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='approved receiving account deletion is forbidden'; END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.status<>'approved' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receiving account must initially be approved'; END IF;
                RETURN NEW;
              END IF;
              IF (NEW.id,NEW.tenant_id,NEW.receiving_account_operation_id,NEW.institution_identifier,NEW.account_identity,NEW.masked_account_identity,NEW.valid_from,NEW.approved_by,NEW.approved_at,NEW.created_at)
                 IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.receiving_account_operation_id,OLD.institution_identifier,OLD.account_identity,OLD.masked_account_identity,OLD.valid_from,OLD.approved_by,OLD.approved_at,OLD.created_at) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='receiving account canonical history is immutable';
              END IF;
              IF NOT (OLD.status='approved' AND NEW.status='retired') THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='unsupported receiving account lifecycle mutation'; END IF;
              IF EXISTS (SELECT 1 FROM public.bank_receipt_evidence r WHERE r.tenant_id=OLD.tenant_id AND r.receiving_account_id=OLD.id AND r.status='effective' AND r.control_date >= NEW.retired_from) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receiving account retirement would invalidate effective receipt history';
              END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER approved_receiving_accounts_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.approved_receiving_accounts FOR EACH ROW EXECUTE FUNCTION public.enforce_receiving_account_history();

            CREATE OR REPLACE FUNCTION public.enforce_bank_receipt_evidence_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE account_row public.approved_receiving_accounts%ROWTYPE;
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='bank receipt evidence deletion is forbidden'; END IF;
              IF TG_OP='UPDATE' THEN
                IF (NEW.id,NEW.tenant_id,NEW.receipt_operation_id,NEW.receiving_account_id,NEW.channel,NEW.source_identity_kind,NEW.source_identity_version,NEW.source_identity,NEW.amount,NEW.currency,NEW.control_date,NEW.evidence_reference,NEW.evidence_locator,NEW.verification_method,NEW.verified_by,NEW.verified_at,NEW.replaces_receipt_id,NEW.created_at)
                   IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.receipt_operation_id,OLD.receiving_account_id,OLD.channel,OLD.source_identity_kind,OLD.source_identity_version,OLD.source_identity,OLD.amount,OLD.currency,OLD.control_date,OLD.evidence_reference,OLD.evidence_locator,OLD.verification_method,OLD.verified_by,OLD.verified_at,OLD.replaces_receipt_id,OLD.created_at) THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='bank receipt evidence business truth is immutable'; END IF;
                IF NOT (OLD.status='effective' AND NEW.status='invalidated') THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='unsupported bank receipt evidence lifecycle mutation'; END IF;
                IF EXISTS (SELECT 1 FROM public.receipt_payment_associations a WHERE a.tenant_id=OLD.tenant_id AND a.receipt_id=OLD.id AND a.status='effective') THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receipt with effective association cannot be invalidated'; END IF;
                RETURN NEW;
              END IF;
              IF NEW.status<>'effective' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='bank receipt evidence must initially be effective'; END IF;
              SELECT * INTO account_row FROM public.approved_receiving_accounts WHERE (tenant_id,id)=(NEW.tenant_id,NEW.receiving_account_id) FOR UPDATE;
              IF NOT FOUND OR account_row.valid_from > NEW.control_date OR (account_row.retired_from IS NOT NULL AND NEW.control_date >= account_row.retired_from) THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receiving account was not approved at receipt control date'; END IF;
              IF NEW.control_date > CURRENT_DATE THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receipt control date cannot be in the future'; END IF;
              IF NEW.replaces_receipt_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.bank_receipt_evidence r WHERE r.tenant_id=NEW.tenant_id AND r.id=NEW.replaces_receipt_id AND r.status='invalidated') THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='replacement receipt requires invalidated original'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER bank_receipt_evidence_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.bank_receipt_evidence FOR EACH ROW EXECUTE FUNCTION public.enforce_bank_receipt_evidence_history();

            CREATE OR REPLACE FUNCTION public.enforce_receipt_payment_association_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE receipt_row public.bank_receipt_evidence%ROWTYPE; payment_row public.payments%ROWTYPE;
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='receipt payment association deletion is forbidden'; END IF;
              IF TG_OP='UPDATE' THEN
                IF (NEW.id,NEW.tenant_id,NEW.association_operation_id,NEW.receipt_id,NEW.payment_id,NEW.associated_amount,NEW.currency,NEW.associated_by,NEW.associated_at,NEW.replaces_association_id,NEW.created_at)
                   IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.association_operation_id,OLD.receipt_id,OLD.payment_id,OLD.associated_amount,OLD.currency,OLD.associated_by,OLD.associated_at,OLD.replaces_association_id,OLD.created_at) THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='receipt payment association business truth is immutable'; END IF;
                IF NOT (OLD.status='effective' AND NEW.status='cancelled') THEN RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='unsupported receipt payment association lifecycle mutation'; END IF;
                RETURN NEW;
              END IF;
              IF NEW.status<>'effective' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receipt payment association must initially be effective'; END IF;
              SELECT * INTO receipt_row FROM public.bank_receipt_evidence WHERE (tenant_id,id)=(NEW.tenant_id,NEW.receipt_id) FOR UPDATE;
              SELECT * INTO payment_row FROM public.payments WHERE (tenant_id,id)=(NEW.tenant_id,NEW.payment_id) FOR UPDATE;
              IF receipt_row.status<>'effective' OR payment_row.status<>'received' THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receipt and payment are not eligible for association'; END IF;
              IF NEW.associated_amount<>receipt_row.amount OR NEW.associated_amount<>payment_row.amount OR NEW.currency<>receipt_row.currency OR NEW.currency<>payment_row.currency THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='receipt payment association must exactly match receipt and payment'; END IF;
              IF NEW.replaces_association_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM public.receipt_payment_associations a WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.replaces_association_id AND a.status='cancelled') THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='replacement association requires cancelled original'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER receipt_payment_associations_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.receipt_payment_associations FOR EACH ROW EXECUTE FUNCTION public.enforce_receipt_payment_association_history();

            CREATE OR REPLACE FUNCTION public.enforce_payment_receipt_association_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF OLD.status='received' AND NEW.status='cancelled' AND EXISTS (SELECT 1 FROM public.receipt_payment_associations a WHERE a.tenant_id=OLD.tenant_id AND a.payment_id=OLD.id AND a.status='effective') THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='payment with effective receipt association cannot be cancelled'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER payments_receipt_association_guard BEFORE UPDATE ON public.payments FOR EACH ROW EXECUTE FUNCTION public.enforce_payment_receipt_association_guard();

            REVOKE EXECUTE ON FUNCTION public.enforce_receiving_account_history() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.enforce_bank_receipt_evidence_history() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.enforce_receipt_payment_association_history() FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.enforce_payment_receipt_association_guard() FROM PUBLIC;
            SQL);
        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }
        $this->revokeRuntimePrivileges();
        DB::unprepared('DROP TRIGGER IF EXISTS payments_receipt_association_guard ON public.payments; DROP FUNCTION IF EXISTS public.enforce_payment_receipt_association_guard(); DROP TABLE IF EXISTS public.receipt_payment_associations; DROP TABLE IF EXISTS public.bank_receipt_evidence; DROP TABLE IF EXISTS public.approved_receiving_accounts; DROP FUNCTION IF EXISTS public.enforce_receipt_payment_association_history(); DROP FUNCTION IF EXISTS public.enforce_bank_receipt_evidence_history(); DROP FUNCTION IF EXISTS public.enforce_receiving_account_history();');
    }

    private function grantRuntimePrivileges(): void
    {
        if (DB::selectOne('SELECT current_schema() AS name')->name !== 'public') {
            return;
        }

        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! is_string($role) || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new RuntimeException('ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.');
        }

        $runtime = DB::selectOne('SELECT rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?', [$role]);
        if ($runtime === null || $runtime->rolsuper || $runtime->rolcreaterole || $runtime->rolcreatedb || $runtime->rolreplication || $runtime->rolbypassrls) {
            throw new RuntimeException('Receipt Evidence runtime role must be an unprivileged PostgreSQL role.');
        }

        $ownsProtectedObject = (bool) DB::selectOne(<<<'SQL'
            SELECT EXISTS(
              SELECT 1
              FROM pg_catalog.pg_class relation
              JOIN pg_catalog.pg_namespace schema ON schema.oid=relation.relnamespace
              WHERE schema.nspname='public'
                AND relation.relname IN ('approved_receiving_accounts','bank_receipt_evidence','receipt_payment_associations')
                AND pg_catalog.pg_get_userbyid(relation.relowner)=?
            ) AS owns
            SQL, [$role])->owns;
        if ($ownsProtectedObject) {
            throw new RuntimeException('Receipt Evidence runtime role must not own protected objects.');
        }

        $quoted = '"'.str_replace('"', '""', $role).'"';
        DB::unprepared("REVOKE ALL ON TABLE public.approved_receiving_accounts, public.bank_receipt_evidence, public.receipt_payment_associations FROM {$quoted}");
        DB::unprepared("GRANT SELECT, INSERT, UPDATE ON TABLE public.approved_receiving_accounts, public.bank_receipt_evidence, public.receipt_payment_associations TO {$quoted}");
        DB::unprepared("REVOKE EXECUTE ON FUNCTION public.enforce_receiving_account_history() FROM {$quoted}; REVOKE EXECUTE ON FUNCTION public.enforce_bank_receipt_evidence_history() FROM {$quoted}; REVOKE EXECUTE ON FUNCTION public.enforce_receipt_payment_association_history() FROM {$quoted}; REVOKE EXECUTE ON FUNCTION public.enforce_payment_receipt_association_guard() FROM {$quoted}");
    }

    private function revokeRuntimePrivileges(): void
    {
        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (is_string($role) && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            $quoted = '"'.str_replace('"', '""', $role).'"';
            DB::unprepared("REVOKE ALL ON TABLE public.approved_receiving_accounts, public.bank_receipt_evidence, public.receipt_payment_associations FROM {$quoted}");
        }
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema === 'public') {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }
        throw new RuntimeException('Receipt Evidence requires the public PostgreSQL schema.');
    }
};
