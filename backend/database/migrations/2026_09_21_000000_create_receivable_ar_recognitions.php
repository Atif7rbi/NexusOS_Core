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
            CREATE TABLE public.receivable_ar_recognitions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              contractual_billing_entitlement_id char(26) NOT NULL,
              receivable_id char(26) NOT NULL,
              billing_consideration_transition_id char(26) NOT NULL,
              recognition_kind text NOT NULL CHECK (recognition_kind='original'),
              receivable_ar_operation_id char(26) NOT NULL
                CHECK (receivable_ar_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              receivable_amount numeric(19,2) NOT NULL CHECK (receivable_amount > 0),
              contract_asset_release_amount numeric(19,2) NOT NULL
                CHECK (contract_asset_release_amount >= 0),
              contract_liability_creation_amount numeric(19,2) NOT NULL
                CHECK (contract_liability_creation_amount >= 0),
              currency char(3) NOT NULL CHECK (currency='SAR'),
              accounting_date date NOT NULL,
              receivable_ar_policy_id char(26) NOT NULL,
              receivable_ar_policy_version integer NOT NULL
                CHECK (receivable_ar_policy_version > 0),
              counterpart_policy_id char(26) NOT NULL,
              counterpart_policy_version integer NOT NULL
                CHECK (counterpart_policy_version > 0),
              ar_control_account_id char(26) NOT NULL,
              counterpart_contract_asset_account_id char(26) NOT NULL,
              contract_liability_account_id char(26),
              journal_entry_id char(26) NOT NULL,
              status text NOT NULL CHECK (status IN ('posted','reversed')),
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              reversal_operation_id char(26),
              reversal_journal_entry_id char(26),
              reversed_by bigint,
              reversed_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,contractual_billing_entitlement_id),
              UNIQUE (tenant_id,receivable_id),
              UNIQUE (tenant_id,billing_consideration_transition_id),
              UNIQUE (tenant_id,receivable_ar_operation_id),
              UNIQUE (tenant_id,journal_entry_id),
              FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,contractual_billing_entitlement_id)
                REFERENCES public.contractual_billing_entitlements(tenant_id,id),
              FOREIGN KEY (tenant_id,receivable_id)
                REFERENCES public.receivables(tenant_id,id),
              FOREIGN KEY (tenant_id,billing_consideration_transition_id)
                REFERENCES public.contract_consideration_transitions(tenant_id,id),
              FOREIGN KEY (tenant_id,receivable_ar_policy_id)
                REFERENCES public.receivable_ar_policies(tenant_id,id),
              FOREIGN KEY (tenant_id,counterpart_policy_id)
                REFERENCES public.receivable_ar_counterpart_policies(tenant_id,id),
              FOREIGN KEY (tenant_id,ar_control_account_id)
                REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,counterpart_contract_asset_account_id)
                REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,contract_liability_account_id)
                REFERENCES public.accounts(tenant_id,id),
              FOREIGN KEY (tenant_id,journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,reversal_journal_entry_id)
                REFERENCES public.journal_entries(tenant_id,id),
              FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id),
              CHECK (
                receivable_amount =
                  contract_asset_release_amount +
                  contract_liability_creation_amount
              ),
              CHECK (
                (contract_liability_creation_amount=0
                  AND contract_liability_account_id IS NULL)
                OR
                (contract_liability_creation_amount>0
                  AND contract_liability_account_id IS NOT NULL)
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

            CREATE UNIQUE INDEX receivable_ar_reversal_operation_unique
              ON public.receivable_ar_recognitions(tenant_id,reversal_operation_id)
              WHERE reversal_operation_id IS NOT NULL;

            CREATE INDEX receivable_ar_source_history
              ON public.receivable_ar_recognitions(
                tenant_id,contract_id,accounting_date,id
              );

            INSERT INTO public.accounting_source_types(
              origin,key,owner_module,description
            ) VALUES (
              'business',
              'receivable_ar_recognition',
              'accounting_recognition',
              'Receivable AR Recognition owned Journal'
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
                'performance_accounting.corrected',
                'receivable_ar.recognized',
                'receivable_ar.reversed'
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

            CREATE OR REPLACE FUNCTION
              public.receivable_ar_recognition_history_guard()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path=pg_catalog,public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE='55000',
                  MESSAGE='Receivable AR Recognition deletion is forbidden';
              END IF;

              IF TG_OP='INSERT' AND NEW.status<>'posted' THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Recognition must start posted';
              END IF;

              IF TG_OP='UPDATE' AND (
                OLD.status<>'posted'
                OR NEW.status<>'reversed'
                OR (to_jsonb(NEW) - ARRAY[
                    'status','reversal_operation_id',
                    'reversal_journal_entry_id','reversed_by','reversed_at'
                  ]) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY[
                    'status','reversal_operation_id',
                    'reversal_journal_entry_id','reversed_by','reversed_at'
                  ])
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE='55000',
                  MESSAGE='Receivable AR Recognition canonical history is immutable';
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER receivable_ar_recognition_history
              BEFORE INSERT OR UPDATE OR DELETE
              ON public.receivable_ar_recognitions
              FOR EACH ROW
              EXECUTE FUNCTION
                public.receivable_ar_recognition_history_guard();

            CREATE OR REPLACE FUNCTION public.validate_receivable_ar_recognition(
              p_tenant char(26),
              p_recognition char(26)
            ) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path=pg_catalog,public AS $$
            DECLARE
              r public.receivable_ar_recognitions%ROWTYPE;
              e public.contractual_billing_entitlements%ROWTYPE;
              l public.entitlement_receivable_links%ROWTYPE;
              rec public.receivables%ROWTYPE;
              t public.contract_consideration_transitions%ROWTYPE;
              p public.contract_consideration_positions%ROWTYPE;
              arp public.receivable_ar_policies%ROWTYPE;
              cp public.receivable_ar_counterpart_policies%ROWTYPE;
              j public.journal_entries%ROWTYPE;
              reversal public.journal_entries%ROWTYPE;
              asset_amount numeric(19,2);
              liability_amount numeric(19,2);
              origin_total numeric(19,2);
              consumption_total numeric(19,2);
              ar_debit numeric(19,2);
              journal_debits numeric(19,2);
              journal_credits numeric(19,2);
              ar_line_count integer;
              journal_line_count integer;
              asset_credit_account_count integer;
              expected_line_count integer;
            BEGIN
              SELECT * INTO r
              FROM public.receivable_ar_recognitions
              WHERE tenant_id=p_tenant AND id=p_recognition;

              IF NOT FOUND THEN
                RETURN;
              END IF;

              SELECT * INTO e
              FROM public.contractual_billing_entitlements
              WHERE tenant_id=r.tenant_id
                AND id=r.contractual_billing_entitlement_id;

              SELECT * INTO l
              FROM public.entitlement_receivable_links
              WHERE tenant_id=r.tenant_id
                AND entitlement_id=r.contractual_billing_entitlement_id;

              SELECT * INTO rec
              FROM public.receivables
              WHERE tenant_id=r.tenant_id AND id=r.receivable_id;

              SELECT * INTO t
              FROM public.contract_consideration_transitions
              WHERE tenant_id=r.tenant_id
                AND id=r.billing_consideration_transition_id;

              SELECT * INTO p
              FROM public.contract_consideration_positions
              WHERE tenant_id=r.tenant_id AND id=t.position_id;

              SELECT * INTO arp
              FROM public.receivable_ar_policies
              WHERE tenant_id=r.tenant_id
                AND id=r.receivable_ar_policy_id;

              SELECT * INTO cp
              FROM public.receivable_ar_counterpart_policies
              WHERE tenant_id=r.tenant_id
                AND id=r.counterpart_policy_id;

              SELECT * INTO j
              FROM public.journal_entries
              WHERE tenant_id=r.tenant_id AND id=r.journal_entry_id;

              IF e.id IS NULL OR l.id IS NULL OR rec.id IS NULL
                 OR t.id IS NULL OR p.id IS NULL
                 OR arp.id IS NULL OR cp.id IS NULL OR j.id IS NULL
                 OR e.contract_id<>r.contract_id
                 OR rec.contract_id<>r.contract_id
                 OR t.contract_id<>r.contract_id
                 OR p.contract_id<>r.contract_id
                 OR l.entitlement_id<>e.id
                 OR l.receivable_id<>rec.id
                 OR rec.recognition_operation_id
                    <>l.receivable_establishment_operation_id
                 OR rec.collection_id IS NOT NULL
                 OR rec.customer_id<>e.customer_id
                 OR rec.recognized_amount<>e.amount
                 OR rec.currency<>e.currency
                 OR rec.due_date<>e.economic_date
                 OR t.source_type<>'CONTRACTUAL_BILLING_ENTITLEMENT'
                 OR t.source_id<>e.id
                 OR t.transition_amount<>e.amount
                 OR t.economic_date<>e.economic_date
                 OR r.receivable_amount<>e.amount
                 OR r.accounting_date<>e.economic_date
                 OR e.currency<>'SAR'
                 OR rec.currency<>'SAR'
                 OR t.currency<>'SAR'
                 OR r.currency<>'SAR'
                 OR arp.policy_version<>r.receivable_ar_policy_version
                 OR cp.policy_version<>r.counterpart_policy_version
                 OR r.accounting_date<arp.effective_from
                 OR r.accounting_date<cp.effective_from
                 OR arp.ar_control_account_id<>r.ar_control_account_id
                 OR cp.contract_asset_control_account_id
                    <>r.counterpart_contract_asset_account_id
                 OR (
                    r.contract_liability_creation_amount>0
                    AND cp.contract_liability_control_account_id
                      <>r.contract_liability_account_id
                 )
                 OR j.status<>'posted'
                 OR j.origin<>'business'
                 OR j.source_type<>'receivable_ar_recognition'
                 OR j.source_id<>r.id
                 OR j.entry_date<>r.accounting_date
              THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR source, policy or Journal ownership is inconsistent';
              END IF;

              IF r.status='posted' THEN
                IF e.status<>'effective'
                   OR rec.status<>'recognized'
                   OR l.source_correction_operation_id IS NOT NULL
                   OR t.status<>'effective'
                THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Effective Receivable AR requires effective source truth';
                END IF;

                IF EXISTS (
                  SELECT 1
                  FROM public.journal_entries direct_reversal
                  WHERE direct_reversal.tenant_id=r.tenant_id
                    AND direct_reversal.reverses_journal_entry_id=r.journal_entry_id
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Posted Receivable AR cannot retain a reversed owned Journal';
                END IF;
              ELSE
                IF e.status<>'reversed'
                   OR rec.status<>'cancelled'
                   OR t.status<>'reversed'
                   OR e.reversal_operation_id IS DISTINCT FROM
                      r.reversal_operation_id
                   OR t.reversal_operation_id IS DISTINCT FROM
                      r.reversal_operation_id
                   OR l.source_correction_operation_id IS DISTINCT FROM
                      e.source_correction_operation_id
                   OR t.reversal_source_operation_id IS DISTINCT FROM
                      e.source_correction_operation_id
                   OR rec.cancelled_by IS DISTINCT FROM e.reversed_by
                   OR r.reversed_by IS DISTINCT FROM e.reversed_by
                   OR t.reversed_by IS DISTINCT FROM e.reversed_by
                   OR rec.cancelled_at IS DISTINCT FROM e.reversed_at
                   OR r.reversed_at IS DISTINCT FROM e.reversed_at
                   OR t.reversed_at IS DISTINCT FROM e.reversed_at
                   OR rec.cancellation_reason IS DISTINCT FROM
                      e.reversal_reason
                   OR t.reversal_reason IS DISTINCT FROM e.reversal_reason
                   OR t.reversal_reference IS DISTINCT FROM
                      e.source_rescission_reference
                THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Reversed Receivable AR must match authoritative source reversal truth';
                END IF;
              END IF;

              SELECT
                COALESCE(sum(edge.consumed_amount) FILTER (
                  WHERE before_lot.semantic_position='EARNED_UNBILLED'
                    AND after_lot.semantic_position='BILLED_EARNED'
                ),0),
                COALESCE(sum(edge.consumed_amount) FILTER (
                  WHERE before_lot.semantic_position='UNPERFORMED_UNBILLED'
                    AND after_lot.semantic_position='BILLED_UNEARNED'
                ),0)
              INTO asset_amount,liability_amount
              FROM public.contract_consideration_transition_lots edge
              JOIN public.contract_consideration_lots before_lot
                ON before_lot.tenant_id=edge.tenant_id
               AND before_lot.id=edge.lot_id
              JOIN public.contract_consideration_lots after_lot
                ON after_lot.tenant_id=edge.tenant_id
               AND after_lot.id=edge.successor_lot_id
              WHERE edge.tenant_id=r.tenant_id
                AND edge.transition_id=r.billing_consideration_transition_id;

              IF asset_amount IS DISTINCT FROM r.contract_asset_release_amount
                 OR liability_amount IS DISTINCT FROM
                    r.contract_liability_creation_amount
                 OR asset_amount+liability_amount
                    IS DISTINCT FROM r.receivable_amount
              THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR amounts differ from exact Billing Transition grammar';
              END IF;

              IF EXISTS (
                SELECT 1
                FROM public.contract_consideration_transition_lots edge
                JOIN public.contract_consideration_lots before_lot
                  ON before_lot.tenant_id=edge.tenant_id
                 AND before_lot.id=edge.lot_id
                JOIN public.contract_consideration_lots after_lot
                  ON after_lot.tenant_id=edge.tenant_id
                 AND after_lot.id=edge.successor_lot_id
                WHERE edge.tenant_id=r.tenant_id
                  AND edge.transition_id=r.billing_consideration_transition_id
                  AND NOT (
                    (before_lot.semantic_position='EARNED_UNBILLED'
                      AND after_lot.semantic_position='BILLED_EARNED')
                    OR
                    (before_lot.semantic_position='UNPERFORMED_UNBILLED'
                      AND after_lot.semantic_position='BILLED_UNEARNED')
                  )
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Billing Transition contains unsupported accounting grammar';
              END IF;

              SELECT COALESCE(sum(o.origin_amount),0)
              INTO origin_total
              FROM public.accounting_position_origins o
              WHERE o.tenant_id=r.tenant_id
                AND o.origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                AND o.origin_recognition_id=r.id
                AND o.position_type='CONTRACT_LIABILITY';

              IF origin_total IS DISTINCT FROM liability_amount
                 OR EXISTS (
                   SELECT 1
                   FROM public.accounting_position_origins o
                   JOIN public.contract_consideration_lots lot
                     ON lot.tenant_id=o.tenant_id
                    AND lot.id=o.consideration_lot_id
                   WHERE o.tenant_id=r.tenant_id
                     AND o.origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                     AND o.origin_recognition_id=r.id
                     AND (
                       o.contract_id<>r.contract_id
                       OR o.position_type<>'CONTRACT_LIABILITY'
                       OR o.origin_journal_entry_id<>r.journal_entry_id
                       OR o.account_id IS DISTINCT FROM
                          r.contract_liability_account_id
                       OR o.economic_source_type
                          <>'CONTRACTUAL_BILLING_ENTITLEMENT'
                       OR o.economic_source_id
                          <>r.contractual_billing_entitlement_id
                       OR o.consideration_transition_id
                          <>r.billing_consideration_transition_id
                       OR lot.semantic_position<>'BILLED_UNEARNED'
                       OR o.economic_leg_identity<>
                          ('AR:ORIGIN:'||
                           r.billing_consideration_transition_id||
                           ':'||o.consideration_lot_id)
                       OR o.accounting_date<>r.accounting_date
                       OR (r.status='posted' AND o.status<>'effective')
                       OR (
                         r.status='reversed'
                         AND (
                           o.status<>'reversed'
                           OR o.reversal_origin_operation_id IS DISTINCT FROM
                              r.reversal_operation_id
                           OR o.reversed_at IS DISTINCT FROM r.reversed_at
                         )
                       )
                     )
                 )
              THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Contract Liability origin graph is inconsistent';
              END IF;

              SELECT COALESCE(sum(c.amount),0)
              INTO consumption_total
              FROM public.accounting_position_consumptions c
              WHERE c.tenant_id=r.tenant_id
                AND c.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION'
                AND c.consuming_recognition_id=r.id;

              IF consumption_total IS DISTINCT FROM asset_amount
                 OR EXISTS (
                   SELECT 1
                   FROM public.accounting_position_consumptions c
                   JOIN public.accounting_position_origins o
                     ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                   JOIN public.contract_consideration_lots successor_lot
                     ON successor_lot.tenant_id=c.tenant_id
                    AND successor_lot.id=c.consideration_lot_id
                   WHERE c.tenant_id=r.tenant_id
                     AND c.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION'
                     AND c.consuming_recognition_id=r.id
                     AND (
                       c.contract_id<>r.contract_id
                       OR o.contract_id<>r.contract_id
                       OR o.position_type<>'CONTRACT_ASSET'
                       OR c.consuming_journal_entry_id<>r.journal_entry_id
                       OR c.consideration_transition_id
                          <>r.billing_consideration_transition_id
                       OR successor_lot.semantic_position<>'BILLED_EARNED'
                       OR NOT EXISTS (
                         SELECT 1
                         FROM public.contract_consideration_transition_lots edge
                         JOIN public.contract_consideration_lots predecessor_lot
                           ON predecessor_lot.tenant_id=edge.tenant_id
                          AND predecessor_lot.id=edge.lot_id
                         WHERE edge.tenant_id=c.tenant_id
                           AND edge.transition_id=
                              r.billing_consideration_transition_id
                           AND edge.lot_id=o.consideration_lot_id
                           AND edge.successor_lot_id=c.consideration_lot_id
                           AND predecessor_lot.semantic_position=
                              'EARNED_UNBILLED'
                       )
                       OR c.economic_leg_identity<>
                          ('AR:CONSUME:'||
                           r.billing_consideration_transition_id||':'||
                           o.consideration_lot_id||':'||
                           c.consideration_lot_id)
                       OR (r.status='posted' AND c.status<>'effective')
                       OR (
                         r.status='reversed'
                         AND (
                           c.status<>'reversed'
                           OR c.reversal_operation_id IS DISTINCT FROM
                              r.reversal_operation_id
                           OR c.reversed_at IS DISTINCT FROM r.reversed_at
                         )
                       )
                     )
                 )
                 OR EXISTS (
                   SELECT 1
                   FROM public.contract_consideration_transition_lots edge
                   JOIN public.contract_consideration_lots predecessor_lot
                     ON predecessor_lot.tenant_id=edge.tenant_id
                    AND predecessor_lot.id=edge.lot_id
                   JOIN public.contract_consideration_lots successor_lot
                     ON successor_lot.tenant_id=edge.tenant_id
                    AND successor_lot.id=edge.successor_lot_id
                   WHERE edge.tenant_id=r.tenant_id
                     AND edge.transition_id=
                        r.billing_consideration_transition_id
                     AND predecessor_lot.semantic_position='EARNED_UNBILLED'
                     AND successor_lot.semantic_position='BILLED_EARNED'
                     AND edge.consumed_amount IS DISTINCT FROM COALESCE((
                       SELECT sum(c.amount)
                       FROM public.accounting_position_consumptions c
                       JOIN public.accounting_position_origins o
                         ON o.tenant_id=c.tenant_id
                        AND o.id=c.origin_id
                       WHERE c.tenant_id=r.tenant_id
                         AND c.consuming_recognition_type=
                            'RECEIVABLE_AR_RECOGNITION'
                         AND c.consuming_recognition_id=r.id
                         AND o.consideration_lot_id=edge.lot_id
                         AND c.consideration_lot_id=edge.successor_lot_id
                     ),0)
                 )
              THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Contract Asset consumption graph is inconsistent';
              END IF;

              IF EXISTS (
                SELECT 1
                FROM public.accounting_position_origins o
                LEFT JOIN public.accounting_position_origin_journal_line_allocations a
                  ON a.tenant_id=o.tenant_id AND a.origin_id=o.id
                LEFT JOIN public.journal_lines jl
                  ON jl.tenant_id=a.tenant_id AND jl.id=a.journal_line_id
                WHERE o.tenant_id=r.tenant_id
                  AND o.origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                  AND o.origin_recognition_id=r.id
                  AND (
                    a.id IS NULL
                    OR a.journal_entry_id<>r.journal_entry_id
                    OR jl.journal_entry_id<>r.journal_entry_id
                    OR jl.account_id<>o.account_id
                    OR a.amount<>o.origin_amount
                    OR jl.credit<a.amount
                  )
              ) OR EXISTS (
                SELECT 1
                FROM public.accounting_position_consumptions c
                LEFT JOIN public.accounting_position_consumption_journal_line_allocations a
                  ON a.tenant_id=c.tenant_id AND a.consumption_id=c.id
                LEFT JOIN public.journal_lines jl
                  ON jl.tenant_id=a.tenant_id AND jl.id=a.journal_line_id
                JOIN public.accounting_position_origins o
                  ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                WHERE c.tenant_id=r.tenant_id
                  AND c.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION'
                  AND c.consuming_recognition_id=r.id
                  AND (
                    a.id IS NULL
                    OR a.journal_entry_id<>r.journal_entry_id
                    OR jl.journal_entry_id<>r.journal_entry_id
                    OR jl.account_id<>o.account_id
                    OR a.amount<>c.amount
                    OR jl.credit<a.amount
                  )
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Journal-line provenance allocation is inconsistent';
              END IF;

              SELECT
                COALESCE(sum(debit),0),
                count(*) FILTER (
                  WHERE account_id=r.ar_control_account_id
                    AND debit=r.receivable_amount
                    AND credit=0
                ),
                count(*),
                COALESCE(sum(debit),0),
                COALESCE(sum(credit),0)
              INTO
                ar_debit,
                ar_line_count,
                journal_line_count,
                journal_debits,
                journal_credits
              FROM public.journal_lines
              WHERE tenant_id=r.tenant_id
                AND journal_entry_id=r.journal_entry_id;

              SELECT count(DISTINCT o.account_id)
              INTO asset_credit_account_count
              FROM public.accounting_position_consumptions c
              JOIN public.accounting_position_origins o
                ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
              WHERE c.tenant_id=r.tenant_id
                AND c.consuming_recognition_type=
                   'RECEIVABLE_AR_RECOGNITION'
                AND c.consuming_recognition_id=r.id;

              expected_line_count:=1+asset_credit_account_count+
                CASE WHEN liability_amount>0 THEN 1 ELSE 0 END;

              IF ar_line_count<>1
                 OR EXISTS (
                   SELECT 1
                   FROM public.journal_lines jl
                   WHERE jl.tenant_id=r.tenant_id
                     AND jl.journal_entry_id=r.journal_entry_id
                     AND jl.debit>0
                     AND (
                       jl.account_id<>r.ar_control_account_id
                       OR jl.debit<>r.receivable_amount
                     )
                 )
                 OR journal_line_count<>expected_line_count
                 OR journal_debits<>r.receivable_amount
                 OR journal_credits<>r.receivable_amount
                 OR EXISTS (
                   SELECT 1
                   FROM (
                     SELECT o.account_id,sum(c.amount) AS expected_credit
                     FROM public.accounting_position_consumptions c
                     JOIN public.accounting_position_origins o
                       ON o.tenant_id=c.tenant_id AND o.id=c.origin_id
                     WHERE c.tenant_id=r.tenant_id
                       AND c.consuming_recognition_type=
                          'RECEIVABLE_AR_RECOGNITION'
                       AND c.consuming_recognition_id=r.id
                     GROUP BY o.account_id
                   ) expected
                   WHERE (
                     SELECT count(*)
                     FROM public.journal_lines jl
                     WHERE jl.tenant_id=r.tenant_id
                       AND jl.journal_entry_id=r.journal_entry_id
                       AND jl.account_id=expected.account_id
                       AND jl.debit=0
                       AND jl.credit=expected.expected_credit
                   )<>1
                 )
                 OR (
                   liability_amount>0
                   AND (
                     SELECT count(*)
                     FROM public.journal_lines jl
                     WHERE jl.tenant_id=r.tenant_id
                       AND jl.journal_entry_id=r.journal_entry_id
                       AND jl.account_id=r.contract_liability_account_id
                       AND jl.debit=0
                       AND jl.credit=liability_amount
                   )<>1
                 )
                 OR (
                   liability_amount=0
                   AND EXISTS (
                     SELECT 1
                     FROM public.journal_lines jl
                     WHERE jl.tenant_id=r.tenant_id
                       AND jl.journal_entry_id=r.journal_entry_id
                       AND jl.account_id IS NOT DISTINCT FROM
                          r.contract_liability_account_id
                       AND jl.credit>0
                   )
                 )
              THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR Journal grammar is noncanonical';
              END IF;

              IF EXISTS (
                SELECT 1
                FROM public.journal_lines jl
                LEFT JOIN (
                  SELECT a.journal_line_id,sum(a.amount) AS mapped_amount
                  FROM public.accounting_position_origin_journal_line_allocations a
                  JOIN public.accounting_position_origins o
                    ON o.tenant_id=a.tenant_id AND o.id=a.origin_id
                  WHERE o.tenant_id=r.tenant_id
                    AND o.origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                    AND o.origin_recognition_id=r.id
                  GROUP BY a.journal_line_id

                  UNION ALL

                  SELECT a.journal_line_id,sum(a.amount) AS mapped_amount
                  FROM public.accounting_position_consumption_journal_line_allocations a
                  JOIN public.accounting_position_consumptions pc
                    ON pc.tenant_id=a.tenant_id
                   AND pc.id=a.consumption_id
                  WHERE pc.tenant_id=r.tenant_id
                    AND pc.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION'
                    AND pc.consuming_recognition_id=r.id
                  GROUP BY a.journal_line_id
                ) mapping
                  ON mapping.journal_line_id=jl.id
                WHERE jl.tenant_id=r.tenant_id
                  AND jl.journal_entry_id=r.journal_entry_id
                  AND jl.credit>0
                GROUP BY jl.id,jl.credit
                HAVING COALESCE(sum(mapping.mapped_amount),0)<>jl.credit
              )
              OR EXISTS (
                SELECT 1
                FROM (
                  SELECT a.journal_line_id
                  FROM public.accounting_position_origin_journal_line_allocations a
                  JOIN public.accounting_position_origins o
                    ON o.tenant_id=a.tenant_id AND o.id=a.origin_id
                  WHERE o.tenant_id=r.tenant_id
                    AND o.origin_recognition_type='RECEIVABLE_AR_RECOGNITION'
                    AND o.origin_recognition_id=r.id

                  UNION ALL

                  SELECT a.journal_line_id
                  FROM public.accounting_position_consumption_journal_line_allocations a
                  JOIN public.accounting_position_consumptions pc
                    ON pc.tenant_id=a.tenant_id
                   AND pc.id=a.consumption_id
                  WHERE pc.tenant_id=r.tenant_id
                    AND pc.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION'
                    AND pc.consuming_recognition_id=r.id
                ) mapped
                JOIN public.journal_lines jl
                  ON jl.tenant_id=r.tenant_id
                 AND jl.id=mapped.journal_line_id
                WHERE jl.journal_entry_id<>r.journal_entry_id
                   OR jl.credit<=0
              ) THEN
                RAISE EXCEPTION USING
                  ERRCODE='23514',
                  MESSAGE='Receivable AR credit Journal lines require exact provenance allocation';
              END IF;

              IF r.status='reversed' THEN
                SELECT * INTO reversal
                FROM public.journal_entries
                WHERE tenant_id=r.tenant_id
                  AND id=r.reversal_journal_entry_id;

                IF reversal.id IS NULL
                   OR reversal.status<>'posted'
                   OR reversal.origin<>'reversal'
                   OR reversal.source_type<>'journal_entry'
                   OR reversal.source_id<>r.journal_entry_id
                   OR reversal.reverses_journal_entry_id<>r.journal_entry_id
                   OR reversal.entry_date<>r.accounting_date
                   OR reversal.reversal_reason IS DISTINCT FROM
                      e.reversal_reason
                   OR reversal.created_by IS DISTINCT FROM e.reversed_by
                   OR reversal.posted_by IS DISTINCT FROM e.reversed_by
                THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Receivable AR reversal Journal is inconsistent';
                END IF;

                IF EXISTS (
                  SELECT 1
                  FROM public.journal_entries rr
                  WHERE rr.tenant_id=r.tenant_id
                    AND rr.reverses_journal_entry_id=
                      r.reversal_journal_entry_id
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Receivable AR recorded reversal Journal cannot itself be reversed';
                END IF;
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION
              public.receivable_ar_recognition_final_state()
            RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path=pg_catalog,public AS $$
            DECLARE recognition_id char(26);
            BEGIN
              IF TG_TABLE_NAME='receivable_ar_recognitions' THEN
                recognition_id:=NEW.id;

              ELSIF TG_TABLE_NAME='contractual_billing_entitlements' THEN
                FOR recognition_id IN
                  SELECT id FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND contractual_billing_entitlement_id=NEW.id
                LOOP
                  PERFORM public.validate_receivable_ar_recognition(
                    NEW.tenant_id,recognition_id
                  );
                END LOOP;

                IF NEW.status='reversed' AND EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND contractual_billing_entitlement_id=NEW.id
                    AND status='posted'
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Reversed Entitlement cannot retain effective Receivable AR';
                END IF;

                RETURN NULL;

              ELSIF TG_TABLE_NAME='receivables' THEN
                FOR recognition_id IN
                  SELECT id FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND receivable_id=NEW.id
                LOOP
                  PERFORM public.validate_receivable_ar_recognition(
                    NEW.tenant_id,recognition_id
                  );
                END LOOP;

                IF NEW.status='cancelled' AND EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND receivable_id=NEW.id
                    AND status='posted'
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Cancelled Receivable cannot retain effective Receivable AR';
                END IF;

                RETURN NULL;

              ELSIF TG_TABLE_NAME='contract_consideration_transitions' THEN
                FOR recognition_id IN
                  SELECT id FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND billing_consideration_transition_id=NEW.id
                LOOP
                  PERFORM public.validate_receivable_ar_recognition(
                    NEW.tenant_id,recognition_id
                  );
                END LOOP;

                IF NEW.status='reversed' AND EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND billing_consideration_transition_id=NEW.id
                    AND status='posted'
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23514',
                    MESSAGE='Reversed Billing Transition cannot retain effective Receivable AR';
                END IF;

                RETURN NULL;

              ELSIF TG_TABLE_NAME='journal_entries' THEN
                IF NEW.source_type='receivable_ar_recognition' THEN
                  recognition_id:=NEW.source_id;
                ELSIF NEW.reverses_journal_entry_id IS NOT NULL THEN
                  SELECT id INTO recognition_id
                  FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id
                    AND (
                      journal_entry_id=NEW.reverses_journal_entry_id
                      OR reversal_journal_entry_id=
                        NEW.reverses_journal_entry_id
                    )
                  ORDER BY id
                  LIMIT 1;
                END IF;

              ELSIF TG_TABLE_NAME='accounting_position_origins' THEN
                IF NEW.origin_recognition_type='RECEIVABLE_AR_RECOGNITION' THEN
                  recognition_id:=NEW.origin_recognition_id;
                END IF;

              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                IF NEW.consuming_recognition_type='RECEIVABLE_AR_RECOGNITION' THEN
                  recognition_id:=NEW.consuming_recognition_id;
                END IF;

              ELSIF TG_TABLE_NAME=
                'accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_id INTO recognition_id
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id
                  AND id=NEW.origin_id
                  AND origin_recognition_type='RECEIVABLE_AR_RECOGNITION';

              ELSIF TG_TABLE_NAME=
                'accounting_position_consumption_journal_line_allocations' THEN
                SELECT consuming_recognition_id INTO recognition_id
                FROM public.accounting_position_consumptions
                WHERE tenant_id=NEW.tenant_id
                  AND id=NEW.consumption_id
                  AND consuming_recognition_type='RECEIVABLE_AR_RECOGNITION';
              END IF;

              IF recognition_id IS NOT NULL THEN
                IF NOT EXISTS(
                  SELECT 1 FROM public.receivable_ar_recognitions
                  WHERE tenant_id=COALESCE(NEW.tenant_id,OLD.tenant_id)
                    AND id=recognition_id
                ) THEN
                  RAISE EXCEPTION USING
                    ERRCODE='23503',
                    MESSAGE='Receivable AR provenance requires an exact Recognition owner';
                END IF;

                PERFORM public.validate_receivable_ar_recognition(
                  COALESCE(NEW.tenant_id,OLD.tenant_id),
                  recognition_id
                );
              END IF;

              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER receivable_ar_recognition_final
              AFTER INSERT OR UPDATE ON public.receivable_ar_recognitions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_journal_final
              AFTER INSERT OR UPDATE ON public.journal_entries
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_entitlement_final
              AFTER UPDATE ON public.contractual_billing_entitlements
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_receivable_final
              AFTER UPDATE ON public.receivables
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_transition_final
              AFTER UPDATE ON public.contract_consideration_transitions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_origin_final
              AFTER INSERT OR UPDATE ON public.accounting_position_origins
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_consumption_final
              AFTER INSERT OR UPDATE ON public.accounting_position_consumptions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_origin_allocation_final
              AFTER INSERT
              ON public.accounting_position_origin_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            CREATE CONSTRAINT TRIGGER receivable_ar_consumption_allocation_final
              AFTER INSERT
              ON public.accounting_position_consumption_journal_line_allocations
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
              EXECUTE FUNCTION public.receivable_ar_recognition_final_state();

            /*
             * Slice 2 installed these provenance-table triggers. Replace the
             * function body so the shared runtime corridor now admits only
             * provenance backed by an exact owner from either implemented
             * recognition family.
             */
            CREATE OR REPLACE FUNCTION
              public.performance_accounting_runtime_provenance_guard()
            RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $receivable_ar_runtime$
            DECLARE owner_type text;
            DECLARE owner_id char(26);
            DECLARE owner_exists boolean:=false;
            BEGIN
              IF current_user <> '__RUNTIME_LITERAL__' THEN
                RETURN NEW;
              END IF;

              IF TG_TABLE_NAME='accounting_position_origins' THEN
                owner_type:=NEW.origin_recognition_type;
                owner_id:=NEW.origin_recognition_id;

              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                owner_type:=NEW.consuming_recognition_type;
                owner_id:=NEW.consuming_recognition_id;

              ELSIF TG_TABLE_NAME=
                'accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_type,origin_recognition_id
                INTO owner_type,owner_id
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id AND id=NEW.origin_id;

              ELSE
                SELECT consuming_recognition_type,consuming_recognition_id
                INTO owner_type,owner_id
                FROM public.accounting_position_consumptions
                WHERE tenant_id=NEW.tenant_id AND id=NEW.consumption_id;
              END IF;

              IF owner_type='PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                SELECT EXISTS(
                  SELECT 1
                  FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=owner_id
                ) INTO owner_exists;

              ELSIF owner_type='RECEIVABLE_AR_RECOGNITION' THEN
                SELECT EXISTS(
                  SELECT 1
                  FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=owner_id
                ) INTO owner_exists;

              ELSE
                RAISE EXCEPTION USING
                  ERRCODE='42501',
                  MESSAGE='Runtime accounting provenance owner type is unsupported';
              END IF;

              IF NOT owner_exists THEN
                RAISE EXCEPTION USING
                  ERRCODE='23503',
                  MESSAGE='Runtime accounting provenance requires an exact Recognition owner';
              END IF;

              RETURN NEW;
            END $receivable_ar_runtime$;

            REVOKE ALL ON public.receivable_ar_recognitions FROM PUBLIC;

            REVOKE EXECUTE ON FUNCTION
              public.receivable_ar_recognition_history_guard(),
              public.validate_receivable_ar_recognition(character,character),
              public.receivable_ar_recognition_final_state()
              FROM PUBLIC;
            SQL);

        $this->hardenRuntimePrivileges();
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        if (DB::table('receivable_ar_recognitions')->exists()) {
            throw new RuntimeException(
                'Receivable AR Recognition rollback refused while historical recognition exists.'
            );
        }

        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.receivable_ar_recognition_final_state()
              CASCADE;
            DROP FUNCTION public.validate_receivable_ar_recognition(
              character,character
            );
            DROP FUNCTION public.receivable_ar_recognition_history_guard()
              CASCADE;

            DROP TABLE public.receivable_ar_recognitions;

            ALTER TABLE public.accounting_source_types
              DISABLE TRIGGER accounting_source_types_immutable_delete;
            DELETE FROM public.accounting_source_types
              WHERE origin='business'
                AND key='receivable_ar_recognition';
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
                'performance_accounting.corrected'
              ));

            ALTER TABLE public.accounting_audits
              DROP CONSTRAINT accounting_audits_subject_type_check;
            ALTER TABLE public.accounting_audits
              ADD CONSTRAINT accounting_audits_subject_type_check CHECK(
                subject_type IN (
                  'accounting_settings','account','journal_entry',
                  'accounting_period','opening_balance_operation',
                  'performance_accounting_recognition'
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

        /*
         * Keep the shared provenance corridor conservative after rollback:
         * restore the Slice 2 owner-only behavior.
         */
        $restore = <<<'SQL'
            CREATE OR REPLACE FUNCTION
              public.performance_accounting_runtime_provenance_guard()
            RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
            DECLARE owner_type text;
            BEGIN
              IF current_user <> '__RUNTIME_LITERAL__' THEN
                RETURN NEW;
              END IF;

              IF TG_TABLE_NAME='accounting_position_origins' THEN
                IF NEW.origin_recognition_type
                    <>'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING
                    ERRCODE='42501',
                    MESSAGE='Runtime cannot create unsupported accounting provenance';
                END IF;
              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                IF NEW.consuming_recognition_type
                    <>'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING
                    ERRCODE='42501',
                    MESSAGE='Runtime cannot create unsupported accounting provenance';
                END IF;
              ELSIF TG_TABLE_NAME=
                'accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_type INTO owner_type
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id AND id=NEW.origin_id;
                IF owner_type IS DISTINCT FROM
                    'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING
                    ERRCODE='42501',
                    MESSAGE='Runtime cannot allocate unsupported accounting provenance';
                END IF;
              ELSE
                SELECT consuming_recognition_type INTO owner_type
                FROM public.accounting_position_consumptions
                WHERE tenant_id=NEW.tenant_id AND id=NEW.consumption_id;
                IF owner_type IS DISTINCT FROM
                    'PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                  RAISE EXCEPTION USING
                    ERRCODE='42501',
                    MESSAGE='Runtime cannot allocate unsupported accounting provenance';
                END IF;
              END IF;

              RETURN NEW;
            END $$;
            SQL;

        $restore = str_replace(
            '__RUNTIME_LITERAL__',
            $runtimeRole,
            $restore,
        );
        DB::unprepared($restore);
    }

    private function hardenRuntimePrivileges(): void
    {
        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        $guardSql = <<<'SQL'
            CREATE OR REPLACE FUNCTION
              public.performance_accounting_runtime_provenance_guard()
            RETURNS trigger
            LANGUAGE plpgsql SET search_path=pg_catalog,public
            AS $receivable_ar_runtime$
            DECLARE owner_type text;
            DECLARE owner_id char(26);
            DECLARE owner_exists boolean:=false;
            BEGIN
              IF current_user <> '__RUNTIME_LITERAL__' THEN
                RETURN NEW;
              END IF;

              IF TG_TABLE_NAME='accounting_position_origins' THEN
                owner_type:=NEW.origin_recognition_type;
                owner_id:=NEW.origin_recognition_id;

              ELSIF TG_TABLE_NAME='accounting_position_consumptions' THEN
                owner_type:=NEW.consuming_recognition_type;
                owner_id:=NEW.consuming_recognition_id;

              ELSIF TG_TABLE_NAME=
                'accounting_position_origin_journal_line_allocations' THEN
                SELECT origin_recognition_type,origin_recognition_id
                INTO owner_type,owner_id
                FROM public.accounting_position_origins
                WHERE tenant_id=NEW.tenant_id AND id=NEW.origin_id;

              ELSE
                SELECT consuming_recognition_type,consuming_recognition_id
                INTO owner_type,owner_id
                FROM public.accounting_position_consumptions
                WHERE tenant_id=NEW.tenant_id AND id=NEW.consumption_id;
              END IF;

              IF owner_type='PERFORMANCE_ACCOUNTING_RECOGNITION' THEN
                SELECT EXISTS(
                  SELECT 1
                  FROM public.performance_accounting_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=owner_id
                ) INTO owner_exists;

              ELSIF owner_type='RECEIVABLE_AR_RECOGNITION' THEN
                SELECT EXISTS(
                  SELECT 1
                  FROM public.receivable_ar_recognitions
                  WHERE tenant_id=NEW.tenant_id AND id=owner_id
                ) INTO owner_exists;

              ELSE
                RAISE EXCEPTION USING
                  ERRCODE='42501',
                  MESSAGE='Runtime accounting provenance owner type is unsupported';
              END IF;

              IF NOT owner_exists THEN
                RAISE EXCEPTION USING
                  ERRCODE='23503',
                  MESSAGE='Runtime accounting provenance requires an exact Recognition owner';
              END IF;

              RETURN NEW;
            END $receivable_ar_runtime$;
            SQL;

        $guardSql = str_replace(
            '__RUNTIME_LITERAL__',
            $runtimeRole,
            $guardSql,
        );
        DB::unprepared($guardSql);

        DB::unprepared(
            "REVOKE ALL ON TABLE public.receivable_ar_recognitions FROM {$identifier}",
        );
        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE public.receivable_ar_recognitions TO {$identifier}",
        );
        DB::unprepared(
            "GRANT SELECT,INSERT,UPDATE ON TABLE
              public.accounting_position_origins,
              public.accounting_position_consumptions
              TO {$identifier}",
        );
        DB::unprepared(
            "GRANT SELECT,INSERT ON TABLE
              public.accounting_position_origin_journal_line_allocations,
              public.accounting_position_consumption_journal_line_allocations
              TO {$identifier}",
        );

        foreach ([
            'public.receivable_ar_recognition_history_guard()',
            'public.validate_receivable_ar_recognition(character,character)',
            'public.receivable_ar_recognition_final_state()',
            'public.performance_accounting_runtime_provenance_guard()',
        ] as $function) {
            DB::unprepared(
                "REVOKE EXECUTE ON FUNCTION {$function} FROM {$identifier}",
            );
        }
    }

    private function runtimeRole(): string
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (
            ! is_string($runtimeRole)
            || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole) !== 1
        ) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.'
            );
        }

        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolreplication,rolbypassrls
             FROM pg_catalog.pg_roles WHERE rolname=?',
            [$runtimeRole],
        );

        if (
            $role === null
            || $role->rolsuper
            || $role->rolcreaterole
            || $role->rolcreatedb
            || $role->rolreplication
            || $role->rolbypassrls
        ) {
            throw new RuntimeException(
                'Receivable AR runtime role must exist and remain unprivileged.'
            );
        }

        return $runtimeRole;
    }

    private function skipIsolatedTestSchema(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Receivable AR Recognition v1 requires PostgreSQL.'
            );
        }

        $schema = DB::selectOne(
            'SELECT current_schema() AS name'
        )->name;

        if ($schema === 'public') {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException(
            'Receivable AR Recognition v1 requires the public PostgreSQL schema.'
        );
    }
};
