<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.prevent_accounting_source_type_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='accounting_source_types is immutable';
            END $$;
            CREATE TRIGGER accounting_source_types_immutable_update BEFORE UPDATE ON public.accounting_source_types FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_source_type_mutation();
            CREATE TRIGGER accounting_source_types_immutable_delete BEFORE DELETE ON public.accounting_source_types FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_source_type_mutation();

            CREATE OR REPLACE FUNCTION public.prevent_accounting_settings_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='accounting_settings is immutable';
            END $$;
            CREATE TRIGGER accounting_settings_immutable_update BEFORE UPDATE ON public.accounting_settings FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_settings_mutation();
            CREATE TRIGGER accounting_settings_immutable_delete BEFORE DELETE ON public.accounting_settings FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_settings_mutation();

            CREATE OR REPLACE FUNCTION public.prevent_activated_tenant_currency_change() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              IF EXISTS(SELECT 1 FROM public.accounting_settings s WHERE s.tenant_id=OLD.id)
                 AND (NEW.currency<>OLD.currency OR NEW.currency<>'SAR') THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='activated Tenant ledger currency is immutable';
              END IF; RETURN NEW;
            END $$;
            CREATE TRIGGER tenants_accounting_currency_immutable BEFORE UPDATE OF currency ON public.tenants FOR EACH ROW EXECUTE FUNCTION public.prevent_activated_tenant_currency_change();

            CREATE OR REPLACE FUNCTION public.enforce_account_hierarchy() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE p public.accounts%ROWTYPE; cycle_found boolean;
            BEGIN
              PERFORM 1 FROM public.accounting_settings WHERE tenant_id=NEW.tenant_id FOR UPDATE;
              IF NEW.parent_id IS NOT NULL THEN
                SELECT * INTO p FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=NEW.parent_id FOR UPDATE;
                IF NOT FOUND OR p.kind<>'group' OR p.account_type<>NEW.account_type THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='account parent must be a same-type group';
                END IF;
                IF NEW.status='active' AND p.status<>'active' THEN
                  RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='active account requires active ancestors';
                END IF;
                WITH RECURSIVE ancestors(id,parent_id) AS (
                  SELECT a.id,a.parent_id FROM public.accounts a WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.parent_id
                  UNION ALL SELECT a.id,a.parent_id FROM public.accounts a JOIN ancestors x ON a.id=x.parent_id WHERE a.tenant_id=NEW.tenant_id
                ) SELECT EXISTS(SELECT 1 FROM ancestors WHERE id=NEW.id) INTO cycle_found;
                IF cycle_found THEN RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='account hierarchy cycle'; END IF;
              END IF;
              IF NEW.kind='posting' AND EXISTS(SELECT 1 FROM public.accounts WHERE tenant_id=NEW.tenant_id AND parent_id=NEW.id) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='posting account cannot have children';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER accounts_hierarchy_guard BEFORE INSERT OR UPDATE OF tenant_id,parent_id,kind,account_type,classification,status ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.enforce_account_hierarchy();

            CREATE OR REPLACE FUNCTION public.enforce_account_lifecycle_and_history() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE has_history boolean;
            BEGIN
              SELECT EXISTS(SELECT 1 FROM public.journal_lines l JOIN public.journal_entries j ON j.tenant_id=l.tenant_id AND j.id=l.journal_entry_id WHERE l.tenant_id=OLD.tenant_id AND l.account_id=OLD.id AND j.status='posted') INTO has_history;
              IF has_history AND (NEW.tenant_id,NEW.code,NEW.kind,NEW.account_type,NEW.classification,NEW.parent_id) IS DISTINCT FROM (OLD.tenant_id,OLD.code,OLD.kind,OLD.account_type,OLD.classification,OLD.parent_id) THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='account structure is immutable after posted history';
              END IF;
              IF OLD.status='archived' AND NEW.status='archived' AND NEW IS DISTINCT FROM OLD THEN
                RAISE EXCEPTION USING ERRCODE='55000', MESSAGE='archived account cannot be edited';
              END IF;
              IF OLD.status='active' AND NEW.status='archived' AND EXISTS(SELECT 1 FROM public.accounts WHERE tenant_id=OLD.tenant_id AND parent_id=OLD.id AND status='active') THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='group with active children cannot be archived';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER accounts_mutation_guard BEFORE UPDATE ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.enforce_account_lifecycle_and_history();
            CREATE OR REPLACE FUNCTION public.prevent_account_delete() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='accounts cannot be deleted'; END $$;
            CREATE TRIGGER accounts_delete_guard BEFORE DELETE ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.prevent_account_delete();

            CREATE OR REPLACE FUNCTION public.enforce_accounting_period_nonoverlap() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              PERFORM 1 FROM public.accounting_settings WHERE tenant_id=NEW.tenant_id FOR UPDATE;
              IF EXISTS(SELECT 1 FROM public.accounting_periods p WHERE p.tenant_id=NEW.tenant_id AND p.id<>NEW.id AND daterange(p.start_date,p.end_date,'[]') && daterange(NEW.start_date,NEW.end_date,'[]')) THEN
                RAISE EXCEPTION USING ERRCODE='23514', MESSAGE='accounting periods overlap';
              END IF; RETURN NEW;
            END $$;
            CREATE TRIGGER accounting_periods_overlap_guard BEFORE INSERT OR UPDATE OF tenant_id,start_date,end_date ON public.accounting_periods FOR EACH ROW EXECUTE FUNCTION public.enforce_accounting_period_nonoverlap();
            CREATE OR REPLACE FUNCTION public.enforce_accounting_period_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              IF (NEW.tenant_id,NEW.id) IS DISTINCT FROM (OLD.tenant_id,OLD.id) THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='period identity is immutable'; END IF;
              IF (NEW.start_date,NEW.end_date) IS DISTINCT FROM (OLD.start_date,OLD.end_date) AND EXISTS(SELECT 1 FROM public.journal_entries WHERE tenant_id=OLD.tenant_id AND accounting_period_id=OLD.id AND status='posted') THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='period boundaries immutable after posted history';
              END IF;
              IF OLD.status=NEW.status AND NEW IS DISTINCT FROM OLD AND (NEW.start_date,NEW.end_date) IS NOT DISTINCT FROM (OLD.start_date,OLD.end_date) THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='invalid period mutation'; END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER accounting_periods_mutation_guard BEFORE UPDATE ON public.accounting_periods FOR EACH ROW EXECUTE FUNCTION public.enforce_accounting_period_mutation();
            CREATE OR REPLACE FUNCTION public.prevent_accounting_period_delete() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='accounting periods cannot be deleted'; END $$;
            CREATE TRIGGER accounting_periods_delete_guard BEFORE DELETE ON public.accounting_periods FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_period_delete();

            CREATE OR REPLACE FUNCTION public.enforce_journal_entry_insert() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              IF NEW.status<>'draft' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='journals must be inserted as draft'; END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER journal_entries_insert_guard BEFORE INSERT ON public.journal_entries FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_entry_insert();

            CREATE OR REPLACE FUNCTION public.enforce_journal_entry_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE p public.accounting_periods%ROWTYPE; n integer; min_line integer; max_line integer; deb numeric(19,2); cred numeric(19,2); target public.journal_entries%ROWTYPE;
            BEGIN
              IF OLD.status='posted' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='posted journal is immutable'; END IF;
              IF (NEW.id,NEW.tenant_id,NEW.origin,NEW.source_type,NEW.source_id,NEW.reverses_journal_entry_id) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.origin,OLD.source_type,OLD.source_id,OLD.reverses_journal_entry_id) THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='journal provenance is immutable';
              END IF;
              IF NEW.status='draft' THEN RETURN NEW; END IF;
              SELECT * INTO p FROM public.accounting_periods WHERE tenant_id=NEW.tenant_id AND id=NEW.accounting_period_id FOR UPDATE;
              IF NOT FOUND OR p.status<>'open' OR NEW.entry_date NOT BETWEEN p.start_date AND p.end_date THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='journal requires containing open period'; END IF;
              SELECT count(*),min(line_number),max(line_number),sum(debit),sum(credit) INTO n,min_line,max_line,deb,cred FROM public.journal_lines WHERE tenant_id=NEW.tenant_id AND journal_entry_id=NEW.id;
              IF n<2 OR min_line<>1 OR max_line<>n OR deb<>cred OR deb<=0 THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='journal lines must be contiguous, balanced and nonzero'; END IF;
              IF EXISTS(SELECT 1 FROM public.journal_lines l JOIN public.accounts a ON a.tenant_id=l.tenant_id AND a.id=l.account_id WHERE l.tenant_id=NEW.tenant_id AND l.journal_entry_id=NEW.id AND (a.kind<>'posting' OR (NEW.origin<>'reversal' AND a.status<>'active'))) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='journal account is not eligible'; END IF;
              IF NEW.origin='reversal' THEN
                SELECT * INTO target FROM public.journal_entries WHERE tenant_id=NEW.tenant_id AND id=NEW.reverses_journal_entry_id FOR UPDATE;
                IF NOT FOUND OR target.status<>'posted' OR NEW.entry_date<target.entry_date OR NEW.source_id<>target.id THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='invalid reversal target'; END IF;
                IF EXISTS(SELECT 1 FROM public.journal_lines a FULL JOIN public.journal_lines b ON b.tenant_id=NEW.tenant_id AND b.journal_entry_id=NEW.id AND b.line_number=a.line_number WHERE a.tenant_id=target.tenant_id AND a.journal_entry_id=target.id AND (b.id IS NULL OR a.account_id<>b.account_id OR a.debit<>b.credit OR a.credit<>b.debit OR a.memo IS DISTINCT FROM b.memo))
                   OR EXISTS(SELECT 1 FROM public.journal_lines b WHERE b.tenant_id=NEW.tenant_id AND b.journal_entry_id=NEW.id AND NOT EXISTS(SELECT 1 FROM public.journal_lines a WHERE a.tenant_id=target.tenant_id AND a.journal_entry_id=target.id AND a.line_number=b.line_number)) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='reversal lines must exactly swap target lines';
                END IF;
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER journal_entries_update_guard BEFORE UPDATE ON public.journal_entries FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_entry_mutation();

            CREATE OR REPLACE FUNCTION public.enforce_journal_entry_delete() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              IF OLD.status<>'draft' OR OLD.origin NOT IN ('manual','opening_balance') THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='journal deletion forbidden'; END IF; RETURN OLD;
            END $$;
            CREATE TRIGGER journal_entries_delete_guard BEFORE DELETE ON public.journal_entries FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_entry_delete();
            CREATE OR REPLACE FUNCTION public.validate_system_journal_final_state() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN
              IF EXISTS(SELECT 1 FROM public.journal_entries WHERE id=NEW.id AND tenant_id=NEW.tenant_id AND status='draft' AND origin IN ('business','reversal')) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='system journal cannot survive transaction as draft'; END IF; RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER journal_entries_system_draft_final_state AFTER INSERT OR UPDATE ON public.journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.validate_system_journal_final_state();

            CREATE OR REPLACE FUNCTION public.enforce_journal_line_parent_state() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ DECLARE tid char(26); jid char(26); s varchar(16); BEGIN
              tid=COALESCE(NEW.tenant_id,OLD.tenant_id); jid=COALESCE(NEW.journal_entry_id,OLD.journal_entry_id);
              SELECT status INTO s FROM public.journal_entries WHERE tenant_id=tid AND id=jid FOR UPDATE;
              IF NOT FOUND OR s<>'draft' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='journal lines mutable only under draft parent'; END IF;
              RETURN COALESCE(NEW,OLD);
            END $$;
            CREATE TRIGGER journal_lines_parent_state_guard BEFORE INSERT OR UPDATE OR DELETE ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.enforce_journal_line_parent_state();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.enforce_journal_line_parent_state() CASCADE;
            DROP FUNCTION public.validate_system_journal_final_state() CASCADE;
            DROP FUNCTION public.enforce_journal_entry_delete() CASCADE;
            DROP FUNCTION public.enforce_journal_entry_mutation() CASCADE;
            DROP FUNCTION public.enforce_journal_entry_insert() CASCADE;
            DROP FUNCTION public.prevent_accounting_period_delete() CASCADE;
            DROP FUNCTION public.enforce_accounting_period_mutation() CASCADE;
            DROP FUNCTION public.enforce_accounting_period_nonoverlap() CASCADE;
            DROP FUNCTION public.prevent_account_delete() CASCADE;
            DROP FUNCTION public.enforce_account_lifecycle_and_history() CASCADE;
            DROP FUNCTION public.enforce_account_hierarchy() CASCADE;
            DROP FUNCTION public.prevent_activated_tenant_currency_change() CASCADE;
            DROP FUNCTION public.prevent_accounting_settings_mutation() CASCADE;
            DROP FUNCTION public.prevent_accounting_source_type_mutation() CASCADE;
            SQL);
    }
};
