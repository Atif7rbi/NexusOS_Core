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
            throw new RuntimeException('Verified Bank Receipt Cash Posting requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO public.accounting_source_types(origin,key,owner_module,description)
            VALUES ('business','bank_receipt_cash_posting','receipt_evidence','Explicit verified bank receipt cash posting');

            CREATE TABLE public.approved_receiving_account_cash_mappings (
              id char(26) PRIMARY KEY, tenant_id char(26) NOT NULL, mapping_operation_id char(26) NOT NULL,
              receiving_account_id char(26) NOT NULL, cash_account_id char(26) NOT NULL,
              replaces_mapping_id char(26), status varchar(16) NOT NULL,
              configured_by bigint NOT NULL, configured_at timestamptz NOT NULL,
              supersession_operation_id char(26), superseded_by bigint, superseded_at timestamptz, supersession_reason varchar(500),
              created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
              CONSTRAINT arcm_tenant_id_unique UNIQUE(tenant_id,id),
              CONSTRAINT arcm_operation_unique UNIQUE(tenant_id,mapping_operation_id),
              CONSTRAINT arcm_tenant_fk FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_receiving_fk FOREIGN KEY(tenant_id,receiving_account_id) REFERENCES public.approved_receiving_accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_account_fk FOREIGN KEY(tenant_id,cash_account_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_replaces_fk FOREIGN KEY(tenant_id,replaces_mapping_id) REFERENCES public.approved_receiving_account_cash_mappings(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_configured_actor_fk FOREIGN KEY(tenant_id,configured_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_superseded_actor_fk FOREIGN KEY(tenant_id,superseded_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT arcm_status_check CHECK(status IN ('effective','superseded')),
              CONSTRAINT arcm_lifecycle_check CHECK(
                (status='effective' AND supersession_operation_id IS NULL AND superseded_by IS NULL AND superseded_at IS NULL AND supersession_reason IS NULL) OR
                (status='superseded' AND supersession_operation_id IS NOT NULL AND superseded_by IS NOT NULL AND superseded_at IS NOT NULL AND btrim(supersession_reason)<>'')
              )
            );
            CREATE UNIQUE INDEX arcm_supersession_operation_unique ON public.approved_receiving_account_cash_mappings(tenant_id,supersession_operation_id) WHERE supersession_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX arcm_effective_receiving_unique ON public.approved_receiving_account_cash_mappings(tenant_id,receiving_account_id) WHERE status='effective';

            CREATE TABLE public.bank_receipt_cash_clearing_policies (
              id char(26) PRIMARY KEY, tenant_id char(26) NOT NULL, policy_operation_id char(26) NOT NULL,
              clearing_account_id char(26) NOT NULL, replaces_policy_id char(26), status varchar(16) NOT NULL,
              configured_by bigint NOT NULL, configured_at timestamptz NOT NULL,
              supersession_operation_id char(26), superseded_by bigint, superseded_at timestamptz, supersession_reason varchar(500),
              created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
              CONSTRAINT brccp_tenant_id_unique UNIQUE(tenant_id,id),
              CONSTRAINT brccp_operation_unique UNIQUE(tenant_id,policy_operation_id),
              CONSTRAINT brccp_tenant_fk FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brccp_account_fk FOREIGN KEY(tenant_id,clearing_account_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brccp_replaces_fk FOREIGN KEY(tenant_id,replaces_policy_id) REFERENCES public.bank_receipt_cash_clearing_policies(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brccp_configured_actor_fk FOREIGN KEY(tenant_id,configured_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brccp_superseded_actor_fk FOREIGN KEY(tenant_id,superseded_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brccp_status_check CHECK(status IN ('effective','superseded')),
              CONSTRAINT brccp_lifecycle_check CHECK(
                (status='effective' AND supersession_operation_id IS NULL AND superseded_by IS NULL AND superseded_at IS NULL AND supersession_reason IS NULL) OR
                (status='superseded' AND supersession_operation_id IS NOT NULL AND superseded_by IS NOT NULL AND superseded_at IS NOT NULL AND btrim(supersession_reason)<>'')
              )
            );
            CREATE UNIQUE INDEX brccp_supersession_operation_unique ON public.bank_receipt_cash_clearing_policies(tenant_id,supersession_operation_id) WHERE supersession_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX brccp_effective_tenant_unique ON public.bank_receipt_cash_clearing_policies(tenant_id) WHERE status='effective';

            CREATE TABLE public.bank_receipt_cash_postings (
              id char(26) PRIMARY KEY, tenant_id char(26) NOT NULL, posting_operation_id char(26) NOT NULL, receipt_id char(26) NOT NULL,
              cash_mapping_id char(26) NOT NULL, cash_policy_id char(26) NOT NULL, receiving_account_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL, currency varchar(3) NOT NULL, accounting_date date NOT NULL,
              cash_account_id char(26) NOT NULL, clearing_account_id char(26) NOT NULL, status varchar(16) NOT NULL,
              journal_entry_id char(26) NOT NULL, posted_by bigint NOT NULL, posted_at timestamptz NOT NULL,
              reversal_operation_id char(26), reversal_journal_entry_id char(26), reversal_date date, reversal_reason varchar(500), reversed_by bigint, reversed_at timestamptz,
              created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
              CONSTRAINT brcp_tenant_id_unique UNIQUE(tenant_id,id),
              CONSTRAINT brcp_posting_operation_unique UNIQUE(tenant_id,posting_operation_id),
              CONSTRAINT brcp_receipt_unique UNIQUE(tenant_id,receipt_id),
              CONSTRAINT brcp_journal_unique UNIQUE(tenant_id,journal_entry_id),
              CONSTRAINT brcp_tenant_fk FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_receipt_fk FOREIGN KEY(tenant_id,receipt_id) REFERENCES public.bank_receipt_evidence(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_mapping_fk FOREIGN KEY(tenant_id,cash_mapping_id) REFERENCES public.approved_receiving_account_cash_mappings(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_policy_fk FOREIGN KEY(tenant_id,cash_policy_id) REFERENCES public.bank_receipt_cash_clearing_policies(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_receiving_fk FOREIGN KEY(tenant_id,receiving_account_id) REFERENCES public.approved_receiving_accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_cash_account_fk FOREIGN KEY(tenant_id,cash_account_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_clearing_account_fk FOREIGN KEY(tenant_id,clearing_account_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_journal_fk FOREIGN KEY(tenant_id,journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_reversal_journal_fk FOREIGN KEY(tenant_id,reversal_journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_posted_actor_fk FOREIGN KEY(tenant_id,posted_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_reversed_actor_fk FOREIGN KEY(tenant_id,reversed_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT brcp_amount_check CHECK(amount>0), CONSTRAINT brcp_currency_check CHECK(currency='SAR'),
              CONSTRAINT brcp_status_check CHECK(status IN ('posted','reversed')),
              CONSTRAINT brcp_lifecycle_check CHECK(
                (status='posted' AND reversal_operation_id IS NULL AND reversal_journal_entry_id IS NULL AND reversal_date IS NULL AND reversal_reason IS NULL AND reversed_by IS NULL AND reversed_at IS NULL) OR
                (status='reversed' AND reversal_operation_id IS NOT NULL AND reversal_journal_entry_id IS NOT NULL AND reversal_date IS NOT NULL AND btrim(reversal_reason)<>'' AND reversed_by IS NOT NULL AND reversed_at IS NOT NULL)
              )
            );
            CREATE UNIQUE INDEX brcp_reversal_operation_unique ON public.bank_receipt_cash_postings(tenant_id,reversal_operation_id) WHERE reversal_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX brcp_reversal_journal_unique ON public.bank_receipt_cash_postings(tenant_id,reversal_journal_entry_id) WHERE reversal_journal_entry_id IS NOT NULL;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_receiving_account_cash_mapping_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE a public.accounts%ROWTYPE;
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='cash mapping deletion is forbidden'; END IF;
              SELECT * INTO a FROM public.accounts WHERE (tenant_id,id)=(NEW.tenant_id,NEW.cash_account_id) FOR KEY SHARE;
              IF NOT FOUND OR a.status<>'active' OR a.kind<>'posting' OR a.account_type<>'asset' OR a.classification<>'current_asset' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash mapping requires an active current asset posting account'; END IF;
              IF TG_OP='INSERT' THEN IF NEW.status<>'effective' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash mapping must initially be effective'; END IF; RETURN NEW; END IF;
              IF (NEW.id,NEW.tenant_id,NEW.mapping_operation_id,NEW.receiving_account_id,NEW.cash_account_id,NEW.replaces_mapping_id,NEW.configured_by,NEW.configured_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.mapping_operation_id,OLD.receiving_account_id,OLD.cash_account_id,OLD.replaces_mapping_id,OLD.configured_by,OLD.configured_at,OLD.created_at) THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='cash mapping canonical history is immutable'; END IF;
              IF NOT (OLD.status='effective' AND NEW.status='superseded') THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported cash mapping lifecycle mutation'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER arcm_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.approved_receiving_account_cash_mappings FOR EACH ROW EXECUTE FUNCTION public.enforce_receiving_account_cash_mapping_history();

            CREATE OR REPLACE FUNCTION public.enforce_cash_clearing_policy_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE a public.accounts%ROWTYPE;
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='cash clearing policy deletion is forbidden'; END IF;
              SELECT * INTO a FROM public.accounts WHERE (tenant_id,id)=(NEW.tenant_id,NEW.clearing_account_id) FOR KEY SHARE;
              IF NOT FOUND OR a.status<>'active' OR a.kind<>'posting' OR a.account_type<>'liability' OR a.classification<>'current_liability' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash clearing policy requires an active current liability posting account'; END IF;
              IF TG_OP='INSERT' THEN IF NEW.status<>'effective' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash clearing policy must initially be effective'; END IF; RETURN NEW; END IF;
              IF (NEW.id,NEW.tenant_id,NEW.policy_operation_id,NEW.clearing_account_id,NEW.replaces_policy_id,NEW.configured_by,NEW.configured_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.policy_operation_id,OLD.clearing_account_id,OLD.replaces_policy_id,OLD.configured_by,OLD.configured_at,OLD.created_at) THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='cash clearing policy canonical history is immutable'; END IF;
              IF NOT (OLD.status='effective' AND NEW.status='superseded') THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported cash clearing policy lifecycle mutation'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER brccp_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.bank_receipt_cash_clearing_policies FOR EACH ROW EXECUTE FUNCTION public.enforce_cash_clearing_policy_history();

            CREATE OR REPLACE FUNCTION public.validate_receiving_account_cash_mapping_final() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF NEW.status='superseded' AND NOT EXISTS(SELECT 1 FROM public.approved_receiving_account_cash_mappings s WHERE s.tenant_id=NEW.tenant_id AND s.replaces_mapping_id=NEW.id AND s.status='effective' AND s.mapping_operation_id=NEW.supersession_operation_id AND s.receiving_account_id=NEW.receiving_account_id) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash mapping supersession chain is incoherent'; END IF;
              IF NEW.status='effective' AND NEW.replaces_mapping_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.approved_receiving_account_cash_mappings p WHERE p.tenant_id=NEW.tenant_id AND p.id=NEW.replaces_mapping_id AND p.status='superseded' AND p.supersession_operation_id=NEW.mapping_operation_id AND p.receiving_account_id=NEW.receiving_account_id) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash mapping successor is incoherent'; END IF;
              RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER arcm_final_guard AFTER INSERT OR UPDATE ON public.approved_receiving_account_cash_mappings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.validate_receiving_account_cash_mapping_final();

            CREATE OR REPLACE FUNCTION public.validate_cash_clearing_policy_final() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF NEW.status='superseded' AND NOT EXISTS(SELECT 1 FROM public.bank_receipt_cash_clearing_policies s WHERE s.tenant_id=NEW.tenant_id AND s.replaces_policy_id=NEW.id AND s.status='effective' AND s.policy_operation_id=NEW.supersession_operation_id) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash clearing policy supersession chain is incoherent'; END IF;
              IF NEW.status='effective' AND NEW.replaces_policy_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.bank_receipt_cash_clearing_policies p WHERE p.tenant_id=NEW.tenant_id AND p.id=NEW.replaces_policy_id AND p.status='superseded' AND p.supersession_operation_id=NEW.policy_operation_id) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash clearing policy successor is incoherent'; END IF;
              RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER brccp_final_guard AFTER INSERT OR UPDATE ON public.bank_receipt_cash_clearing_policies DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.validate_cash_clearing_policy_final();

            CREATE OR REPLACE FUNCTION public.enforce_bank_receipt_cash_posting_history() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='bank receipt cash posting deletion is forbidden'; END IF;
              IF TG_OP='INSERT' THEN IF NEW.status<>'posted' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash posting must initially be posted'; END IF; RETURN NEW; END IF;
              IF (NEW.id,NEW.tenant_id,NEW.posting_operation_id,NEW.receipt_id,NEW.cash_mapping_id,NEW.cash_policy_id,NEW.receiving_account_id,NEW.amount,NEW.currency,NEW.accounting_date,NEW.cash_account_id,NEW.clearing_account_id,NEW.posted_by,NEW.posted_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.posting_operation_id,OLD.receipt_id,OLD.cash_mapping_id,OLD.cash_policy_id,OLD.receiving_account_id,OLD.amount,OLD.currency,OLD.accounting_date,OLD.cash_account_id,OLD.clearing_account_id,OLD.posted_by,OLD.posted_at,OLD.created_at) OR (OLD.journal_entry_id IS NOT NULL AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id) OR (OLD.journal_entry_id IS NULL AND NEW.journal_entry_id IS NULL) THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='bank receipt cash posting canonical history is immutable'; END IF;
              IF NOT (OLD.status='posted' AND NEW.status='reversed') THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported cash posting lifecycle mutation'; END IF;
              RETURN NEW;
            END; $$;
            CREATE TRIGGER brcp_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.bank_receipt_cash_postings FOR EACH ROW EXECUTE FUNCTION public.enforce_bank_receipt_cash_posting_history();

            CREATE OR REPLACE FUNCTION public.validate_bank_receipt_cash_posting_final() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE r record; m record; p record; j record; l_count integer; total_debit numeric; total_credit numeric;
            BEGIN
              SELECT * INTO r FROM public.bank_receipt_evidence WHERE (tenant_id,id)=(NEW.tenant_id,NEW.receipt_id);
              SELECT * INTO m FROM public.approved_receiving_account_cash_mappings WHERE (tenant_id,id)=(NEW.tenant_id,NEW.cash_mapping_id);
              SELECT * INTO p FROM public.bank_receipt_cash_clearing_policies WHERE (tenant_id,id)=(NEW.tenant_id,NEW.cash_policy_id);
              IF NOT FOUND OR (NEW.status='posted' AND r.status<>'effective') OR (NEW.status='reversed' AND r.status NOT IN ('effective','invalidated')) OR r.channel<>'bank_transfer' OR r.currency<>'SAR' OR NEW.receiving_account_id<>r.receiving_account_id OR NEW.amount<>r.amount OR NEW.currency<>r.currency OR NEW.accounting_date<>r.control_date OR m.receiving_account_id<>r.receiving_account_id OR m.cash_account_id<>NEW.cash_account_id OR p.clearing_account_id<>NEW.clearing_account_id THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash posting receipt or mapping facts are inconsistent'; END IF;
              SELECT * INTO j FROM public.journal_entries WHERE (tenant_id,id)=(NEW.tenant_id,NEW.journal_entry_id);
              SELECT count(*),coalesce(sum(jl.debit),0),coalesce(sum(jl.credit),0) INTO l_count,total_debit,total_credit FROM public.journal_lines jl WHERE jl.tenant_id=NEW.tenant_id AND jl.journal_entry_id=NEW.journal_entry_id;
              IF j.status<>'posted' OR j.origin<>'business' OR j.source_type<>'bank_receipt_cash_posting' OR j.source_id<>NEW.id OR j.entry_date<>NEW.accounting_date OR l_count<>2 OR total_debit<>NEW.amount OR total_credit<>NEW.amount OR NOT EXISTS(SELECT 1 FROM public.journal_lines WHERE tenant_id=NEW.tenant_id AND journal_entry_id=NEW.journal_entry_id AND line_number=1 AND account_id=NEW.cash_account_id AND debit=NEW.amount AND credit=0) OR NOT EXISTS(SELECT 1 FROM public.journal_lines WHERE tenant_id=NEW.tenant_id AND journal_entry_id=NEW.journal_entry_id AND line_number=2 AND account_id=NEW.clearing_account_id AND debit=0 AND credit=NEW.amount) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash posting journal is inconsistent'; END IF;
              IF NEW.status='reversed' AND NOT EXISTS(SELECT 1 FROM public.journal_entries x WHERE (x.tenant_id,x.id)=(NEW.tenant_id,NEW.reversal_journal_entry_id) AND x.status='posted' AND x.origin='reversal' AND x.reverses_journal_entry_id=NEW.journal_entry_id AND x.entry_date=NEW.reversal_date) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='cash posting reversal journal is inconsistent'; END IF;
              RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER brcp_final_guard AFTER INSERT OR UPDATE ON public.bank_receipt_cash_postings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.validate_bank_receipt_cash_posting_final();

            CREATE OR REPLACE FUNCTION public.prevent_receipt_invalidation_with_posted_cash() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN IF OLD.status='effective' AND NEW.status='invalidated' AND EXISTS(SELECT 1 FROM public.bank_receipt_cash_postings p WHERE p.tenant_id=OLD.tenant_id AND p.receipt_id=OLD.id AND p.status='posted') THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receipt with posted cash integration cannot be invalidated'; END IF; RETURN NEW; END; $$;
            CREATE TRIGGER bank_receipt_cash_invalidation_guard BEFORE UPDATE ON public.bank_receipt_evidence FOR EACH ROW EXECUTE FUNCTION public.prevent_receipt_invalidation_with_posted_cash();

            REVOKE EXECUTE ON FUNCTION public.enforce_receiving_account_cash_mapping_history(),public.enforce_cash_clearing_policy_history(),public.validate_receiving_account_cash_mapping_final(),public.validate_cash_clearing_policy_final(),public.enforce_bank_receipt_cash_posting_history(),public.validate_bank_receipt_cash_posting_final(),public.prevent_receipt_invalidation_with_posted_cash() FROM PUBLIC;
            SQL);
        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }
        $this->revokeRuntimePrivileges();
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_receipt_cash_invalidation_guard ON public.bank_receipt_evidence;
            DROP FUNCTION IF EXISTS public.prevent_receipt_invalidation_with_posted_cash();
            DROP TRIGGER IF EXISTS brcp_final_guard ON public.bank_receipt_cash_postings;
            DROP FUNCTION IF EXISTS public.validate_bank_receipt_cash_posting_final();
            DROP TRIGGER IF EXISTS brcp_history_guard ON public.bank_receipt_cash_postings;
            DROP FUNCTION IF EXISTS public.enforce_bank_receipt_cash_posting_history();
            DROP TRIGGER IF EXISTS brccp_final_guard ON public.bank_receipt_cash_clearing_policies;
            DROP FUNCTION IF EXISTS public.validate_cash_clearing_policy_final();
            DROP TRIGGER IF EXISTS brccp_history_guard ON public.bank_receipt_cash_clearing_policies;
            DROP FUNCTION IF EXISTS public.enforce_cash_clearing_policy_history();
            DROP TRIGGER IF EXISTS arcm_final_guard ON public.approved_receiving_account_cash_mappings;
            DROP FUNCTION IF EXISTS public.validate_receiving_account_cash_mapping_final();
            DROP TRIGGER IF EXISTS arcm_history_guard ON public.approved_receiving_account_cash_mappings;
            DROP FUNCTION IF EXISTS public.enforce_receiving_account_cash_mapping_history();
            DROP TABLE public.bank_receipt_cash_postings;
            DROP TABLE public.bank_receipt_cash_clearing_policies;
            DROP TABLE public.approved_receiving_account_cash_mappings;
            ALTER TABLE public.accounting_source_types DISABLE TRIGGER accounting_source_types_immutable_delete;
            DELETE FROM public.accounting_source_types WHERE origin='business' AND key='bank_receipt_cash_posting';
            ALTER TABLE public.accounting_source_types ENABLE TRIGGER accounting_source_types_immutable_delete;
            SQL);
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
        $quoted = '"'.str_replace('"', '""', $role).'"';
        DB::unprepared("REVOKE ALL ON TABLE public.approved_receiving_account_cash_mappings,public.bank_receipt_cash_clearing_policies,public.bank_receipt_cash_postings FROM {$quoted}; GRANT SELECT,INSERT,UPDATE ON TABLE public.approved_receiving_account_cash_mappings,public.bank_receipt_cash_clearing_policies,public.bank_receipt_cash_postings TO {$quoted}; REVOKE EXECUTE ON FUNCTION public.enforce_receiving_account_cash_mapping_history(),public.enforce_cash_clearing_policy_history(),public.validate_receiving_account_cash_mapping_final(),public.validate_cash_clearing_policy_final(),public.enforce_bank_receipt_cash_posting_history(),public.validate_bank_receipt_cash_posting_final(),public.prevent_receipt_invalidation_with_posted_cash() FROM {$quoted}");
    }

    private function revokeRuntimePrivileges(): void
    {
        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (is_string($role) && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            $quoted = '"'.str_replace('"', '""', $role).'"';
            DB::unprepared("REVOKE ALL ON TABLE public.approved_receiving_account_cash_mappings,public.bank_receipt_cash_clearing_policies,public.bank_receipt_cash_postings FROM {$quoted}");
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
        throw new RuntimeException('Verified Bank Receipt Cash Posting requires the public PostgreSQL schema.');
    }
};
