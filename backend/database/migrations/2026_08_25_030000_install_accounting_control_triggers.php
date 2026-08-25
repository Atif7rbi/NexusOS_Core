<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_opening_balance_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE tid char(26); cutoff date;
            BEGIN
              tid=COALESCE(NEW.tenant_id,OLD.tenant_id);
              PERFORM 1 FROM public.accounting_settings WHERE tenant_id=tid FOR UPDATE;
              IF TG_OP='INSERT' OR (TG_OP='UPDATE' AND OLD.status='draft') THEN
                SELECT max(j.entry_date) INTO cutoff
                FROM public.opening_balance_operations o
                JOIN public.journal_entries j ON j.tenant_id=o.tenant_id AND j.id=o.latest_effect_journal_entry_id
                WHERE o.tenant_id=tid AND o.status='posted' AND o.effect_state='neutralized';
                IF cutoff IS NOT NULL AND NEW.accounting_date<cutoff THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance date precedes neutralized history'; END IF;
              END IF;
              IF TG_OP='DELETE' THEN
                IF OLD.status<>'draft' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='posted opening balance is immutable'; END IF; RETURN OLD;
              END IF;
              IF TG_OP='UPDATE' AND OLD.status='posted' AND
                 (NEW.id,NEW.tenant_id,NEW.status,NEW.accounting_date,NEW.journal_entry_id,NEW.created_by,NEW.created_at,NEW.posted_by,NEW.posted_at)
                 IS DISTINCT FROM
                 (OLD.id,OLD.tenant_id,OLD.status,OLD.accounting_date,OLD.journal_entry_id,OLD.created_by,OLD.created_at,OLD.posted_by,OLD.posted_at)
              THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='posted opening balance core is immutable'; END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER opening_balance_mutation_guard BEFORE INSERT OR UPDATE OR DELETE ON public.opening_balance_operations FOR EACH ROW EXECUTE FUNCTION public.enforce_opening_balance_mutation();

            CREATE OR REPLACE FUNCTION public.validate_opening_balance_final_state() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE o public.opening_balance_operations%ROWTYPE; j public.journal_entries%ROWTYPE; terminal public.journal_entries%ROWTYPE; hops integer:=0; parity integer:=0;
            BEGIN
              SELECT * INTO o FROM public.opening_balance_operations WHERE tenant_id=COALESCE(NEW.tenant_id,OLD.tenant_id) AND id=COALESCE(NEW.id,OLD.id);
              IF NOT FOUND THEN RETURN NULL; END IF;
              SELECT * INTO j FROM public.journal_entries WHERE tenant_id=o.tenant_id AND id=o.journal_entry_id;
              IF NOT FOUND OR j.origin<>'opening_balance' OR j.source_type<>'opening_balance_operation' OR j.source_id<>o.id OR j.entry_date<>o.accounting_date OR j.status<>o.status THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance root journal mismatch';
              END IF;
              IF o.status='draft' THEN RETURN NULL; END IF;
              terminal:=j;
              LOOP
                EXIT WHEN terminal.id=o.latest_effect_journal_entry_id;
                SELECT r.* INTO terminal FROM public.journal_entries r WHERE r.tenant_id=o.tenant_id AND r.reverses_journal_entry_id=terminal.id AND r.status='posted';
                IF NOT FOUND THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance latest pointer is not chain terminal'; END IF;
                hops:=hops+1; parity:=1-parity;
              END LOOP;
              IF EXISTS(SELECT 1 FROM public.journal_entries r WHERE r.tenant_id=o.tenant_id AND r.reverses_journal_entry_id=terminal.id) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance latest pointer is not terminal'; END IF;
              IF (o.effect_state='effective')<>(parity=0) OR o.effect_updated_by<>terminal.posted_by OR o.effect_updated_at<>terminal.posted_at THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance effect state mismatch';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER opening_balance_final_consistency AFTER INSERT OR UPDATE OR DELETE ON public.opening_balance_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.validate_opening_balance_final_state();

            CREATE OR REPLACE FUNCTION public.validate_opening_balance_final_state_for(o public.opening_balance_operations) RETURNS void
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN RETURN; END $$;

            CREATE OR REPLACE FUNCTION public.schedule_opening_balance_validation() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE oid char(26); o public.opening_balance_operations%ROWTYPE;
            BEGIN
              SELECT id INTO oid FROM public.opening_balance_operations WHERE tenant_id=NEW.tenant_id AND (journal_entry_id=NEW.id OR latest_effect_journal_entry_id=NEW.id);
              IF oid IS NULL AND NEW.origin='reversal' THEN
                WITH RECURSIVE chain(id,reverses_id) AS (
                  SELECT NEW.id,NEW.reverses_journal_entry_id UNION ALL
                  SELECT j.id,j.reverses_journal_entry_id FROM public.journal_entries j JOIN chain c ON j.id=c.reverses_id WHERE j.tenant_id=NEW.tenant_id
                ) SELECT ob.id INTO oid FROM chain c JOIN public.opening_balance_operations ob ON ob.tenant_id=NEW.tenant_id AND ob.journal_entry_id=c.reverses_id LIMIT 1;
              END IF;
              IF oid IS NOT NULL THEN SELECT * INTO o FROM public.opening_balance_operations WHERE tenant_id=NEW.tenant_id AND id=oid; PERFORM public.validate_opening_balance_final_state_for(o); END IF;
              RETURN NULL;
            END $$;

            CREATE OR REPLACE FUNCTION public.validate_opening_balance_final_state_for(o public.opening_balance_operations) RETURNS void
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE j public.journal_entries%ROWTYPE; terminal public.journal_entries%ROWTYPE; parity integer:=0;
            BEGIN
              SELECT * INTO j FROM public.journal_entries WHERE tenant_id=o.tenant_id AND id=o.journal_entry_id;
              IF o.status='posted' THEN
                terminal:=j;
                LOOP EXIT WHEN terminal.id=o.latest_effect_journal_entry_id;
                  SELECT r.* INTO terminal FROM public.journal_entries r WHERE r.tenant_id=o.tenant_id AND r.reverses_journal_entry_id=terminal.id AND r.status='posted';
                  IF NOT FOUND THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance journal chain mismatch'; END IF;
                  parity:=1-parity;
                END LOOP;
                IF (o.effect_state='effective')<>(parity=0) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='opening balance parity mismatch'; END IF;
              END IF;
            END $$;
            CREATE CONSTRAINT TRIGGER journal_opening_balance_final_consistency AFTER INSERT OR UPDATE ON public.journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.schedule_opening_balance_validation();

            CREATE OR REPLACE FUNCTION public.validate_accounting_audit_subject() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE valid boolean:=false;
            BEGIN
              valid:=CASE NEW.subject_type
                WHEN 'accounting_settings' THEN EXISTS(SELECT 1 FROM public.accounting_settings WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'account' THEN EXISTS(SELECT 1 FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'journal_entry' THEN EXISTS(SELECT 1 FROM public.journal_entries WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'accounting_period' THEN EXISTS(SELECT 1 FROM public.accounting_periods WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'opening_balance_operation' THEN EXISTS(SELECT 1 FROM public.opening_balance_operations WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                ELSE false END;
              IF NOT valid THEN RAISE EXCEPTION USING ERRCODE='23503',MESSAGE='accounting audit subject missing or cross-tenant'; END IF;
              IF NOT ((NEW.event LIKE 'account.%' AND NEW.subject_type='account') OR
                (NEW.event LIKE 'journal.%' AND NEW.subject_type='journal_entry') OR
                (NEW.event LIKE 'period.%' AND NEW.subject_type='accounting_period') OR
                (NEW.event LIKE 'opening_balance.%' AND NEW.subject_type='opening_balance_operation') OR
                (NEW.event='accounting.activated' AND NEW.subject_type='accounting_settings')) THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='accounting audit event/subject mismatch';
              END IF; RETURN NEW;
            END $$;
            CREATE TRIGGER accounting_audits_subject_guard BEFORE INSERT ON public.accounting_audits FOR EACH ROW EXECUTE FUNCTION public.validate_accounting_audit_subject();
            CREATE OR REPLACE FUNCTION public.prevent_accounting_audit_mutation() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ BEGIN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='accounting_audits is immutable'; END $$;
            CREATE TRIGGER accounting_audits_immutable_update BEFORE UPDATE ON public.accounting_audits FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_audit_mutation();
            CREATE TRIGGER accounting_audits_immutable_delete BEFORE DELETE ON public.accounting_audits FOR EACH ROW EXECUTE FUNCTION public.prevent_accounting_audit_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.prevent_accounting_audit_mutation() CASCADE;
            DROP FUNCTION public.validate_accounting_audit_subject() CASCADE;
            DROP FUNCTION public.schedule_opening_balance_validation() CASCADE;
            DROP FUNCTION public.validate_opening_balance_final_state_for(public.opening_balance_operations) CASCADE;
            DROP FUNCTION public.validate_opening_balance_final_state() CASCADE;
            DROP FUNCTION public.enforce_opening_balance_mutation() CASCADE;
            SQL);
    }
};
