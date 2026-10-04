<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE account_cash_roles (
 id char(26) PRIMARY KEY, tenant_id char(26) NOT NULL, account_id char(26) NOT NULL, role varchar(16) NOT NULL,
 assignment_operation_id char(26) NOT NULL, assigned_by bigint NOT NULL, assigned_at timestamptz NOT NULL, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,account_id), UNIQUE(tenant_id,assignment_operation_id),
 CHECK(role IN ('cash','cash_equivalent')),
 FOREIGN KEY(tenant_id,account_id) REFERENCES accounts(tenant_id,id), FOREIGN KEY(tenant_id,assigned_by) REFERENCES tenant_users(tenant_id,user_id)
);
CREATE TABLE journal_cash_flow_semantics (
 id char(26) PRIMARY KEY, tenant_id char(26) NOT NULL, journal_entry_id char(26) NOT NULL, activity varchar(16) NOT NULL,
 semantic_operation_id char(26) NOT NULL, assigned_by bigint NOT NULL, assigned_at timestamptz NOT NULL, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,journal_entry_id), UNIQUE(tenant_id,semantic_operation_id),
 CHECK(activity IN ('operating','investing','financing')),
 FOREIGN KEY(tenant_id,journal_entry_id) REFERENCES journal_entries(tenant_id,id), FOREIGN KEY(tenant_id,assigned_by) REFERENCES tenant_users(tenant_id,user_id)
);
CREATE OR REPLACE FUNCTION cash_flow_role_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'cash role history is immutable'; END IF;
 PERFORM 1 FROM accounts a
 WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.account_id
   AND a.kind='posting' AND a.account_type='asset' AND a.classification='current_asset'
 FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'cash role requires current asset posting account'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER account_cash_roles_guard BEFORE INSERT OR UPDATE OR DELETE ON account_cash_roles FOR EACH ROW EXECUTE FUNCTION cash_flow_role_guard();
CREATE OR REPLACE FUNCTION cash_flow_account_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='UPDATE' AND (NEW.tenant_id,NEW.id,NEW.kind,NEW.account_type,NEW.classification) IS DISTINCT FROM (OLD.tenant_id,OLD.id,OLD.kind,OLD.account_type,OLD.classification) AND EXISTS(SELECT 1 FROM account_cash_roles r WHERE r.tenant_id=OLD.tenant_id AND r.account_id=OLD.id) THEN RAISE EXCEPTION 'cash role account structural identity is immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER accounts_cash_flow_guard BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION cash_flow_account_guard();
CREATE OR REPLACE FUNCTION cash_flow_semantic_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE journal_status varchar(16); BEGIN
 SELECT j.status INTO journal_status FROM journal_entries j
 WHERE j.tenant_id=OLD.tenant_id AND j.id=OLD.journal_entry_id
 FOR UPDATE;
 IF TG_OP IN ('UPDATE','DELETE') AND journal_status='posted' THEN RAISE EXCEPTION 'posted cash flow semantic is immutable'; END IF;
 RETURN COALESCE(NEW,OLD);
END $$;
CREATE TRIGGER journal_cash_flow_semantics_guard BEFORE UPDATE OR DELETE ON journal_cash_flow_semantics FOR EACH ROW EXECUTE FUNCTION cash_flow_semantic_guard();
CREATE OR REPLACE FUNCTION cash_flow_posted_final_state() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE d numeric; s boolean; original_activity varchar(16); semantic_activity varchar(16); BEGIN
 SELECT COALESCE(SUM(l.debit-l.credit),0) INTO d FROM journal_lines l JOIN account_cash_roles r ON r.tenant_id=l.tenant_id AND r.account_id=l.account_id WHERE l.tenant_id=NEW.tenant_id AND l.journal_entry_id=NEW.id;
 SELECT EXISTS(SELECT 1 FROM journal_cash_flow_semantics x WHERE x.tenant_id=NEW.tenant_id AND x.journal_entry_id=NEW.id) INTO s;
 IF NEW.status='posted' AND ((d=0 AND s) OR (d<>0 AND NOT s)) THEN RAISE EXCEPTION 'cash flow semantic final state is incomplete'; END IF;
 IF NEW.status='posted' AND NEW.reverses_journal_entry_id IS NOT NULL THEN
   SELECT x.activity INTO original_activity FROM journal_cash_flow_semantics x WHERE x.tenant_id=NEW.tenant_id AND x.journal_entry_id=NEW.reverses_journal_entry_id;
   SELECT x.activity INTO semantic_activity FROM journal_cash_flow_semantics x WHERE x.tenant_id=NEW.tenant_id AND x.journal_entry_id=NEW.id;
   IF original_activity IS DISTINCT FROM semantic_activity THEN RAISE EXCEPTION 'cash flow reversal semantic must equal original semantic'; END IF;
 END IF;
 RETURN NEW; END $$;
CREATE CONSTRAINT TRIGGER journal_cash_flow_final_state AFTER INSERT OR UPDATE OF status ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cash_flow_posted_final_state();
CREATE OR REPLACE FUNCTION cash_flow_semantic_final_state() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE journal_id char(26); journal_row journal_entries%ROWTYPE; d numeric; s boolean; BEGIN
 journal_id := COALESCE(NEW.journal_entry_id,OLD.journal_entry_id);
 SELECT * INTO journal_row FROM journal_entries WHERE tenant_id=COALESCE(NEW.tenant_id,OLD.tenant_id) AND id=journal_id;
 IF NOT FOUND OR journal_row.status <> 'posted' THEN RETURN COALESCE(NEW,OLD); END IF;
 SELECT COALESCE(SUM(l.debit-l.credit),0) INTO d FROM journal_lines l JOIN account_cash_roles r ON r.tenant_id=l.tenant_id AND r.account_id=l.account_id WHERE l.tenant_id=journal_row.tenant_id AND l.journal_entry_id=journal_row.id;
 SELECT EXISTS(SELECT 1 FROM journal_cash_flow_semantics x WHERE x.tenant_id=journal_row.tenant_id AND x.journal_entry_id=journal_row.id) INTO s;
 IF (d=0 AND s) OR (d<>0 AND NOT s) THEN RAISE EXCEPTION 'cash flow semantic final state is incomplete'; END IF;
 RETURN COALESCE(NEW,OLD); END $$;
CREATE CONSTRAINT TRIGGER journal_cash_flow_semantic_final_state AFTER INSERT OR UPDATE OR DELETE ON journal_cash_flow_semantics DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cash_flow_semantic_final_state();
CREATE OR REPLACE FUNCTION public.validate_accounting_audit_subject() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$ DECLARE valid boolean:=false; BEGIN
 valid:=CASE NEW.subject_type
  WHEN 'accounting_settings' THEN EXISTS(SELECT 1 FROM public.accounting_settings WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'account' THEN EXISTS(SELECT 1 FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'journal_entry' THEN EXISTS(SELECT 1 FROM public.journal_entries WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'accounting_period' THEN EXISTS(SELECT 1 FROM public.accounting_periods WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'opening_balance_operation' THEN EXISTS(SELECT 1 FROM public.opening_balance_operations WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'performance_accounting_recognition' THEN EXISTS(SELECT 1 FROM public.performance_accounting_recognitions WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'receivable_ar_recognition' THEN EXISTS(SELECT 1 FROM public.receivable_ar_recognitions WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'receivable_settlement' THEN EXISTS(SELECT 1 FROM public.receivable_settlements WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  ELSE false END;
 IF NOT valid THEN RAISE EXCEPTION USING ERRCODE='23503',MESSAGE='accounting audit subject missing or cross-tenant'; END IF;
 IF NOT ((NEW.event LIKE 'account.%' AND NEW.subject_type='account') OR (NEW.event LIKE 'journal.%' AND NEW.subject_type='journal_entry') OR (NEW.event LIKE 'period.%' AND NEW.subject_type='accounting_period') OR (NEW.event LIKE 'opening_balance.%' AND NEW.subject_type='opening_balance_operation') OR (NEW.event LIKE 'performance_accounting.%' AND NEW.subject_type='performance_accounting_recognition') OR (NEW.event LIKE 'receivable_ar.%' AND NEW.subject_type='receivable_ar_recognition') OR (NEW.event LIKE 'receivable_settlement.%' AND NEW.subject_type='receivable_settlement') OR (NEW.event='accounting.activated' AND NEW.subject_type='accounting_settings') OR (NEW.event='cash_flow.cash_role_assigned' AND NEW.subject_type='account') OR (NEW.event='cash_flow.historical_semantic_adopted' AND NEW.subject_type='journal_entry')) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='accounting audit event/subject mismatch'; END IF;
 RETURN NEW; END $$;
ALTER TABLE public.accounting_audits DROP CONSTRAINT accounting_audits_event_check;
ALTER TABLE public.accounting_audits ADD CONSTRAINT accounting_audits_event_check CHECK(event IN ('accounting.activated','account.created','account.updated','account.archived','account.restored','journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed','period.created','period.boundaries_changed','period.closed','period.reopened','opening_balance.created','opening_balance.draft_deleted','opening_balance.posted','opening_balance.reversed','opening_balance.reactivated','performance_accounting.recognized','performance_accounting.reversed','performance_accounting.corrected','receivable_ar.recognized','receivable_ar.reversed','receivable_settlement.posted','receivable_settlement.reversed','cash_flow.cash_role_assigned','cash_flow.historical_semantic_adopted'));
SQL);

        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (is_string($runtimeRole) && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            $role = '"'.str_replace('"', '""', $runtimeRole).'"';
            DB::unprepared("GRANT SELECT,INSERT ON TABLE public.account_cash_roles TO {$role}");
            DB::unprepared("GRANT SELECT,INSERT,UPDATE,DELETE ON TABLE public.journal_cash_flow_semantics TO {$role}");
            DB::unprepared(<<<SQL
REVOKE EXECUTE ON FUNCTION
 public.cash_flow_role_guard(),
 public.cash_flow_account_guard(),
 public.cash_flow_semantic_guard(),
 public.cash_flow_posted_final_state(),
 public.cash_flow_semantic_final_state()
FROM PUBLIC,{$role};
SQL);
        }
    }

    public function down(): void
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (is_string($runtimeRole) && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            $role = '"'.str_replace('"', '""', $runtimeRole).'"';
            DB::unprepared("REVOKE ALL ON TABLE public.journal_cash_flow_semantics,public.account_cash_roles FROM {$role}");
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE public.accounting_audits DROP CONSTRAINT accounting_audits_event_check;
ALTER TABLE public.accounting_audits ADD CONSTRAINT accounting_audits_event_check CHECK(event IN (
 'accounting.activated',
 'account.created','account.updated','account.archived','account.restored',
 'journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed',
 'period.created','period.boundaries_changed','period.closed','period.reopened',
 'opening_balance.created','opening_balance.draft_deleted','opening_balance.posted','opening_balance.reversed','opening_balance.reactivated',
 'performance_accounting.recognized','performance_accounting.reversed','performance_accounting.corrected',
 'receivable_ar.recognized','receivable_ar.reversed',
 'receivable_settlement.posted','receivable_settlement.reversed'
));
ALTER TABLE public.accounting_audits DROP CONSTRAINT accounting_audits_subject_type_check;
ALTER TABLE public.accounting_audits ADD CONSTRAINT accounting_audits_subject_type_check CHECK(subject_type IN (
 'accounting_settings','account','journal_entry','accounting_period','opening_balance_operation',
 'performance_accounting_recognition','receivable_ar_recognition','receivable_settlement'
));
CREATE OR REPLACE FUNCTION public.validate_accounting_audit_subject()
RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE valid boolean:=false;
BEGIN
 valid:=CASE NEW.subject_type
  WHEN 'accounting_settings' THEN EXISTS(SELECT 1 FROM public.accounting_settings WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'account' THEN EXISTS(SELECT 1 FROM public.accounts WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'journal_entry' THEN EXISTS(SELECT 1 FROM public.journal_entries WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'accounting_period' THEN EXISTS(SELECT 1 FROM public.accounting_periods WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'opening_balance_operation' THEN EXISTS(SELECT 1 FROM public.opening_balance_operations WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'performance_accounting_recognition' THEN EXISTS(SELECT 1 FROM public.performance_accounting_recognitions WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'receivable_ar_recognition' THEN EXISTS(SELECT 1 FROM public.receivable_ar_recognitions WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  WHEN 'receivable_settlement' THEN EXISTS(SELECT 1 FROM public.receivable_settlements WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
  ELSE false END;
 IF NOT valid THEN RAISE EXCEPTION USING ERRCODE='23503',MESSAGE='accounting audit subject missing or cross-tenant'; END IF;
 IF NOT ((NEW.event LIKE 'account.%' AND NEW.subject_type='account') OR (NEW.event LIKE 'journal.%' AND NEW.subject_type='journal_entry') OR (NEW.event LIKE 'period.%' AND NEW.subject_type='accounting_period') OR (NEW.event LIKE 'opening_balance.%' AND NEW.subject_type='opening_balance_operation') OR (NEW.event LIKE 'performance_accounting.%' AND NEW.subject_type='performance_accounting_recognition') OR (NEW.event LIKE 'receivable_ar.%' AND NEW.subject_type='receivable_ar_recognition') OR (NEW.event LIKE 'receivable_settlement.%' AND NEW.subject_type='receivable_settlement') OR (NEW.event='accounting.activated' AND NEW.subject_type='accounting_settings')) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='accounting audit event/subject mismatch'; END IF;
 RETURN NEW;
END $$;
SQL);
        DB::unprepared('DROP TABLE IF EXISTS journal_cash_flow_semantics CASCADE; DROP TABLE IF EXISTS account_cash_roles CASCADE; DROP FUNCTION IF EXISTS cash_flow_semantic_final_state() CASCADE; DROP FUNCTION IF EXISTS cash_flow_posted_final_state() CASCADE; DROP FUNCTION IF EXISTS cash_flow_semantic_guard() CASCADE; DROP FUNCTION IF EXISTS cash_flow_account_guard() CASCADE; DROP FUNCTION IF EXISTS cash_flow_role_guard() CASCADE;');
    }
};
