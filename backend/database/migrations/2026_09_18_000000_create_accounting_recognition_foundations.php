<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Accounting Recognition Foundations v1 requires PostgreSQL.');
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.receivable_ar_policies (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              policy_version integer NOT NULL CHECK (policy_version > 0),
              status text NOT NULL CHECK (status IN ('active','superseded')),
              ar_control_account_id char(26) NOT NULL,
              effective_from date NOT NULL,
              effective_to date,
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              superseded_by bigint,
              superseded_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,policy_version),
              FOREIGN KEY (tenant_id) REFERENCES public.accounting_settings(tenant_id),
              FOREIGN KEY (tenant_id,ar_control_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,superseded_by) REFERENCES public.tenant_users(tenant_id,user_id),
              CHECK (
                (status='active' AND effective_to IS NULL AND superseded_by IS NULL AND superseded_at IS NULL)
                OR
                (status='superseded' AND effective_to IS NOT NULL AND effective_to >= effective_from
                  AND superseded_by IS NOT NULL AND superseded_at IS NOT NULL)
              )
            );
            CREATE UNIQUE INDEX receivable_ar_policies_one_active
              ON public.receivable_ar_policies(tenant_id) WHERE status='active';

            CREATE TABLE public.contract_consideration_accounting_policies (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              policy_version integer NOT NULL CHECK (policy_version > 0),
              status text NOT NULL CHECK (status IN ('active','superseded')),
              contract_asset_control_account_id char(26) NOT NULL,
              contract_liability_control_account_id char(26) NOT NULL,
              effective_from date NOT NULL,
              effective_to date,
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              superseded_by bigint,
              superseded_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,policy_version),
              FOREIGN KEY (tenant_id) REFERENCES public.accounting_settings(tenant_id),
              FOREIGN KEY (tenant_id,contract_asset_control_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,contract_liability_control_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,superseded_by) REFERENCES public.tenant_users(tenant_id,user_id),
              CHECK (
                (status='active' AND effective_to IS NULL AND superseded_by IS NULL AND superseded_at IS NULL)
                OR
                (status='superseded' AND effective_to IS NOT NULL AND effective_to >= effective_from
                  AND superseded_by IS NOT NULL AND superseded_at IS NOT NULL)
              )
            );
            CREATE UNIQUE INDEX contract_consideration_accounting_policies_one_active
              ON public.contract_consideration_accounting_policies(tenant_id) WHERE status='active';

            CREATE TABLE public.performance_accounting_policies (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              policy_version integer NOT NULL CHECK (policy_version > 0),
              status text NOT NULL CHECK (status IN ('active','superseded')),
              revenue_account_id char(26) NOT NULL,
              contract_asset_control_account_id char(26) NOT NULL,
              contract_liability_control_account_id char(26) NOT NULL,
              effective_from date NOT NULL,
              effective_to date,
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              superseded_by bigint,
              superseded_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,policy_version),
              FOREIGN KEY (tenant_id) REFERENCES public.accounting_settings(tenant_id),
              FOREIGN KEY (tenant_id,revenue_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,contract_asset_control_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,contract_liability_control_account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,superseded_by) REFERENCES public.tenant_users(tenant_id,user_id),
              CHECK (
                (status='active' AND effective_to IS NULL AND superseded_by IS NULL AND superseded_at IS NULL)
                OR
                (status='superseded' AND effective_to IS NOT NULL AND effective_to >= effective_from
                  AND superseded_by IS NOT NULL AND superseded_at IS NOT NULL)
              )
            );
            CREATE UNIQUE INDEX performance_accounting_policies_one_active
              ON public.performance_accounting_policies(tenant_id) WHERE status='active';

            CREATE TABLE public.performance_accounting_adoptions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              consideration_position_id char(26) NOT NULL,
              performance_accounting_adoption_operation_id char(26) NOT NULL
                CHECK (performance_accounting_adoption_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              status text NOT NULL CHECK (status='ADOPTED'),
              accounting_scope_version text NOT NULL CHECK (accounting_scope_version='PERFORMANCE_ACCOUNTING_V1'),
              adoption_basis text NOT NULL CHECK (adoption_basis='CLEAN_PROTOCOL_READY'),
              consideration_amount numeric(19,2) NOT NULL CHECK (consideration_amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              adopted_by bigint NOT NULL,
              adopted_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,contract_id),
              UNIQUE (tenant_id,performance_accounting_adoption_operation_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,consideration_position_id) REFERENCES public.contract_consideration_positions(tenant_id,id),
              FOREIGN KEY (tenant_id,adopted_by) REFERENCES public.tenant_users(tenant_id,user_id)
            );

            CREATE TABLE public.accounting_position_origins (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              position_type text NOT NULL CHECK (position_type IN ('CONTRACT_ASSET','CONTRACT_LIABILITY')),
              origin_recognition_type text NOT NULL
                CHECK (origin_recognition_type IN ('PERFORMANCE_ACCOUNTING_RECOGNITION','RECEIVABLE_AR_RECOGNITION')),
              origin_recognition_id char(26) NOT NULL CHECK (origin_recognition_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              origin_journal_entry_id char(26) NOT NULL,
              account_id char(26) NOT NULL,
              economic_source_type text NOT NULL
                CHECK (economic_source_type IN ('UNIT_HANDOVER_ACCEPTANCE','CONTRACTUAL_BILLING_ENTITLEMENT')),
              economic_source_id char(26) NOT NULL CHECK (economic_source_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              consideration_transition_id char(26) NOT NULL,
              consideration_lot_id char(26) NOT NULL,
              economic_leg_identity text NOT NULL CHECK (btrim(economic_leg_identity) <> ''),
              origin_amount numeric(19,2) NOT NULL CHECK (origin_amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              accounting_date date NOT NULL,
              status text NOT NULL CHECK (status IN ('effective','reversed')),
              created_at timestamptz NOT NULL,
              reversal_origin_operation_id char(26),
              reversed_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,origin_recognition_type,origin_recognition_id,consideration_lot_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,origin_journal_entry_id) REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,account_id) REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,consideration_transition_id) REFERENCES public.contract_consideration_transitions(tenant_id,id),
              FOREIGN KEY (tenant_id,consideration_lot_id) REFERENCES public.contract_consideration_lots(tenant_id,id),
              CHECK (
                (position_type='CONTRACT_ASSET' AND origin_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                  AND economic_source_type='UNIT_HANDOVER_ACCEPTANCE')
                OR
                (position_type='CONTRACT_LIABILITY' AND origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                  AND economic_source_type='CONTRACTUAL_BILLING_ENTITLEMENT')
              ),
              CHECK (
                (status='effective' AND reversal_origin_operation_id IS NULL AND reversed_at IS NULL)
                OR
                (status='reversed' AND reversal_origin_operation_id IS NOT NULL
                  AND reversal_origin_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                  AND reversed_at IS NOT NULL)
              )
            );
            CREATE INDEX accounting_position_origins_capacity
              ON public.accounting_position_origins(tenant_id,contract_id,position_type,status,accounting_date,id);

            CREATE TABLE public.accounting_position_consumptions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              origin_id char(26) NOT NULL,
              consuming_recognition_type text NOT NULL
                CHECK (consuming_recognition_type IN ('PERFORMANCE_ACCOUNTING_RECOGNITION','RECEIVABLE_AR_RECOGNITION')),
              consuming_recognition_id char(26) NOT NULL CHECK (consuming_recognition_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              consuming_journal_entry_id char(26) NOT NULL,
              consideration_transition_id char(26) NOT NULL,
              consideration_lot_id char(26) NOT NULL,
              economic_leg_identity text NOT NULL CHECK (btrim(economic_leg_identity) <> ''),
              amount numeric(19,2) NOT NULL CHECK (amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              status text NOT NULL CHECK (status IN ('effective','reversed')),
              created_at timestamptz NOT NULL,
              reversal_operation_id char(26),
              reversed_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,origin_id,consuming_recognition_type,consuming_recognition_id,consideration_lot_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,origin_id) REFERENCES public.accounting_position_origins(tenant_id,id),
              FOREIGN KEY (tenant_id,consuming_journal_entry_id) REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,consideration_transition_id) REFERENCES public.contract_consideration_transitions(tenant_id,id),
              FOREIGN KEY (tenant_id,consideration_lot_id) REFERENCES public.contract_consideration_lots(tenant_id,id),
              CHECK (
                (status='effective' AND reversal_operation_id IS NULL AND reversed_at IS NULL)
                OR
                (status='reversed' AND reversal_operation_id IS NOT NULL
                  AND reversal_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                  AND reversed_at IS NOT NULL)
              )
            );
            CREATE INDEX accounting_position_consumptions_origin
              ON public.accounting_position_consumptions(tenant_id,origin_id,status,id);

            CREATE TABLE public.accounting_position_origin_journal_line_allocations (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              origin_id char(26) NOT NULL,
              journal_entry_id char(26) NOT NULL,
              journal_line_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL CHECK (amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              economic_leg_identity text NOT NULL CHECK (btrim(economic_leg_identity) <> ''),
              created_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,origin_id,journal_line_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,origin_id) REFERENCES public.accounting_position_origins(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_entry_id) REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_line_id) REFERENCES public.journal_lines(tenant_id,id)
            );

            CREATE TABLE public.accounting_position_consumption_journal_line_allocations (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              consumption_id char(26) NOT NULL,
              journal_entry_id char(26) NOT NULL,
              journal_line_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL CHECK (amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              economic_leg_identity text NOT NULL CHECK (btrim(economic_leg_identity) <> ''),
              created_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,consumption_id,journal_line_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,consumption_id) REFERENCES public.accounting_position_consumptions(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_entry_id) REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_line_id) REFERENCES public.journal_lines(tenant_id,id)
            );

            CREATE OR REPLACE FUNCTION public.accounting_recognition_policy_account_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              a public.accounts%ROWTYPE;
              asset_id char(26);
              liability_id char(26);
              revenue_id char(26);
              ar_id char(26);
            BEGIN
              IF TG_TABLE_NAME='receivable_ar_policies' THEN
                ar_id := NEW.ar_control_account_id;
              ELSIF TG_TABLE_NAME='contract_consideration_accounting_policies' THEN
                asset_id := NEW.contract_asset_control_account_id;
                liability_id := NEW.contract_liability_control_account_id;
              ELSIF TG_TABLE_NAME='performance_accounting_policies' THEN
                asset_id := NEW.contract_asset_control_account_id;
                liability_id := NEW.contract_liability_control_account_id;
                revenue_id := NEW.revenue_account_id;
              END IF;

              IF ar_id IS NOT NULL THEN
                SELECT * INTO a FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=ar_id FOR UPDATE;
                IF NOT FOUND OR a.kind<>'posting' OR a.status<>'active' OR a.account_type<>'asset' THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='ACCOUNTS_RECEIVABLE_CONTROL requires an active posting asset account';
                END IF;
              END IF;
              IF asset_id IS NOT NULL THEN
                SELECT * INTO a FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=asset_id FOR UPDATE;
                IF NOT FOUND OR a.kind<>'posting' OR a.status<>'active' OR a.account_type<>'asset' THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='CONTRACT_ASSET_CONTROL requires an active posting asset account';
                END IF;
              END IF;
              IF liability_id IS NOT NULL THEN
                SELECT * INTO a FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=liability_id FOR UPDATE;
                IF NOT FOUND OR a.kind<>'posting' OR a.status<>'active' OR a.account_type<>'liability' THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='CONTRACT_LIABILITY_CONTROL requires an active posting liability account';
                END IF;
              END IF;
              IF revenue_id IS NOT NULL THEN
                SELECT * INTO a FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=revenue_id FOR UPDATE;
                IF NOT FOUND OR a.kind<>'posting' OR a.status<>'active' OR a.account_type<>'revenue' THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='REVENUE requires an active posting revenue account';
                END IF;
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER receivable_ar_policy_account_guard
              BEFORE INSERT OR UPDATE OF ar_control_account_id ON public.receivable_ar_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_account_guard();
            CREATE TRIGGER contract_consideration_policy_account_guard
              BEFORE INSERT OR UPDATE OF contract_asset_control_account_id,contract_liability_control_account_id
              ON public.contract_consideration_accounting_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_account_guard();
            CREATE TRIGGER performance_policy_account_guard
              BEFORE INSERT OR UPDATE OF revenue_account_id,contract_asset_control_account_id,contract_liability_control_account_id
              ON public.performance_accounting_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_account_guard();

            CREATE OR REPLACE FUNCTION public.accounting_recognition_policy_history_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting recognition policy history cannot be deleted';
              END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.status<>'active' OR NEW.effective_to IS NOT NULL OR NEW.superseded_by IS NOT NULL OR NEW.superseded_at IS NOT NULL THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='A policy version must start active and open-ended';
                END IF;
                RETURN NEW;
              END IF;
              IF OLD.status<>'active' OR NEW.status<>'superseded'
                 OR NEW.effective_to IS NULL OR NEW.effective_to < OLD.effective_from
                 OR NEW.superseded_by IS NULL OR NEW.superseded_at IS NULL
                 OR (to_jsonb(NEW) - ARRAY['status','effective_to','superseded_by','superseded_at'])
                    IS DISTINCT FROM
                    (to_jsonb(OLD) - ARRAY['status','effective_to','superseded_by','superseded_at']) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting recognition policy canonical history is immutable';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER receivable_ar_policy_history_guard
              BEFORE INSERT OR UPDATE OR DELETE ON public.receivable_ar_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_history_guard();
            CREATE TRIGGER contract_consideration_policy_history_guard
              BEFORE INSERT OR UPDATE OR DELETE ON public.contract_consideration_accounting_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_history_guard();
            CREATE TRIGGER performance_policy_history_guard
              BEFORE INSERT OR UPDATE OR DELETE ON public.performance_accounting_policies
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_policy_history_guard();

            CREATE OR REPLACE FUNCTION public.validate_accounting_recognition_policy_history(
              p_table text,
              p_tenant char(26)
            ) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $
            DECLARE
              total_count integer;
              active_count integer;
              valid_history boolean;
            BEGIN
              IF p_table NOT IN (
                'receivable_ar_policies',
                'contract_consideration_accounting_policies',
                'performance_accounting_policies'
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Unsupported Accounting Recognition policy family';
              END IF;

              EXECUTE format(
                'SELECT count(*), count(*) FILTER (WHERE status=''active'') FROM public.%I WHERE tenant_id=$1',
                p_table
              ) INTO total_count,active_count USING p_tenant;

              IF total_count=0 THEN
                RETURN;
              END IF;
              IF active_count<>1 THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting Recognition policy history requires exactly one active version';
              END IF;

              EXECUTE format(
                'SELECT bool_and(policy_version=rn AND effective_from>COALESCE(previous_from,DATE ''1999-12-31'') AND
                  ((next_from IS NULL AND status=''active'' AND effective_to IS NULL)
                   OR
                   (next_from IS NOT NULL AND status=''superseded'' AND effective_to=next_from-1)))
                 FROM (
                   SELECT policy_version,status,effective_from,effective_to,
                     row_number() OVER (ORDER BY policy_version) AS rn,
                     lag(effective_from) OVER (ORDER BY policy_version) AS previous_from,
                     lead(effective_from) OVER (ORDER BY policy_version) AS next_from
                   FROM public.%I
                   WHERE tenant_id=$1
                 ) history',
                p_table
              ) INTO valid_history USING p_tenant;

              IF valid_history IS DISTINCT FROM true THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting Recognition policy versions or effective windows are inconsistent';
              END IF;
            END $;

            CREATE OR REPLACE FUNCTION public.accounting_recognition_policy_final_state() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $
            BEGIN
              PERFORM public.validate_accounting_recognition_policy_history(
                TG_TABLE_NAME,
                COALESCE(NEW.tenant_id,OLD.tenant_id)
              );
              RETURN NULL;
            END $;

            CREATE CONSTRAINT TRIGGER receivable_ar_policy_final
              AFTER INSERT OR UPDATE OR DELETE ON public.receivable_ar_policies
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.accounting_recognition_policy_final_state();
            CREATE CONSTRAINT TRIGGER contract_consideration_policy_final
              AFTER INSERT OR UPDATE OR DELETE ON public.contract_consideration_accounting_policies
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.accounting_recognition_policy_final_state();
            CREATE CONSTRAINT TRIGGER performance_policy_final
              AFTER INSERT OR UPDATE OR DELETE ON public.performance_accounting_policies
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.accounting_recognition_policy_final_state();

            CREATE OR REPLACE FUNCTION public.accounting_recognition_immutable_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting recognition foundation history is immutable';
            END $$;

            CREATE TRIGGER performance_accounting_adoption_immutable
              BEFORE UPDATE OR DELETE ON public.performance_accounting_adoptions
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_immutable_history();
            CREATE TRIGGER accounting_position_origin_allocation_immutable
              BEFORE UPDATE OR DELETE ON public.accounting_position_origin_journal_line_allocations
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_immutable_history();
            CREATE TRIGGER accounting_position_consumption_allocation_immutable
              BEFORE UPDATE OR DELETE ON public.accounting_position_consumption_journal_line_allocations
              FOR EACH ROW EXECUTE FUNCTION public.accounting_recognition_immutable_history();

            CREATE OR REPLACE FUNCTION public.accounting_position_origin_history_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting position origin deletion is forbidden';
              END IF;
              IF TG_OP='INSERT' AND NEW.status<>'effective' THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position origin must start effective';
              END IF;
              IF TG_OP='UPDATE' AND (
                OLD.status<>'effective' OR NEW.status<>'reversed'
                OR (to_jsonb(NEW) - ARRAY['status','reversal_origin_operation_id','reversed_at']) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status','reversal_origin_operation_id','reversed_at'])
              ) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting position origin canonical history is immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER accounting_position_origin_history_guard
              BEFORE INSERT OR UPDATE OR DELETE ON public.accounting_position_origins
              FOR EACH ROW EXECUTE FUNCTION public.accounting_position_origin_history_guard();

            CREATE OR REPLACE FUNCTION public.accounting_position_consumption_history_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting position consumption deletion is forbidden';
              END IF;
              IF TG_OP='INSERT' AND NEW.status<>'effective' THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position consumption must start effective';
              END IF;
              IF TG_OP='UPDATE' AND (
                OLD.status<>'effective' OR NEW.status<>'reversed'
                OR (to_jsonb(NEW) - ARRAY['status','reversal_operation_id','reversed_at']) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status','reversal_operation_id','reversed_at'])
              ) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='Accounting position consumption canonical history is immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER accounting_position_consumption_history_guard
              BEFORE INSERT OR UPDATE OR DELETE ON public.accounting_position_consumptions
              FOR EACH ROW EXECUTE FUNCTION public.accounting_position_consumption_history_guard();

            CREATE OR REPLACE FUNCTION public.validate_accounting_position_origin(p_tenant char(26), p_origin char(26)) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              o public.accounting_position_origins%ROWTYPE;
              t public.contract_consideration_transitions%ROWTYPE;
              l public.contract_consideration_lots%ROWTYPE;
              j public.journal_entries%ROWTYPE;
              a public.accounts%ROWTYPE;
              consumed numeric(19,2);
              allocated numeric(19,2);
            BEGIN
              SELECT * INTO o FROM public.accounting_position_origins WHERE tenant_id=p_tenant AND id=p_origin;
              IF NOT FOUND THEN RETURN; END IF;
              SELECT * INTO t FROM public.contract_consideration_transitions WHERE tenant_id=o.tenant_id AND id=o.consideration_transition_id;
              SELECT * INTO l FROM public.contract_consideration_lots WHERE tenant_id=o.tenant_id AND id=o.consideration_lot_id;
              SELECT * INTO j FROM public.journal_entries WHERE tenant_id=o.tenant_id AND id=o.origin_journal_entry_id;
              SELECT * INTO a FROM public.accounts WHERE tenant_id=o.tenant_id AND id=o.account_id;

              IF t.id IS NULL OR l.id IS NULL OR j.id IS NULL OR a.id IS NULL
                 OR t.contract_id<>o.contract_id OR l.contract_id<>o.contract_id
                 OR l.transition_id IS DISTINCT FROM t.id
                 OR t.source_type<>o.economic_source_type OR t.source_id<>o.economic_source_id
                 OR j.status<>'posted' OR j.entry_date<>o.accounting_date
                 OR a.kind<>'posting'
                 OR (o.position_type='CONTRACT_ASSET' AND a.account_type<>'asset')
                 OR (o.position_type='CONTRACT_LIABILITY' AND a.account_type<>'liability') THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position origin provenance is inconsistent';
              END IF;

              SELECT COALESCE(sum(amount),0) INTO consumed
                FROM public.accounting_position_consumptions
                WHERE tenant_id=o.tenant_id AND origin_id=o.id AND status='effective';
              IF consumed > o.origin_amount THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position origin is over-consumed';
              END IF;
              IF o.status='reversed' AND consumed<>0 THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position origin reversal requires successor-first consumption reversal';
              END IF;

              SELECT COALESCE(sum(x.amount),0) INTO allocated
                FROM public.accounting_position_origin_journal_line_allocations x
                JOIN public.journal_lines line ON line.tenant_id=x.tenant_id AND line.id=x.journal_line_id
                WHERE x.tenant_id=o.tenant_id AND x.origin_id=o.id
                  AND x.journal_entry_id=o.origin_journal_entry_id
                  AND line.journal_entry_id=o.origin_journal_entry_id
                  AND line.account_id=o.account_id;
              IF allocated<>o.origin_amount THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position origin requires exact Journal-line allocation';
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION public.validate_accounting_position_consumption(p_tenant char(26), p_consumption char(26)) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              c public.accounting_position_consumptions%ROWTYPE;
              o public.accounting_position_origins%ROWTYPE;
              t public.contract_consideration_transitions%ROWTYPE;
              l public.contract_consideration_lots%ROWTYPE;
              j public.journal_entries%ROWTYPE;
              allocated numeric(19,2);
            BEGIN
              SELECT * INTO c FROM public.accounting_position_consumptions WHERE tenant_id=p_tenant AND id=p_consumption;
              IF NOT FOUND THEN RETURN; END IF;
              SELECT * INTO o FROM public.accounting_position_origins WHERE tenant_id=c.tenant_id AND id=c.origin_id;
              SELECT * INTO t FROM public.contract_consideration_transitions WHERE tenant_id=c.tenant_id AND id=c.consideration_transition_id;
              SELECT * INTO l FROM public.contract_consideration_lots WHERE tenant_id=c.tenant_id AND id=c.consideration_lot_id;
              SELECT * INTO j FROM public.journal_entries WHERE tenant_id=c.tenant_id AND id=c.consuming_journal_entry_id;

              IF o.id IS NULL OR t.id IS NULL OR l.id IS NULL OR j.id IS NULL
                 OR o.contract_id<>c.contract_id OR t.contract_id<>c.contract_id OR l.contract_id<>c.contract_id
                 OR c.currency<>o.currency OR j.status<>'posted'
                 OR (o.position_type='CONTRACT_ASSET' AND c.consuming_recognition_type<>'RECEIVABLE_AR_RECOGNITION')
                 OR (o.position_type='CONTRACT_LIABILITY' AND c.consuming_recognition_type<>'PERFORMANCE_ACCOUNTING_RECOGNITION') THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position consumption provenance is inconsistent';
              END IF;

              SELECT COALESCE(sum(x.amount),0) INTO allocated
                FROM public.accounting_position_consumption_journal_line_allocations x
                JOIN public.journal_lines line ON line.tenant_id=x.tenant_id AND line.id=x.journal_line_id
                WHERE x.tenant_id=c.tenant_id AND x.consumption_id=c.id
                  AND x.journal_entry_id=c.consuming_journal_entry_id
                  AND line.journal_entry_id=c.consuming_journal_entry_id
                  AND line.account_id=o.account_id;
              IF allocated<>c.amount THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Accounting position consumption requires exact Journal-line allocation';
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION public.accounting_position_final_state() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE target_origin char(26);
            BEGIN
              IF TG_TABLE_NAME='accounting_position_origins' THEN
                target_origin := NEW.id;
                PERFORM public.validate_accounting_position_origin(NEW.tenant_id,NEW.id);
              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                target_origin := NEW.origin_id;
                PERFORM public.validate_accounting_position_consumption(NEW.tenant_id,NEW.id);
              ELSIF TG_TABLE_NAME='accounting_position_origin_journal_line_allocations' THEN
                target_origin := NEW.origin_id;
              ELSIF TG_TABLE_NAME='accounting_position_consumption_journal_line_allocations' THEN
                SELECT origin_id INTO target_origin FROM public.accounting_position_consumptions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.consumption_id;
                PERFORM public.validate_accounting_position_consumption(NEW.tenant_id,NEW.consumption_id);
              END IF;
              IF target_origin IS NOT NULL THEN
                PERFORM public.validate_accounting_position_origin(NEW.tenant_id,target_origin);
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER accounting_position_origin_final
              AFTER INSERT OR UPDATE ON public.accounting_position_origins
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.accounting_position_final_state();
            CREATE CONSTRAINT TRIGGER accounting_position_consumption_final
              AFTER INSERT OR UPDATE ON public.accounting_position_consumptions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.accounting_position_final_state();
            CREATE CONSTRAINT TRIGGER accounting_position_origin_allocation_final
              AFTER INSERT ON public.accounting_position_origin_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.accounting_position_final_state();
            CREATE CONSTRAINT TRIGGER accounting_position_consumption_allocation_final
              AFTER INSERT ON public.accounting_position_consumption_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.accounting_position_final_state();

            CREATE OR REPLACE FUNCTION public.validate_performance_accounting_adoption(p_tenant char(26), p_adoption char(26)) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              a public.performance_accounting_adoptions%ROWTYPE;
              c public.contracts%ROWTYPE;
              p public.contract_consideration_positions%ROWTYPE;
              billed record;
              liability_capacity numeric(19,2);
            BEGIN
              SELECT * INTO a FROM public.performance_accounting_adoptions WHERE tenant_id=p_tenant AND id=p_adoption;
              IF NOT FOUND THEN RETURN; END IF;
              SELECT * INTO c FROM public.contracts WHERE tenant_id=a.tenant_id AND id=a.contract_id;
              SELECT * INTO p FROM public.contract_consideration_positions WHERE tenant_id=a.tenant_id AND id=a.consideration_position_id;
              IF c.id IS NULL OR p.id IS NULL OR p.contract_id<>a.contract_id OR p.status<>'ADOPTED'
                 OR c.currency<>'SAR' OR p.currency<>'SAR'
                 OR c.total_amount IS DISTINCT FROM a.consideration_amount
                 OR p.consideration_amount IS DISTINCT FROM a.consideration_amount THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Performance Accounting adoption must snapshot exact adopted Contract consideration';
              END IF;
              IF EXISTS (
                SELECT 1 FROM public.unit_handover_acceptances
                WHERE tenant_id=a.tenant_id AND contract_id=a.contract_id AND status='effective'
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Clean Performance Accounting adoption rejects prior effective performance';
              END IF;
              IF EXISTS (
                SELECT 1 FROM public.accounting_position_origins
                WHERE tenant_id=a.tenant_id AND contract_id=a.contract_id AND position_type='CONTRACT_ASSET'
              ) OR EXISTS (
                SELECT 1 FROM public.accounting_position_consumptions
                WHERE tenant_id=a.tenant_id AND contract_id=a.contract_id
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Clean Performance Accounting adoption rejects prior performance-accounting protocol history';
              END IF;

              FOR billed IN
                SELECT
                  l.id,
                  l.transition_id,
                  l.amount - COALESCE((
                    SELECT sum(e.consumed_amount)
                    FROM public.contract_consideration_transition_lots e
                    JOIN public.contract_consideration_transitions consumer
                      ON consumer.tenant_id=e.tenant_id AND consumer.id=e.transition_id
                    WHERE e.tenant_id=l.tenant_id
                      AND e.lot_id=l.id
                      AND consumer.status='effective'
                  ),0) AS remaining
                FROM public.contract_consideration_lots l
                JOIN public.contract_consideration_transitions creator
                  ON creator.tenant_id=l.tenant_id AND creator.id=l.transition_id
                WHERE l.tenant_id=a.tenant_id
                  AND l.position_id=a.consideration_position_id
                  AND l.semantic_position='BILLED_UNEARNED'
                  AND creator.status='effective'
              LOOP
                IF billed.remaining <= 0 THEN
                  CONTINUE;
                END IF;

                SELECT COALESCE(sum(
                  o.origin_amount - COALESCE((
                    SELECT sum(cn.amount)
                    FROM public.accounting_position_consumptions cn
                    WHERE cn.tenant_id=o.tenant_id
                      AND cn.origin_id=o.id
                      AND cn.status='effective'
                  ),0)
                ),0) INTO liability_capacity
                FROM public.accounting_position_origins o
                WHERE o.tenant_id=a.tenant_id
                  AND o.contract_id=a.contract_id
                  AND o.position_type='CONTRACT_LIABILITY'
                  AND o.status='effective'
                  AND o.consideration_transition_id=billed.transition_id
                  AND o.consideration_lot_id=billed.id;

                IF liability_capacity < billed.remaining THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='Clean Performance Accounting adoption requires exact protocol-backed Contract Liability capacity';
                END IF;
              END LOOP;
            END $$;

            CREATE OR REPLACE FUNCTION public.performance_accounting_adoption_final_state() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              PERFORM public.validate_performance_accounting_adoption(NEW.tenant_id,NEW.id);
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER performance_accounting_adoption_final
              AFTER INSERT ON public.performance_accounting_adoptions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_adoption_final_state();
            SQL);

        $this->hardenRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        foreach ([
            'accounting_position_consumption_journal_line_allocations',
            'accounting_position_origin_journal_line_allocations',
            'accounting_position_consumptions',
            'accounting_position_origins',
            'performance_accounting_adoptions',
            'performance_accounting_policies',
            'contract_consideration_accounting_policies',
            'receivable_ar_policies',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Accounting Recognition Foundations rollback refused while data exists.');
            }
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE public.accounting_position_consumption_journal_line_allocations;
            DROP TABLE public.accounting_position_origin_journal_line_allocations;
            DROP TABLE public.accounting_position_consumptions;
            DROP TABLE public.accounting_position_origins;
            DROP TABLE public.performance_accounting_adoptions;
            DROP TABLE public.performance_accounting_policies;
            DROP TABLE public.contract_consideration_accounting_policies;
            DROP TABLE public.receivable_ar_policies;

            DROP FUNCTION public.performance_accounting_adoption_final_state();
            DROP FUNCTION public.validate_performance_accounting_adoption(character,character);
            DROP FUNCTION public.accounting_position_final_state();
            DROP FUNCTION public.validate_accounting_position_consumption(character,character);
            DROP FUNCTION public.validate_accounting_position_origin(character,character);
            DROP FUNCTION public.accounting_position_consumption_history_guard();
            DROP FUNCTION public.accounting_position_origin_history_guard();
            DROP FUNCTION public.accounting_recognition_immutable_history();
            DROP FUNCTION public.accounting_recognition_policy_final_state();
            DROP FUNCTION public.validate_accounting_recognition_policy_history(text,character);
            DROP FUNCTION public.accounting_recognition_policy_history_guard();
            DROP FUNCTION public.accounting_recognition_policy_account_guard();
            SQL);
    }

    private function hardenRuntimePrivileges(): void
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! is_string($runtimeRole) || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            throw new RuntimeException('ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.');
        }

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        DB::unprepared("REVOKE ALL ON TABLE
          public.receivable_ar_policies,
          public.contract_consideration_accounting_policies,
          public.performance_accounting_policies,
          public.performance_accounting_adoptions,
          public.accounting_position_origins,
          public.accounting_position_consumptions,
          public.accounting_position_origin_journal_line_allocations,
          public.accounting_position_consumption_journal_line_allocations
          FROM {$identifier}");

        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE
          public.receivable_ar_policies,
          public.contract_consideration_accounting_policies,
          public.performance_accounting_policies
          TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT ON TABLE public.performance_accounting_adoptions TO {$identifier}");
        DB::unprepared("GRANT SELECT ON TABLE
          public.accounting_position_origins,
          public.accounting_position_consumptions,
          public.accounting_position_origin_journal_line_allocations,
          public.accounting_position_consumption_journal_line_allocations
          TO {$identifier}");

        DB::unprepared("REVOKE EXECUTE ON FUNCTION
          public.accounting_recognition_policy_account_guard(),
          public.accounting_recognition_policy_history_guard(),
          public.validate_accounting_recognition_policy_history(text,character),
          public.accounting_recognition_policy_final_state(),
          public.accounting_recognition_immutable_history(),
          public.accounting_position_origin_history_guard(),
          public.accounting_position_consumption_history_guard(),
          public.validate_accounting_position_origin(character,character),
          public.validate_accounting_position_consumption(character,character),
          public.accounting_position_final_state(),
          public.validate_performance_accounting_adoption(character,character),
          public.performance_accounting_adoption_final_state()
          FROM PUBLIC,{$identifier}");
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

        throw new RuntimeException('Accounting Recognition Foundations requires the public PostgreSQL schema.');
    }
};
