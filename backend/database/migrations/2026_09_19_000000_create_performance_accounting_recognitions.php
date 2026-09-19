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
            CREATE TABLE public.performance_accounting_recognitions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              unit_handover_acceptance_id char(26) NOT NULL,
              performance_consideration_transition_id char(26) NOT NULL,
              recognition_kind text NOT NULL CHECK (recognition_kind IN ('original','accounting_correction')),
              performance_accounting_operation_id char(26),
              performance_accounting_correction_operation_id char(26),
              root_recognition_id char(26) NOT NULL,
              predecessor_recognition_id char(26),
              performance_amount numeric(19,2) NOT NULL CHECK (performance_amount > 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              accounting_date date NOT NULL,
              contract_asset_amount numeric(19,2) NOT NULL CHECK (contract_asset_amount >= 0),
              contract_liability_release_amount numeric(19,2) NOT NULL CHECK (contract_liability_release_amount >= 0),
              revenue_amount numeric(19,2) NOT NULL CHECK (revenue_amount > 0),
              performance_accounting_policy_id char(26) NOT NULL,
              performance_accounting_policy_version integer NOT NULL CHECK (performance_accounting_policy_version > 0),
              contract_asset_account_id char(26),
              revenue_account_id char(26) NOT NULL,
              journal_entry_id char(26) NOT NULL,
              status text NOT NULL CHECK (status IN ('posted','reversed')),
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              reversal_operation_id char(26),
              reversal_journal_entry_id char(26),
              reversed_by bigint,
              reversed_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,journal_entry_id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,unit_handover_acceptance_id)
                REFERENCES public.unit_handover_acceptances(tenant_id,id),
              FOREIGN KEY (tenant_id,performance_consideration_transition_id)
                REFERENCES public.contract_consideration_transitions(tenant_id,id),
              FOREIGN KEY (tenant_id,performance_accounting_policy_id)
                REFERENCES public.performance_accounting_policies(tenant_id,id),
              FOREIGN KEY (tenant_id,contract_asset_account_id)
                REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,revenue_account_id)
                REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,reversal_journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,root_recognition_id)
                REFERENCES public.performance_accounting_recognitions(tenant_id,id)
                DEFERRABLE INITIALLY DEFERRED,
              FOREIGN KEY (tenant_id,predecessor_recognition_id)
                REFERENCES public.performance_accounting_recognitions(tenant_id,id)
                DEFERRABLE INITIALLY DEFERRED,
              CHECK (performance_amount = contract_asset_amount + contract_liability_release_amount),
              CHECK (revenue_amount = performance_amount),
              CHECK (
                (contract_asset_amount=0 AND contract_asset_account_id IS NULL)
                OR
                (contract_asset_amount>0 AND contract_asset_account_id IS NOT NULL)
              ),
              CHECK (
                (recognition_kind='original'
                  AND predecessor_recognition_id IS NULL
                  AND root_recognition_id=id
                  AND performance_accounting_operation_id IS NOT NULL
                  AND performance_accounting_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                  AND performance_accounting_correction_operation_id IS NULL)
                OR
                (recognition_kind='accounting_correction'
                  AND predecessor_recognition_id IS NOT NULL
                  AND performance_accounting_operation_id IS NULL
                  AND performance_accounting_correction_operation_id IS NOT NULL
                  AND performance_accounting_correction_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
              ),
              CHECK (
                (status='posted'
                  AND reversal_operation_id IS NULL
                  AND reversal_journal_entry_id IS NULL
                  AND reversed_by IS NULL
                  AND reversed_at IS NULL)
                OR
                (status='reversed'
                  AND reversal_operation_id IS NOT NULL
                  AND reversal_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                  AND reversal_journal_entry_id IS NOT NULL
                  AND reversed_by IS NOT NULL
                  AND reversed_at IS NOT NULL)
              )
            );

            CREATE UNIQUE INDEX performance_accounting_original_source_unique
              ON public.performance_accounting_recognitions(tenant_id,unit_handover_acceptance_id)
              WHERE recognition_kind='original';
            CREATE UNIQUE INDEX performance_accounting_original_transition_unique
              ON public.performance_accounting_recognitions(tenant_id,performance_consideration_transition_id)
              WHERE recognition_kind='original';
            CREATE UNIQUE INDEX performance_accounting_operation_unique
              ON public.performance_accounting_recognitions(tenant_id,performance_accounting_operation_id)
              WHERE performance_accounting_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX performance_accounting_correction_operation_unique
              ON public.performance_accounting_recognitions(tenant_id,performance_accounting_correction_operation_id)
              WHERE performance_accounting_correction_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX performance_accounting_reversal_operation_unique
              ON public.performance_accounting_recognitions(tenant_id,reversal_operation_id)
              WHERE reversal_operation_id IS NOT NULL;
            CREATE UNIQUE INDEX performance_accounting_direct_successor_unique
              ON public.performance_accounting_recognitions(tenant_id,predecessor_recognition_id)
              WHERE predecessor_recognition_id IS NOT NULL;
            CREATE UNIQUE INDEX performance_accounting_effective_leaf_unique
              ON public.performance_accounting_recognitions(tenant_id,root_recognition_id)
              WHERE status='posted';
            CREATE INDEX performance_accounting_source_history
              ON public.performance_accounting_recognitions(
                tenant_id,unit_handover_acceptance_id,root_recognition_id,created_at,id
              );

            INSERT INTO public.accounting_source_types(origin,key,owner_module,description)
              VALUES(
                'business',
                'performance_accounting_recognition',
                'accounting_recognition',
                'Performance Accounting Recognition owned Journal'
              );

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
                'performance_accounting.corrected'
              ));
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_subject_type_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_subject_type_check CHECK(subject_type IN (
                'accounting_settings','account','journal_entry','accounting_period',
                'opening_balance_operation','performance_accounting_recognition'
              ));

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
                WHEN 'performance_accounting_recognition' THEN EXISTS(
                  SELECT 1 FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=NEW.subject_id
                )
                ELSE false END;
              IF NOT valid THEN
                RAISE EXCEPTION USING ERRCODE='23503',MESSAGE='accounting audit subject missing or cross-tenant';
              END IF;
              IF NOT (
                (NEW.event LIKE 'account.%' AND NEW.subject_type='account')
                OR (NEW.event LIKE 'journal.%' AND NEW.subject_type='journal_entry')
                OR (NEW.event LIKE 'period.%' AND NEW.subject_type='accounting_period')
                OR (NEW.event LIKE 'opening_balance.%' AND NEW.subject_type='opening_balance_operation')
                OR (NEW.event LIKE 'performance_accounting.%' AND NEW.subject_type='performance_accounting_recognition')
                OR (NEW.event='accounting.activated' AND NEW.subject_type='accounting_settings')
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='accounting audit event/subject mismatch';
              END IF;
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION public.performance_accounting_recognition_history_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='Performance Accounting Recognition deletion is forbidden';
              END IF;
              IF TG_OP='INSERT' AND NEW.status<>'posted' THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting Recognition must start posted';
              END IF;
              IF TG_OP='UPDATE' AND (
                OLD.status<>'posted' OR NEW.status<>'reversed'
                OR (to_jsonb(NEW) - ARRAY[
                    'status','reversal_operation_id','reversal_journal_entry_id','reversed_by','reversed_at'
                  ]) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY[
                    'status','reversal_operation_id','reversal_journal_entry_id','reversed_by','reversed_at'
                  ])
              ) THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='Performance Accounting Recognition canonical history is immutable';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER performance_accounting_recognition_history
              BEFORE INSERT OR UPDATE OR DELETE ON public.performance_accounting_recognitions
              FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_recognition_history_guard();

            CREATE OR REPLACE FUNCTION public.validate_performance_accounting_recognition(
              p_tenant char(26),
              p_recognition char(26)
            ) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE
              r public.performance_accounting_recognitions%ROWTYPE;
              a public.unit_handover_acceptances%ROWTYPE;
              t public.contract_consideration_transitions%ROWTYPE;
              p public.contract_consideration_positions%ROWTYPE;
              adoption public.performance_accounting_adoptions%ROWTYPE;
              policy public.performance_accounting_policies%ROWTYPE;
              j public.journal_entries%ROWTYPE;
              predecessor public.performance_accounting_recognitions%ROWTYPE;
              root_row public.performance_accounting_recognitions%ROWTYPE;
              reversal public.journal_entries%ROWTYPE;
              u numeric(19,2);
              b numeric(19,2);
              origin_total numeric(19,2);
              consumption_total numeric(19,2);
              liability_accounts integer;
              expected_lines integer;
              actual_lines integer;
              mapped numeric(19,2);
              edge record;
              grouping record;
            BEGIN
              SELECT * INTO r FROM public.performance_accounting_recognitions
                WHERE tenant_id=p_tenant AND id=p_recognition;
              IF NOT FOUND THEN
                RETURN;
              END IF;

              SELECT * INTO a FROM public.unit_handover_acceptances
                WHERE tenant_id=r.tenant_id AND id=r.unit_handover_acceptance_id;
              SELECT * INTO t FROM public.contract_consideration_transitions
                WHERE tenant_id=r.tenant_id AND id=r.performance_consideration_transition_id;
              SELECT * INTO p FROM public.contract_consideration_positions
                WHERE tenant_id=r.tenant_id AND id=t.position_id;
              SELECT * INTO adoption FROM public.performance_accounting_adoptions
                WHERE tenant_id=r.tenant_id AND contract_id=r.contract_id;
              SELECT * INTO policy FROM public.performance_accounting_policies
                WHERE tenant_id=r.tenant_id AND id=r.performance_accounting_policy_id;
              SELECT * INTO j FROM public.journal_entries
                WHERE tenant_id=r.tenant_id AND id=r.journal_entry_id;
              SELECT * INTO root_row FROM public.performance_accounting_recognitions
                WHERE tenant_id=r.tenant_id AND id=r.root_recognition_id;

              IF a.id IS NULL OR t.id IS NULL OR p.id IS NULL OR adoption.id IS NULL
                 OR policy.id IS NULL OR j.id IS NULL OR root_row.id IS NULL
                 OR a.contract_id<>r.contract_id OR t.contract_id<>r.contract_id
                 OR p.contract_id<>r.contract_id
                 OR t.source_type<>'UNIT_HANDOVER_ACCEPTANCE'
                 OR t.source_id<>a.id
                 OR a.currency<>'SAR' OR t.currency<>'SAR' OR r.currency<>'SAR'
                 OR a.performance_amount IS DISTINCT FROM r.performance_amount
                 OR t.transition_amount IS DISTINCT FROM r.performance_amount
                 OR a.performance_date<>r.accounting_date
                 OR t.economic_date<>r.accounting_date
                 OR adoption.consideration_position_id<>p.id
                 OR adoption.status<>'ADOPTED'
                 OR adoption.accounting_scope_version<>'PERFORMANCE_ACCOUNTING_V1'
                 OR policy.policy_version<>r.performance_accounting_policy_version
                 OR r.accounting_date<policy.effective_from
                 OR (policy.effective_to IS NOT NULL AND r.accounting_date>policy.effective_to)
                 OR policy.revenue_account_id<>r.revenue_account_id
                 OR (
                    r.contract_asset_amount>0
                    AND policy.contract_asset_control_account_id<>r.contract_asset_account_id
                 )
                 OR j.status<>'posted'
                 OR j.origin<>'business'
                 OR j.source_type<>'performance_accounting_recognition'
                 OR j.source_id<>r.id
                 OR j.entry_date<>r.accounting_date
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',
                  MESSAGE='Performance Accounting Recognition source, policy or Journal ownership is inconsistent';
              END IF;

              IF r.recognition_kind='original' THEN
                IF r.root_recognition_id<>r.id OR root_row.id<>r.id OR root_row.recognition_kind<>'original' THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting original root identity is inconsistent';
                END IF;
              ELSE
                SELECT * INTO predecessor FROM public.performance_accounting_recognitions
                  WHERE tenant_id=r.tenant_id AND id=r.predecessor_recognition_id;
                IF predecessor.id IS NULL
                   OR predecessor.root_recognition_id<>r.root_recognition_id
                   OR predecessor.status<>'reversed'
                   OR root_row.recognition_kind<>'original'
                   OR root_row.unit_handover_acceptance_id<>r.unit_handover_acceptance_id
                   OR root_row.performance_consideration_transition_id<>r.performance_consideration_transition_id
                   OR predecessor.unit_handover_acceptance_id<>r.unit_handover_acceptance_id
                   OR predecessor.performance_consideration_transition_id<>r.performance_consideration_transition_id
                   OR predecessor.performance_amount<>r.performance_amount
                   OR predecessor.accounting_date<>r.accounting_date
                   OR predecessor.contract_asset_amount<>r.contract_asset_amount
                   OR predecessor.contract_liability_release_amount<>r.contract_liability_release_amount
                   OR predecessor.revenue_amount<>r.revenue_amount
                THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting correction lineage is inconsistent';
                END IF;
              END IF;

              IF r.status='posted' AND (a.status<>'effective' OR t.status<>'effective') THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Effective Performance Accounting requires effective performance source truth';
              END IF;

              SELECT
                COALESCE(sum(e.consumed_amount) FILTER (
                  WHERE before_lot.semantic_position='UNPERFORMED_UNBILLED'
                    AND after_lot.semantic_position='EARNED_UNBILLED'
                ),0),
                COALESCE(sum(e.consumed_amount) FILTER (
                  WHERE before_lot.semantic_position='BILLED_UNEARNED'
                    AND after_lot.semantic_position='BILLED_EARNED'
                ),0)
              INTO u,b
              FROM public.contract_consideration_transition_lots e
              JOIN public.contract_consideration_lots before_lot
                ON before_lot.tenant_id=e.tenant_id AND before_lot.id=e.lot_id
              JOIN public.contract_consideration_lots after_lot
                ON after_lot.tenant_id=e.tenant_id AND after_lot.id=e.successor_lot_id
              WHERE e.tenant_id=r.tenant_id
                AND e.transition_id=r.performance_consideration_transition_id;

              IF u IS DISTINCT FROM r.contract_asset_amount
                 OR b IS DISTINCT FROM r.contract_liability_release_amount
                 OR u+b IS DISTINCT FROM r.revenue_amount
                 OR r.revenue_amount IS DISTINCT FROM r.performance_amount
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting amounts differ from exact Performance Transition grammar';
              END IF;

              SELECT COALESCE(sum(o.origin_amount),0) INTO origin_total
              FROM public.accounting_position_origins o
              WHERE o.tenant_id=r.tenant_id
                AND o.origin_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                AND o.origin_recognition_id=r.id
                AND o.position_type='CONTRACT_ASSET';

              IF origin_total IS DISTINCT FROM u
                 OR EXISTS (
                   SELECT 1
                   FROM public.accounting_position_origins o
                   JOIN public.contract_consideration_lots l
                     ON l.tenant_id=o.tenant_id AND l.id=o.consideration_lot_id
                   WHERE o.tenant_id=r.tenant_id
                     AND o.origin_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                     AND o.origin_recognition_id=r.id
                     AND (
                       o.position_type<>'CONTRACT_ASSET'
                       OR o.origin_journal_entry_id<>r.journal_entry_id
                       OR o.account_id IS DISTINCT FROM r.contract_asset_account_id
                       OR o.consideration_transition_id<>r.performance_consideration_transition_id
                       OR l.semantic_position<>'EARNED_UNBILLED'
                       OR o.economic_leg_identity<>
                         ('PA:ORIGIN:'||r.performance_consideration_transition_id||':'||o.consideration_lot_id)
                       OR (r.status='posted' AND o.status<>'effective')
                       OR (r.status='reversed' AND o.status<>'reversed')
                     )
                 )
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting Contract Asset origin graph is inconsistent';
              END IF;

              SELECT COALESCE(sum(c.amount),0) INTO consumption_total
              FROM public.accounting_position_consumptions c
              WHERE c.tenant_id=r.tenant_id
                AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                AND c.consuming_recognition_id=r.id;

              IF consumption_total IS DISTINCT FROM b THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting Contract Liability consumption total is inconsistent';
              END IF;

              FOR edge IN
                SELECT
                  e.lot_id,
                  e.successor_lot_id,
                  e.consumed_amount
                FROM public.contract_consideration_transition_lots e
                JOIN public.contract_consideration_lots before_lot
                  ON before_lot.tenant_id=e.tenant_id AND before_lot.id=e.lot_id
                JOIN public.contract_consideration_lots after_lot
                  ON after_lot.tenant_id=e.tenant_id AND after_lot.id=e.successor_lot_id
                WHERE e.tenant_id=r.tenant_id
                  AND e.transition_id=r.performance_consideration_transition_id
                  AND before_lot.semantic_position='BILLED_UNEARNED'
                  AND after_lot.semantic_position='BILLED_EARNED'
              LOOP
                SELECT COALESCE(sum(c.amount),0) INTO mapped
                FROM public.accounting_position_consumptions c
                JOIN public.accounting_position_origins o
                  ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                WHERE c.tenant_id=r.tenant_id
                  AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                  AND c.consuming_recognition_id=r.id
                  AND c.consideration_transition_id=r.performance_consideration_transition_id
                  AND c.consideration_lot_id=edge.successor_lot_id
                  AND o.position_type='CONTRACT_LIABILITY'
                  AND o.consideration_lot_id=edge.lot_id
                  AND c.economic_leg_identity=
                    ('PA:CONSUME:'||r.performance_consideration_transition_id||':'||edge.lot_id||':'||edge.successor_lot_id)
                  AND (
                    (r.status='posted' AND c.status='effective' AND o.status='effective')
                    OR
                    (r.status='reversed' AND c.status='reversed')
                  );

                IF mapped IS DISTINCT FROM edge.consumed_amount THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting liability consumption does not mirror exact economic predecessor causality';
                END IF;
              END LOOP;

              IF EXISTS (
                SELECT 1
                FROM public.accounting_position_consumptions c
                JOIN public.accounting_position_origins o
                  ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                WHERE c.tenant_id=r.tenant_id
                  AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                  AND c.consuming_recognition_id=r.id
                  AND (
                    c.consuming_journal_entry_id<>r.journal_entry_id
                    OR c.consideration_transition_id<>r.performance_consideration_transition_id
                    OR o.position_type<>'CONTRACT_LIABILITY'
                    OR NOT EXISTS (
                      SELECT 1
                      FROM public.contract_consideration_transition_lots e
                      JOIN public.contract_consideration_lots before_lot
                        ON before_lot.tenant_id=e.tenant_id AND before_lot.id=e.lot_id
                      JOIN public.contract_consideration_lots after_lot
                        ON after_lot.tenant_id=e.tenant_id AND after_lot.id=e.successor_lot_id
                      WHERE e.tenant_id=c.tenant_id
                        AND e.transition_id=c.consideration_transition_id
                        AND e.lot_id=o.consideration_lot_id
                        AND e.successor_lot_id=c.consideration_lot_id
                        AND before_lot.semantic_position='BILLED_UNEARNED'
                        AND after_lot.semantic_position='BILLED_EARNED'
                    )
                  )
              ) THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting contains alternate Contract Liability predecessor provenance';
              END IF;

              SELECT count(DISTINCT o.account_id) INTO liability_accounts
              FROM public.accounting_position_consumptions c
              JOIN public.accounting_position_origins o
                ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
              WHERE c.tenant_id=r.tenant_id
                AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                AND c.consuming_recognition_id=r.id;

              expected_lines := 1 + CASE WHEN u>0 THEN 1 ELSE 0 END + liability_accounts;
              SELECT count(*) INTO actual_lines FROM public.journal_lines
                WHERE tenant_id=r.tenant_id AND journal_entry_id=r.journal_entry_id;

              IF actual_lines<>expected_lines
                 OR (SELECT count(*) FROM public.journal_lines
                     WHERE tenant_id=r.tenant_id AND journal_entry_id=r.journal_entry_id
                       AND account_id=r.revenue_account_id AND debit=0 AND credit=r.revenue_amount)<>1
                 OR (u>0 AND (SELECT count(*) FROM public.journal_lines
                     WHERE tenant_id=r.tenant_id AND journal_entry_id=r.journal_entry_id
                       AND account_id=r.contract_asset_account_id AND debit=u AND credit=0)<>1)
              THEN
                RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting Journal grammar is inconsistent';
              END IF;

              FOR grouping IN
                SELECT o.account_id,sum(c.amount) AS amount
                FROM public.accounting_position_consumptions c
                JOIN public.accounting_position_origins o
                  ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                WHERE c.tenant_id=r.tenant_id
                  AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION'
                  AND c.consuming_recognition_id=r.id
                GROUP BY o.account_id
              LOOP
                IF (SELECT count(*) FROM public.journal_lines
                    WHERE tenant_id=r.tenant_id AND journal_entry_id=r.journal_entry_id
                      AND account_id=grouping.account_id
                      AND debit=grouping.amount AND credit=0)<>1 THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting liability debit grouping differs from exact predecessor accounts';
                END IF;
              END LOOP;

              IF r.status='reversed' THEN
                SELECT * INTO reversal FROM public.journal_entries
                  WHERE tenant_id=r.tenant_id AND id=r.reversal_journal_entry_id;
                IF reversal.id IS NULL OR reversal.status<>'posted'
                   OR reversal.origin<>'reversal'
                   OR reversal.source_type<>'journal_entry'
                   OR reversal.source_id<>r.journal_entry_id
                   OR reversal.reverses_journal_entry_id<>r.journal_entry_id
                   OR reversal.entry_date<>r.accounting_date
                THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Performance Accounting reversal Journal is inconsistent';
                END IF;
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION public.performance_accounting_recognition_final_state() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            DECLARE recognition_id char(26);
            BEGIN
              IF TG_TABLE_NAME='performance_accounting_recognitions' THEN
                recognition_id:=NEW.id;
              ELSIF TG_TABLE_NAME='unit_handover_acceptances' THEN
                FOR recognition_id IN
                  SELECT id FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND unit_handover_acceptance_id=NEW.id
                LOOP
                  PERFORM public.validate_performance_accounting_recognition(NEW.tenant_id,recognition_id);
                END LOOP;
                IF NEW.status='reversed' AND EXISTS(
                  SELECT 1 FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND unit_handover_acceptance_id=NEW.id
                    AND status='posted'
                ) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Reversed Handover cannot retain effective Performance Accounting';
                END IF;
                RETURN NULL;
              ELSIF TG_TABLE_NAME='contract_consideration_transitions' THEN
                FOR recognition_id IN
                  SELECT id FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND performance_consideration_transition_id=NEW.id
                LOOP
                  PERFORM public.validate_performance_accounting_recognition(NEW.tenant_id,recognition_id);
                END LOOP;
                IF NEW.status='reversed' AND EXISTS(
                  SELECT 1 FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND performance_consideration_transition_id=NEW.id
                    AND status='posted'
                ) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='Reversed Performance Transition cannot retain effective Performance Accounting';
                END IF;
                RETURN NULL;
              ELSIF TG_TABLE_NAME='accounting_position_origins' THEN
                IF NEW.origin_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  recognition_id:=NEW.origin_recognition_id;
                END IF;
              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                IF NEW.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  recognition_id:=NEW.consuming_recognition_id;
                END IF;
              ELSIF TG_TABLE_NAME='accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_id INTO recognition_id
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id AND id=NEW.origin_id
                  AND origin_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION';
              ELSIF TG_TABLE_NAME='accounting_position_consumption_journal_line_allocations' THEN
                SELECT c.consuming_recognition_id INTO recognition_id
                FROM public.accounting_position_consumptions c
                WHERE c.tenant_id=NEW.tenant_id AND c.id=NEW.consumption_id
                  AND c.consuming_recognition_type='PERFORMANCE_ACCOUNTING_RECOGNITION';
              END IF;

              IF recognition_id IS NOT NULL THEN
                PERFORM public.validate_performance_accounting_recognition(
                  COALESCE(NEW.tenant_id,OLD.tenant_id),
                  recognition_id
                );
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER performance_accounting_recognition_final
              AFTER INSERT OR UPDATE ON public.performance_accounting_recognitions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_handover_final
              AFTER UPDATE ON public.unit_handover_acceptances
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_transition_final
              AFTER UPDATE ON public.contract_consideration_transitions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_origin_final
              AFTER INSERT OR UPDATE ON public.accounting_position_origins
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_consumption_final
              AFTER INSERT OR UPDATE ON public.accounting_position_consumptions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_origin_allocation_final
              AFTER INSERT ON public.accounting_position_origin_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();
            CREATE CONSTRAINT TRIGGER performance_accounting_consumption_allocation_final
              AFTER INSERT ON public.accounting_position_consumption_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.performance_accounting_recognition_final_state();

            REVOKE ALL ON public.performance_accounting_recognitions FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION
              public.performance_accounting_recognition_history_guard(),
              public.validate_performance_accounting_recognition(character,character),
              public.performance_accounting_recognition_final_state()
              FROM PUBLIC;
            SQL);

        $this->hardenRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        if (DB::table('performance_accounting_recognitions')->exists()) {
            throw new RuntimeException(
                'Performance Accounting Recognition rollback refused while historical recognition exists.'
            );
        }

        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared("REVOKE INSERT,UPDATE ON TABLE
          public.accounting_position_origins,
          public.accounting_position_consumptions
          FROM {$identifier}");
        DB::unprepared("REVOKE INSERT ON TABLE
          public.accounting_position_origin_journal_line_allocations,
          public.accounting_position_consumption_journal_line_allocations
          FROM {$identifier}");
        DB::unprepared("GRANT SELECT ON TABLE
          public.accounting_position_origins,
          public.accounting_position_consumptions,
          public.accounting_position_origin_journal_line_allocations,
          public.accounting_position_consumption_journal_line_allocations
          TO {$identifier}");

        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.performance_accounting_recognition_final_state() CASCADE;
            DROP FUNCTION public.validate_performance_accounting_recognition(character,character);
            DROP FUNCTION public.performance_accounting_recognition_history_guard() CASCADE;
            DROP FUNCTION public.performance_accounting_runtime_provenance_guard() CASCADE;

            DROP TABLE public.performance_accounting_recognitions;

            ALTER TABLE public.accounting_source_types DISABLE TRIGGER accounting_source_types_immutable_delete;
            DELETE FROM public.accounting_source_types
              WHERE origin='business' AND key='performance_accounting_recognition';
            ALTER TABLE public.accounting_source_types ENABLE TRIGGER accounting_source_types_immutable_delete;

            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_event_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_event_check CHECK(event IN (
                'accounting.activated',
                'account.created','account.updated','account.archived','account.restored',
                'journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed',
                'period.created','period.boundaries_changed','period.closed','period.reopened',
                'opening_balance.created','opening_balance.draft_deleted','opening_balance.posted',
                'opening_balance.reversed','opening_balance.reactivated'
              ));
            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_subject_type_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_subject_type_check CHECK(subject_type IN (
                'accounting_settings','account','journal_entry','accounting_period','opening_balance_operation'
              ));

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
            SQL);
    }

    private function hardenRuntimePrivileges(): void
    {
        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        DB::unprepared("REVOKE ALL ON TABLE public.performance_accounting_recognitions FROM {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE public.performance_accounting_recognitions TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE
          public.accounting_position_origins,
          public.accounting_position_consumptions
          TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT ON TABLE
          public.accounting_position_origin_journal_line_allocations,
          public.accounting_position_consumption_journal_line_allocations
          TO {$identifier}");

        $guardSql = <<<'SQL'
            CREATE OR REPLACE FUNCTION public.performance_accounting_runtime_provenance_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE owner_type text;
            BEGIN
              IF current_user <> '__RUNTIME_LITERAL__' THEN
                RETURN NEW;
              END IF;

              IF TG_TABLE_NAME='accounting_position_origins' THEN
                IF NEW.origin_recognition_type<>'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Slice 2 runtime cannot create Receivable AR accounting provenance';
                END IF;
              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                IF NEW.consuming_recognition_type<>'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Slice 2 runtime cannot create Receivable AR accounting provenance';
                END IF;
              ELSIF TG_TABLE_NAME='accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_type INTO owner_type
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id AND id=NEW.origin_id;
                IF owner_type IS DISTINCT FROM 'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Slice 2 runtime cannot allocate Receivable AR accounting provenance';
                END IF;
              ELSE
                SELECT consuming_recognition_type INTO owner_type
                FROM public.accounting_position_consumptions
                WHERE tenant_id=NEW.tenant_id AND id=NEW.consumption_id;
                IF owner_type IS DISTINCT FROM 'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Slice 2 runtime cannot allocate Receivable AR accounting provenance';
                END IF;
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER performance_accounting_runtime_origin_guard
              BEFORE INSERT OR UPDATE ON public.accounting_position_origins
              FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_runtime_provenance_guard();
            CREATE TRIGGER performance_accounting_runtime_consumption_guard
              BEFORE INSERT OR UPDATE ON public.accounting_position_consumptions
              FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_runtime_provenance_guard();
            CREATE TRIGGER performance_accounting_runtime_origin_allocation_guard
              BEFORE INSERT ON public.accounting_position_origin_journal_line_allocations
              FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_runtime_provenance_guard();
            CREATE TRIGGER performance_accounting_runtime_consumption_allocation_guard
              BEFORE INSERT ON public.accounting_position_consumption_journal_line_allocations
              FOR EACH ROW EXECUTE FUNCTION public.performance_accounting_runtime_provenance_guard();

            REVOKE EXECUTE ON FUNCTION
              public.performance_accounting_recognition_history_guard(),
              public.validate_performance_accounting_recognition(character,character),
              public.performance_accounting_recognition_final_state(),
              public.performance_accounting_runtime_provenance_guard()
              FROM PUBLIC,__RUNTIME_IDENTIFIER__;
            SQL;

        $guardSql = str_replace(
            ['__RUNTIME_LITERAL__', '__RUNTIME_IDENTIFIER__'],
            [$runtimeRole, $identifier],
            $guardSql,
        );
        DB::unprepared($guardSql);
    }

    private function runtimeRole(): string
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (! is_string($runtimeRole)
            || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole) !== 1) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.'
            );
        }

        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?',
            [$runtimeRole],
        );

        if ($role === null || $role->rolsuper || $role->rolcreaterole
            || $role->rolcreatedb || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException(
                'Performance Accounting runtime role must exist and be unprivileged.'
            );
        }

        return $runtimeRole;
    }

    private function skipIsolatedTestSchema(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Performance Accounting Recognition v1 requires PostgreSQL.'
            );
        }

        $schema = DB::selectOne('SELECT current_schema() AS name')->name;

        if ($schema === 'public') {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException(
            'Performance Accounting Recognition v1 requires the public schema.'
        );
    }
};
