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

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.receivable_settlements (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              settlement_operation_id char(26) NOT NULL,
              payment_allocation_id char(26) NOT NULL,
              payment_id char(26) NOT NULL,
              receivable_id char(26) NOT NULL,
              receipt_payment_association_id char(26) NOT NULL,
              receipt_id char(26) NOT NULL,
              bank_receipt_cash_posting_id char(26) NOT NULL,
              receivable_ar_recognition_id char(26) NOT NULL,
              amount numeric(19,2) NOT NULL,
              currency varchar(3) NOT NULL,
              accounting_date date NOT NULL,
              clearing_account_id char(26) NOT NULL,
              ar_control_account_id char(26) NOT NULL,
              journal_entry_id char(26) NOT NULL,
              status varchar(16) NOT NULL,
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              reversal_operation_id char(26),
              reversal_journal_entry_id char(26),
              reversal_date date,
              reversal_reason varchar(500),
              reversed_by bigint,
              reversed_at timestamptz,
              CONSTRAINT receivable_settlements_tenant_id_id_unique
                UNIQUE (tenant_id,id),
              CONSTRAINT receivable_settlements_operation_unique
                UNIQUE (tenant_id,settlement_operation_id),
              CONSTRAINT receivable_settlements_allocation_unique
                UNIQUE (tenant_id,payment_allocation_id),
              CONSTRAINT receivable_settlements_tenant_foreign
                FOREIGN KEY (tenant_id) REFERENCES public.tenants(id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_allocation_foreign
                FOREIGN KEY (tenant_id,payment_allocation_id)
                REFERENCES public.payment_allocations(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_payment_foreign
                FOREIGN KEY (tenant_id,payment_id)
                REFERENCES public.payments(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_receivable_foreign
                FOREIGN KEY (tenant_id,receivable_id)
                REFERENCES public.receivables(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_association_foreign
                FOREIGN KEY (tenant_id,receipt_payment_association_id)
                REFERENCES public.receipt_payment_associations(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_receipt_foreign
                FOREIGN KEY (tenant_id,receipt_id)
                REFERENCES public.bank_receipt_evidence(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_cash_posting_foreign
                FOREIGN KEY (tenant_id,bank_receipt_cash_posting_id)
                REFERENCES public.bank_receipt_cash_postings(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_ar_recognition_foreign
                FOREIGN KEY (tenant_id,receivable_ar_recognition_id)
                REFERENCES public.receivable_ar_recognitions(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_clearing_account_foreign
                FOREIGN KEY (tenant_id,clearing_account_id)
                REFERENCES public.accounts(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_ar_account_foreign
                FOREIGN KEY (tenant_id,ar_control_account_id)
                REFERENCES public.accounts(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_journal_foreign
                FOREIGN KEY (tenant_id,journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_reversal_journal_foreign
                FOREIGN KEY (tenant_id,reversal_journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_created_actor_foreign
                FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_reversed_actor_foreign
                FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
              CONSTRAINT receivable_settlements_amount_positive_check
                CHECK (amount > 0),
              CONSTRAINT receivable_settlements_currency_check
                CHECK (currency='SAR'),
              CONSTRAINT receivable_settlements_operation_id_check
                CHECK (settlement_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              CONSTRAINT receivable_settlements_status_check
                CHECK (status IN ('posted','reversed')),
              CONSTRAINT receivable_settlements_lifecycle_check CHECK (
                (status='posted'
                  AND reversal_operation_id IS NULL
                  AND reversal_journal_entry_id IS NULL
                  AND reversal_date IS NULL
                  AND reversal_reason IS NULL
                  AND reversed_by IS NULL
                  AND reversed_at IS NULL)
                OR
                (status='reversed'
                  AND reversal_operation_id IS NOT NULL
                  AND reversal_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                  AND reversal_journal_entry_id IS NOT NULL
                  AND reversal_date IS NOT NULL
                  AND btrim(reversal_reason)<>''
                  AND reversed_by IS NOT NULL
                  AND reversed_at IS NOT NULL)
              )
            );

            CREATE UNIQUE INDEX receivable_settlements_reversal_operation_unique
              ON public.receivable_settlements(tenant_id,reversal_operation_id)
              WHERE reversal_operation_id IS NOT NULL;
            CREATE INDEX receivable_settlements_receivable_capacity_index
              ON public.receivable_settlements(tenant_id,receivable_id,id)
              WHERE status='posted';
            CREATE INDEX receivable_settlements_cash_capacity_index
              ON public.receivable_settlements(
                tenant_id,bank_receipt_cash_posting_id,id
              ) WHERE status='posted';

            INSERT INTO public.accounting_source_types(
              origin,key,owner_module,description
            ) VALUES (
              'business','receivable_settlement','settlement',
              'Receivable Settlement owned Journal'
            );
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.receivable_settlement_history_guard()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000',
                  MESSAGE='Receivable Settlement deletion is forbidden';
              END IF;

              IF TG_OP='INSERT' THEN
                IF NEW.status<>'posted' THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Receivable Settlement must initially be posted';
                END IF;
                RETURN NEW;
              END IF;

              IF (
                NEW.id,NEW.tenant_id,NEW.settlement_operation_id,
                NEW.payment_allocation_id,NEW.payment_id,NEW.receivable_id,
                NEW.receipt_payment_association_id,NEW.receipt_id,
                NEW.bank_receipt_cash_posting_id,
                NEW.receivable_ar_recognition_id,NEW.amount,NEW.currency,
                NEW.accounting_date,NEW.clearing_account_id,
                NEW.ar_control_account_id,NEW.journal_entry_id,
                NEW.created_by,NEW.created_at
              ) IS DISTINCT FROM (
                OLD.id,OLD.tenant_id,OLD.settlement_operation_id,
                OLD.payment_allocation_id,OLD.payment_id,OLD.receivable_id,
                OLD.receipt_payment_association_id,OLD.receipt_id,
                OLD.bank_receipt_cash_posting_id,
                OLD.receivable_ar_recognition_id,OLD.amount,OLD.currency,
                OLD.accounting_date,OLD.clearing_account_id,
                OLD.ar_control_account_id,OLD.journal_entry_id,
                OLD.created_by,OLD.created_at
              ) THEN
                RAISE EXCEPTION USING ERRCODE='55000',
                  MESSAGE='Receivable Settlement canonical truth is immutable';
              END IF;

              IF NOT (OLD.status='posted' AND NEW.status='reversed') THEN
                RAISE EXCEPTION USING ERRCODE='55000',
                  MESSAGE='Unsupported Receivable Settlement lifecycle mutation';
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER receivable_settlements_history_guard
              BEFORE INSERT OR UPDATE OR DELETE
              ON public.receivable_settlements
              FOR EACH ROW EXECUTE FUNCTION
                public.receivable_settlement_history_guard();

            CREATE OR REPLACE FUNCTION public.validate_receivable_settlement(
              settlement_tenant char(26),settlement_id char(26)
            ) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE s public.receivable_settlements%ROWTYPE;
            DECLARE p public.payments%ROWTYPE;
            DECLARE r public.receivables%ROWTYPE;
            DECLARE a public.payment_allocations%ROWTYPE;
            DECLARE x public.receipt_payment_associations%ROWTYPE;
            DECLARE e public.bank_receipt_evidence%ROWTYPE;
            DECLARE c public.bank_receipt_cash_postings%ROWTYPE;
            DECLARE ar public.receivable_ar_recognitions%ROWTYPE;
            DECLARE j public.journal_entries%ROWTYPE;
            DECLARE rev public.journal_entries%ROWTYPE;
            DECLARE period_count integer;
            DECLARE receivable_used numeric(19,2);
            DECLARE cash_used numeric(19,2);
            DECLARE line_count integer;
            BEGIN
              SELECT * INTO s FROM public.receivable_settlements
              WHERE tenant_id=settlement_tenant AND id=settlement_id;
              IF NOT FOUND THEN RETURN; END IF;

              SELECT * INTO p FROM public.payments
              WHERE tenant_id=s.tenant_id AND id=s.payment_id FOR UPDATE;
              SELECT * INTO r FROM public.receivables
              WHERE tenant_id=s.tenant_id AND id=s.receivable_id FOR UPDATE;
              SELECT * INTO a FROM public.payment_allocations
              WHERE tenant_id=s.tenant_id AND id=s.payment_allocation_id
              FOR UPDATE;
              SELECT * INTO x FROM public.receipt_payment_associations
              WHERE tenant_id=s.tenant_id
                AND id=s.receipt_payment_association_id FOR UPDATE;
              SELECT * INTO c FROM public.bank_receipt_cash_postings
              WHERE tenant_id=s.tenant_id
                AND id=s.bank_receipt_cash_posting_id FOR UPDATE;
              SELECT * INTO ar FROM public.receivable_ar_recognitions
              WHERE tenant_id=s.tenant_id
                AND id=s.receivable_ar_recognition_id FOR UPDATE;

              SELECT * INTO e FROM public.bank_receipt_evidence
              WHERE tenant_id=s.tenant_id AND id=s.receipt_id;

              IF p.id IS NULL OR r.id IS NULL OR a.id IS NULL OR x.id IS NULL
                 OR e.id IS NULL OR c.id IS NULL OR ar.id IS NULL
                 OR a.payment_id<>p.id OR a.receivable_id<>r.id
                 OR x.payment_id<>p.id OR x.receipt_id<>e.id
                 OR c.receipt_id<>e.id OR ar.receivable_id<>r.id
                 OR s.amount<>a.amount
                 OR s.currency<>'SAR' OR p.currency<>'SAR'
                 OR r.currency<>'SAR' OR x.currency<>'SAR'
                 OR e.currency<>'SAR' OR c.currency<>'SAR'
                 OR ar.currency<>'SAR'
                 OR p.customer_id<>r.customer_id
                 OR s.clearing_account_id<>c.clearing_account_id
                 OR s.ar_control_account_id<>ar.ar_control_account_id
                 OR s.accounting_date<>greatest(c.accounting_date,ar.accounting_date)
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Receivable Settlement provenance is inconsistent';
              END IF;

              SELECT count(*) INTO period_count
              FROM public.accounting_periods ap
              WHERE ap.tenant_id=s.tenant_id
                AND s.accounting_date BETWEEN ap.start_date AND ap.end_date
                AND ap.status='open';
              IF period_count<>1 THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Receivable Settlement requires exactly one open period';
              END IF;

              SELECT * INTO j FROM public.journal_entries
              WHERE tenant_id=s.tenant_id AND id=s.journal_entry_id FOR UPDATE;
              IF j.id IS NULL OR j.status<>'posted' OR j.origin<>'business'
                 OR j.source_type<>'receivable_settlement'
                 OR j.source_id<>s.id OR j.entry_date<>s.accounting_date
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Receivable Settlement Journal provenance is invalid';
              END IF;

              SELECT count(*) INTO line_count FROM public.journal_lines l
              WHERE l.tenant_id=s.tenant_id AND l.journal_entry_id=j.id
                AND (
                  (l.line_number=1 AND l.account_id=s.clearing_account_id
                    AND l.debit=s.amount AND l.credit=0)
                  OR
                  (l.line_number=2 AND l.account_id=s.ar_control_account_id
                    AND l.debit=0 AND l.credit=s.amount)
                );
              IF line_count<>2 OR (
                SELECT count(*) FROM public.journal_lines l
                WHERE l.tenant_id=s.tenant_id AND l.journal_entry_id=j.id
              )<>2 THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Receivable Settlement Journal grammar is invalid';
              END IF;

              IF s.status='posted' THEN
                IF a.status<>'effective' OR p.status<>'received'
                   OR r.status<>'recognized' OR x.status<>'effective'
                   OR e.status<>'effective' OR c.status<>'posted'
                   OR ar.status<>'posted'
                THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Posted Settlement requires effective upstream truth';
                END IF;

                IF EXISTS(
                  SELECT 1 FROM public.journal_entries q
                  WHERE q.tenant_id=s.tenant_id
                    AND q.reverses_journal_entry_id=s.journal_entry_id
                ) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Posted Settlement Journal cannot have a reversal';
                END IF;

                SELECT coalesce(sum(amount),0) INTO receivable_used
                FROM public.receivable_settlements q
                WHERE q.tenant_id=s.tenant_id
                  AND q.receivable_id=s.receivable_id AND q.status='posted';
                SELECT coalesce(sum(amount),0) INTO cash_used
                FROM public.receivable_settlements q
                WHERE q.tenant_id=s.tenant_id
                  AND q.bank_receipt_cash_posting_id=
                    s.bank_receipt_cash_posting_id AND q.status='posted';
                IF receivable_used>ar.receivable_amount THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Receivable Settlement exceeds AR capacity';
                END IF;
                IF cash_used>c.amount THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Receivable Settlement exceeds Cash capacity';
                END IF;
              ELSE
                SELECT * INTO rev FROM public.journal_entries
                WHERE tenant_id=s.tenant_id
                  AND id=s.reversal_journal_entry_id FOR UPDATE;
                IF rev.id IS NULL OR rev.status<>'posted'
                   OR rev.origin<>'reversal'
                   OR rev.source_type<>'journal_entry'
                   OR rev.source_id<>j.id
                   OR rev.reverses_journal_entry_id<>j.id
                   OR rev.entry_date<>s.reversal_date
                   OR rev.reversal_reason<>s.reversal_reason
                THEN
                  RAISE EXCEPTION USING ERRCODE='23514',
                    MESSAGE='Settlement reversal Journal provenance is invalid';
                END IF;
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION
              public.receivable_settlement_final_state()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE settlement_id char(26);
            DECLARE tenant_id char(26);
            BEGIN
              tenant_id:=COALESCE(NEW.tenant_id,OLD.tenant_id);
              IF TG_TABLE_NAME='receivable_settlements' THEN
                settlement_id:=COALESCE(NEW.id,OLD.id);
              ELSIF NEW.source_type='receivable_settlement' THEN
                settlement_id:=NEW.source_id;
              ELSIF NEW.reverses_journal_entry_id IS NOT NULL THEN
                SELECT s.id INTO settlement_id
                FROM public.receivable_settlements s
                WHERE s.tenant_id=NEW.tenant_id
                  AND s.journal_entry_id=NEW.reverses_journal_entry_id;
              END IF;
              IF settlement_id IS NOT NULL THEN
                PERFORM public.validate_receivable_settlement(
                  tenant_id,settlement_id
                );
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER receivable_settlements_final_state
              AFTER INSERT OR UPDATE ON public.receivable_settlements
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_settlement_final_state();
            CREATE CONSTRAINT TRIGGER receivable_settlement_journals_final_state
              AFTER INSERT OR UPDATE ON public.journal_entries
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_settlement_final_state();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION
              public.receivable_settlement_parent_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF TG_TABLE_NAME='payment_allocations'
                 AND OLD.status='effective' AND NEW.status='cancelled'
                 AND EXISTS(
                   SELECT 1 FROM public.receivable_settlements s
                   WHERE s.tenant_id=OLD.tenant_id
                     AND s.payment_allocation_id=OLD.id AND s.status='posted'
                 ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Posted Settlement blocks Allocation cancellation';
              ELSIF TG_TABLE_NAME='receipt_payment_associations'
                 AND OLD.status='effective' AND NEW.status='cancelled'
                 AND EXISTS(
                   SELECT 1 FROM public.receivable_settlements s
                   WHERE s.tenant_id=OLD.tenant_id
                     AND s.receipt_payment_association_id=OLD.id
                     AND s.status='posted'
                 ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Posted Settlement blocks Receipt association cancellation';
              ELSIF TG_TABLE_NAME='bank_receipt_cash_postings'
                 AND OLD.status='posted' AND NEW.status='reversed'
                 AND EXISTS(
                   SELECT 1 FROM public.receivable_settlements s
                   WHERE s.tenant_id=OLD.tenant_id
                     AND s.bank_receipt_cash_posting_id=OLD.id
                     AND s.status='posted'
                 ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Posted Settlement blocks Cash Posting reversal';
              ELSIF TG_TABLE_NAME='receivable_ar_recognitions'
                 AND OLD.status='posted' AND NEW.status='reversed'
                 AND EXISTS(
                   SELECT 1 FROM public.receivable_settlements s
                   WHERE s.tenant_id=OLD.tenant_id
                     AND s.receivable_ar_recognition_id=OLD.id
                     AND s.status='posted'
                 ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Posted Settlement blocks Receivable AR reversal';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER payment_allocations_settlement_guard
              BEFORE UPDATE ON public.payment_allocations FOR EACH ROW
              EXECUTE FUNCTION
                public.receivable_settlement_parent_lifecycle_guard();
            CREATE TRIGGER receipt_associations_settlement_guard
              BEFORE UPDATE ON public.receipt_payment_associations FOR EACH ROW
              EXECUTE FUNCTION
                public.receivable_settlement_parent_lifecycle_guard();
            CREATE TRIGGER cash_postings_settlement_guard
              BEFORE UPDATE ON public.bank_receipt_cash_postings FOR EACH ROW
              EXECUTE FUNCTION
                public.receivable_settlement_parent_lifecycle_guard();
            CREATE TRIGGER receivable_ar_settlement_guard
              BEFORE UPDATE ON public.receivable_ar_recognitions FOR EACH ROW
              EXECUTE FUNCTION
                public.receivable_settlement_parent_lifecycle_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_event_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_event_check CHECK(event IN (
                'accounting.activated',
                'account.created','account.updated','account.archived','account.restored',
                'journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed',
                'period.created','period.boundaries_changed','period.closed','period.reopened',
                'opening_balance.created','opening_balance.draft_deleted','opening_balance.posted',
                'opening_balance.reversed','opening_balance.reactivated',
                'performance_accounting.recognized',
                'performance_accounting.reversed',
                'performance_accounting.corrected',
                'receivable_ar.recognized','receivable_ar.reversed',
                'receivable_settlement.posted','receivable_settlement.reversed'
              ));
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_subject_type_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_subject_type_check CHECK(
                subject_type IN (
                  'accounting_settings','account','journal_entry',
                  'accounting_period','opening_balance_operation',
                  'performance_accounting_recognition',
                  'receivable_ar_recognition','receivable_settlement'
                )
              );

            CREATE OR REPLACE FUNCTION public.validate_accounting_audit_subject()
            RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE valid boolean:=false;
            BEGIN
              valid:=CASE NEW.subject_type
                WHEN 'accounting_settings' THEN EXISTS(
                  SELECT 1 FROM public.accounting_settings
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'account' THEN EXISTS(
                  SELECT 1 FROM public.accounts
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'journal_entry' THEN EXISTS(
                  SELECT 1 FROM public.journal_entries
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'accounting_period' THEN EXISTS(
                  SELECT 1 FROM public.accounting_periods
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'opening_balance_operation' THEN EXISTS(
                  SELECT 1 FROM public.opening_balance_operations
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'performance_accounting_recognition' THEN EXISTS(
                  SELECT 1 FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'receivable_ar_recognition' THEN EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                WHEN 'receivable_settlement' THEN EXISTS(
                  SELECT 1 FROM public.receivable_settlements
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id)
                ELSE false
              END;
              IF NOT valid THEN
                RAISE EXCEPTION USING ERRCODE='23503',
                  MESSAGE='accounting audit subject missing or cross-tenant';
              END IF;
              IF NOT (
                (NEW.event LIKE 'account.%' AND NEW.subject_type='account')
                OR (NEW.event LIKE 'journal.%'
                  AND NEW.subject_type='journal_entry')
                OR (NEW.event LIKE 'period.%'
                  AND NEW.subject_type='accounting_period')
                OR (NEW.event LIKE 'opening_balance.%'
                  AND NEW.subject_type='opening_balance_operation')
                OR (NEW.event LIKE 'performance_accounting.%'
                  AND NEW.subject_type='performance_accounting_recognition')
                OR (NEW.event LIKE 'receivable_ar.%'
                  AND NEW.subject_type='receivable_ar_recognition')
                OR (NEW.event LIKE 'receivable_settlement.%'
                  AND NEW.subject_type='receivable_settlement')
                OR (NEW.event='accounting.activated'
                  AND NEW.subject_type='accounting_settings')
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='accounting audit event/subject mismatch';
              END IF;
              RETURN NEW;
            END $$;
            SQL);

        $this->hardenRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }
        if (DB::table('receivable_settlements')->exists()) {
            throw new RuntimeException(
                'Receivable Settlement rollback refused while historical Settlement exists.',
            );
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.receivable_settlement_parent_lifecycle_guard()
              CASCADE;
            DROP FUNCTION public.receivable_settlement_final_state() CASCADE;
            DROP FUNCTION public.validate_receivable_settlement(character,character);
            DROP FUNCTION public.receivable_settlement_history_guard() CASCADE;
            DROP TABLE public.receivable_settlements;
            ALTER TABLE public.accounting_source_types
              DISABLE TRIGGER accounting_source_types_immutable_delete;
            DELETE FROM public.accounting_source_types
              WHERE origin='business' AND key='receivable_settlement';
            ALTER TABLE public.accounting_source_types
              ENABLE TRIGGER accounting_source_types_immutable_delete;
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_event_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_event_check CHECK(event IN (
                'accounting.activated',
                'account.created','account.updated','account.archived','account.restored',
                'journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed',
                'period.created','period.boundaries_changed','period.closed','period.reopened',
                'opening_balance.created','opening_balance.draft_deleted','opening_balance.posted',
                'opening_balance.reversed','opening_balance.reactivated',
                'performance_accounting.recognized',
                'performance_accounting.reversed',
                'performance_accounting.corrected',
                'receivable_ar.recognized','receivable_ar.reversed'
              ));
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_subject_type_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_subject_type_check CHECK(
                subject_type IN (
                  'accounting_settings','account','journal_entry',
                  'accounting_period','opening_balance_operation',
                  'performance_accounting_recognition',
                  'receivable_ar_recognition'
                )
              );

            CREATE OR REPLACE FUNCTION public.validate_accounting_audit_subject()
            RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE valid boolean:=false;
            BEGIN
              valid:=CASE NEW.subject_type
                WHEN 'accounting_settings' THEN EXISTS(
                  SELECT 1 FROM public.accounting_settings
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'account' THEN EXISTS(
                  SELECT 1 FROM public.accounts
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'journal_entry' THEN EXISTS(
                  SELECT 1 FROM public.journal_entries
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'accounting_period' THEN EXISTS(
                  SELECT 1 FROM public.accounting_periods
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'opening_balance_operation' THEN EXISTS(
                  SELECT 1 FROM public.opening_balance_operations
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'performance_accounting_recognition' THEN EXISTS(
                  SELECT 1 FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                WHEN 'receivable_ar_recognition' THEN EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                ELSE false
              END;

              IF NOT valid THEN
                RAISE EXCEPTION USING
                  ERRCODE='23503',
                  MESSAGE='accounting audit subject missing or cross-tenant';
              END IF;

              IF NOT (
                (NEW.event LIKE 'account.%' AND NEW.subject_type='account')
                OR
                (NEW.event LIKE 'journal.%'
                  AND NEW.subject_type='journal_entry')
                OR
                (NEW.event LIKE 'period.%'
                  AND NEW.subject_type='accounting_period')
                OR
                (NEW.event LIKE 'opening_balance.%'
                  AND NEW.subject_type='opening_balance_operation')
                OR
                (NEW.event LIKE 'performance_accounting.%'
                  AND NEW.subject_type='performance_accounting_recognition')
                OR
                (NEW.event LIKE 'receivable_ar.%'
                  AND NEW.subject_type='receivable_ar_recognition')
                OR
                (NEW.event='accounting.activated'
                  AND NEW.subject_type='accounting_settings')
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='accounting audit event/subject mismatch';
              END IF;

              RETURN NEW;
            END $$;
            SQL);
    }

    private function hardenRuntimePrivileges(): void
    {
        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        DB::unprepared(
            "REVOKE ALL ON TABLE public.receivable_settlements FROM {$identifier}",
        );
        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE public.receivable_settlements TO {$identifier}",
        );
        foreach ([
            'public.receivable_settlement_history_guard()',
            'public.validate_receivable_settlement(character,character)',
            'public.receivable_settlement_final_state()',
            'public.receivable_settlement_parent_lifecycle_guard()',
        ] as $function) {
            DB::unprepared(
                "REVOKE EXECUTE ON FUNCTION {$function} FROM PUBLIC,{$identifier}",
            );
        }
    }

    private function runtimeRole(): string
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! is_string($runtimeRole)
            || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole) !== 1) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.',
            );
        }
        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls
             FROM pg_catalog.pg_roles WHERE rolname=?',
            [$runtimeRole],
        );
        if ($role === null || $role->rolsuper || $role->rolcreaterole
            || $role->rolcreatedb || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException(
                'Settlement runtime role must exist and remain unprivileged.',
            );
        }

        return $runtimeRole;
    }

    private function skipIsolatedTestSchema(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivable Settlement v1 requires PostgreSQL.');
        }
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema === 'public') {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException(
            'Receivable Settlement v1 requires the public PostgreSQL schema.',
        );
    }
};
