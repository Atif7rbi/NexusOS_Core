<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->skip()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.contract_consideration_positions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL REFERENCES public.tenants(id),
              contract_id char(26) NOT NULL,
              coordination_adoption_operation_id char(26) NOT NULL
                CHECK (coordination_adoption_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              status text NOT NULL CHECK (status = 'ADOPTED'),
              consideration_amount numeric(19,2) NOT NULL CHECK (consideration_amount > 0),
              currency char(3) NOT NULL CHECK (currency = 'SAR'),
              adoption_basis text NOT NULL CHECK (adoption_basis = 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES'),
              coordination_scope_version text NOT NULL CHECK (coordination_scope_version = 'CONTRACT_CONSIDERATION_V1'),
              adopted_by bigint NOT NULL,
              adopted_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,contract_id),
              UNIQUE (tenant_id,coordination_adoption_operation_id),
              UNIQUE (tenant_id,contract_id,id),
              FOREIGN KEY (tenant_id,contract_id) REFERENCES public.contracts(tenant_id,id),
              FOREIGN KEY (tenant_id,adopted_by) REFERENCES public.tenant_users(tenant_id,user_id)
            );

            CREATE TABLE public.contract_consideration_transitions (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              position_id char(26) NOT NULL,
              transition_operation_id char(26) NOT NULL
                CHECK (transition_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              source_type text NOT NULL CHECK (source_type IN ('UNIT_HANDOVER_ACCEPTANCE','CONTRACTUAL_BILLING_ENTITLEMENT')),
              source_id char(26) NOT NULL CHECK (source_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              economic_date date NOT NULL,
              semantic_precedence smallint NOT NULL,
              transition_amount numeric(19,2) NOT NULL CHECK (transition_amount > 0),
              currency char(3) NOT NULL CHECK (currency = 'SAR'),
              status text NOT NULL CHECK (status IN ('effective','reversed')),
              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              reversal_operation_id char(26),
              reversal_source_operation_id char(26),
              reversal_reason text,
              reversal_reference text,
              reversed_by bigint,
              reversed_at timestamptz,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,contract_id,position_id,id),
              UNIQUE (tenant_id,transition_operation_id),
              UNIQUE (tenant_id,source_type,source_id),
              UNIQUE (tenant_id,reversal_operation_id),
              FOREIGN KEY (tenant_id,contract_id,position_id)
                REFERENCES public.contract_consideration_positions(tenant_id,contract_id,id),
              FOREIGN KEY (tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id),
              FOREIGN KEY (tenant_id,reversed_by) REFERENCES public.tenant_users(tenant_id,user_id),
              CHECK ((source_type = 'UNIT_HANDOVER_ACCEPTANCE' AND semantic_precedence = 10)
                  OR (source_type = 'CONTRACTUAL_BILLING_ENTITLEMENT' AND semantic_precedence = 20)),
              CHECK ((status = 'effective' AND reversal_operation_id IS NULL
                      AND reversal_source_operation_id IS NULL AND reversal_reason IS NULL
                      AND reversal_reference IS NULL AND reversed_by IS NULL AND reversed_at IS NULL)
                  OR (status = 'reversed' AND reversal_operation_id IS NOT NULL
                      AND reversal_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                      AND reversal_source_operation_id IS NOT NULL
                      AND reversal_source_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                      AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> ''
                      AND reversal_reference IS NOT NULL AND btrim(reversal_reference) <> ''
                      AND reversed_by IS NOT NULL AND reversed_at IS NOT NULL))
            );
            CREATE INDEX cc_transition_order ON public.contract_consideration_transitions
              (tenant_id,position_id,economic_date,semantic_precedence,source_id);

            CREATE TABLE public.contract_consideration_lots (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              position_id char(26) NOT NULL,
              transition_id char(26),
              lot_kind text NOT NULL CHECK (lot_kind IN ('GENESIS','TRANSITION_OUTPUT')),
              semantic_position text NOT NULL CHECK (semantic_position IN
                ('UNPERFORMED_UNBILLED','EARNED_UNBILLED','BILLED_UNEARNED','BILLED_EARNED')),
              amount numeric(19,2) NOT NULL CHECK (amount > 0),
              currency char(3) NOT NULL CHECK (currency = 'SAR'),
              created_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,contract_id,position_id,id),
              UNIQUE (tenant_id,transition_id,semantic_position),
              FOREIGN KEY (tenant_id,contract_id,position_id)
                REFERENCES public.contract_consideration_positions(tenant_id,contract_id,id),
              FOREIGN KEY (tenant_id,contract_id,position_id,transition_id)
                REFERENCES public.contract_consideration_transitions(tenant_id,contract_id,position_id,id),
              CHECK ((lot_kind = 'GENESIS' AND transition_id IS NULL AND semantic_position = 'UNPERFORMED_UNBILLED')
                  OR (lot_kind = 'TRANSITION_OUTPUT' AND transition_id IS NOT NULL))
            );
            CREATE UNIQUE INDEX cc_one_genesis ON public.contract_consideration_lots (tenant_id,position_id)
              WHERE lot_kind = 'GENESIS';

            CREATE TABLE public.contract_consideration_transition_lots (
              id char(26) PRIMARY KEY CHECK (id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              position_id char(26) NOT NULL,
              transition_id char(26) NOT NULL,
              lot_id char(26) NOT NULL,
              successor_lot_id char(26) NOT NULL,
              consumed_amount numeric(19,2) NOT NULL CHECK (consumed_amount > 0),
              currency char(3) NOT NULL CHECK (currency = 'SAR'),
              created_at timestamptz NOT NULL,
              UNIQUE (tenant_id,id),
              UNIQUE (tenant_id,transition_id,lot_id),
              FOREIGN KEY (tenant_id,contract_id,position_id,transition_id)
                REFERENCES public.contract_consideration_transitions(tenant_id,contract_id,position_id,id),
              FOREIGN KEY (tenant_id,contract_id,position_id,lot_id)
                REFERENCES public.contract_consideration_lots(tenant_id,contract_id,position_id,id),
              FOREIGN KEY (tenant_id,contract_id,position_id,successor_lot_id)
                REFERENCES public.contract_consideration_lots(tenant_id,contract_id,position_id,id),
              CHECK (lot_id <> successor_lot_id)
            );
            CREATE INDEX cc_edge_predecessor ON public.contract_consideration_transition_lots (tenant_id,lot_id);
            CREATE INDEX cc_edge_successor ON public.contract_consideration_transition_lots (tenant_id,successor_lot_id);

            CREATE OR REPLACE FUNCTION public.cc_immutable_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'Contract Consideration historical facts are immutable';
            END $$;

            CREATE TRIGGER cc_position_immutable BEFORE UPDATE OR DELETE ON public.contract_consideration_positions
              FOR EACH ROW EXECUTE FUNCTION public.cc_immutable_history();
            CREATE TRIGGER cc_lot_immutable BEFORE UPDATE OR DELETE ON public.contract_consideration_lots
              FOR EACH ROW EXECUTE FUNCTION public.cc_immutable_history();
            CREATE TRIGGER cc_edge_immutable BEFORE UPDATE OR DELETE ON public.contract_consideration_transition_lots
              FOR EACH ROW EXECUTE FUNCTION public.cc_immutable_history();

            CREATE OR REPLACE FUNCTION public.cc_adoption_source() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE c public.contracts%ROWTYPE;
            BEGIN
              PERFORM id FROM public.tenants WHERE id = NEW.tenant_id FOR UPDATE;
              SELECT * INTO c FROM public.contracts
                WHERE tenant_id = NEW.tenant_id AND id = NEW.contract_id FOR UPDATE;
              IF NOT FOUND OR c.currency <> 'SAR' OR c.total_amount IS DISTINCT FROM NEW.consideration_amount THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Adoption must snapshot the exact SAR Contract capacity';
              END IF;
              IF EXISTS (SELECT 1 FROM public.contractual_billing_entitlements
                         WHERE tenant_id = NEW.tenant_id AND contract_id = NEW.contract_id AND status = 'effective')
                 OR EXISTS (SELECT 1 FROM public.unit_handover_acceptances
                         WHERE tenant_id = NEW.tenant_id AND contract_id = NEW.contract_id AND status = 'effective') THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Clean adoption requires no prior effective supported source';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER cc_adoption_source_guard BEFORE INSERT ON public.contract_consideration_positions
              FOR EACH ROW EXECUTE FUNCTION public.cc_adoption_source();

            CREATE OR REPLACE FUNCTION public.cc_contract_capacity() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF (NEW.total_amount,NEW.currency,NEW.reservation_id) IS DISTINCT FROM
                 (OLD.total_amount,OLD.currency,OLD.reservation_id)
                 AND EXISTS (SELECT 1 FROM public.contract_consideration_positions
                             WHERE tenant_id = OLD.tenant_id AND contract_id = OLD.id) THEN
                RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'Adopted Contract capacity and provenance are immutable';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER cc_contract_capacity_guard BEFORE UPDATE OF total_amount,currency,reservation_id ON public.contracts
              FOR EACH ROW EXECUTE FUNCTION public.cc_contract_capacity();

            CREATE OR REPLACE FUNCTION public.cc_transition_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'Transition deletion is forbidden';
              END IF;
              IF TG_OP = 'INSERT' AND NEW.status <> 'effective' THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Transition must initially be effective';
              END IF;
              IF TG_OP = 'UPDATE' AND (
                OLD.status <> 'effective' OR NEW.status <> 'reversed'
                OR (to_jsonb(NEW) - ARRAY['status','reversal_operation_id','reversal_source_operation_id',
                     'reversal_reason','reversal_reference','reversed_by','reversed_at']) IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status','reversal_operation_id','reversal_source_operation_id',
                     'reversal_reason','reversal_reference','reversed_by','reversed_at'])) THEN
                RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'Transition canonical history is immutable';
              END IF;
              -- NOWAIT avoids taking a competing child-to-parent waiting corridor for direct SQL.
              PERFORM id FROM public.contracts WHERE tenant_id = NEW.tenant_id AND id = NEW.contract_id FOR UPDATE NOWAIT;
              PERFORM id FROM public.contract_consideration_positions
                WHERE tenant_id = NEW.tenant_id AND id = NEW.position_id FOR UPDATE NOWAIT;
              -- New history may never be inserted behind any recorded Transition,
              -- including a Transition that has since been reversed. Updates retain
              -- successor-first reversal by considering only later effective history.
              IF EXISTS (SELECT 1 FROM public.contract_consideration_transitions t
                         WHERE t.tenant_id = NEW.tenant_id AND t.position_id = NEW.position_id
                           AND t.id <> NEW.id
                           AND (TG_OP = 'INSERT' OR t.status = 'effective')
                           AND (t.economic_date,t.semantic_precedence,t.source_id) >
                               (NEW.economic_date,NEW.semantic_precedence,NEW.source_id)) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Transition requires latest canonical order';
              END IF;
              IF TG_OP = 'UPDATE' THEN
                -- Validate while OLD is effective. A newly inserted invalid graph must not
                -- bypass canonical selection by being reversed in the same transaction.
                PERFORM public.cc_validate_transition_selection(OLD.tenant_id,OLD.id);
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER cc_transition_history_guard BEFORE INSERT OR UPDATE OR DELETE ON public.contract_consideration_transitions
              FOR EACH ROW EXECUTE FUNCTION public.cc_transition_history();

            CREATE OR REPLACE FUNCTION public.cc_graph_insert_lock() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              PERFORM id FROM public.contracts WHERE tenant_id = NEW.tenant_id AND id = NEW.contract_id FOR UPDATE NOWAIT;
              PERFORM id FROM public.contract_consideration_positions
                WHERE tenant_id = NEW.tenant_id AND id = NEW.position_id FOR UPDATE NOWAIT;
              RETURN NEW;
            END $$;
            CREATE TRIGGER cc_lot_insert_guard BEFORE INSERT ON public.contract_consideration_lots
              FOR EACH ROW EXECUTE FUNCTION public.cc_graph_insert_lock();
            CREATE TRIGGER cc_edge_insert_guard BEFORE INSERT ON public.contract_consideration_transition_lots
              FOR EACH ROW EXECUTE FUNCTION public.cc_graph_insert_lock();

            CREATE OR REPLACE FUNCTION public.cc_validate_transition_selection(p_tenant char(26), p_transition char(26)) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              t public.contract_consideration_transitions%ROWTYPE;
              predecessor record;
              need numeric(19,2);
              used numeric(19,2);
              expected numeric(19,2);
            BEGIN
              SELECT * INTO STRICT t FROM public.contract_consideration_transitions
                WHERE tenant_id = p_tenant AND id = p_transition;
                  need := t.transition_amount;
                  FOR predecessor IN
                    SELECT l.*, prior.economic_date,prior.semantic_precedence,prior.source_id,
                      l.amount - COALESCE((SELECT sum(e.consumed_amount)
                        FROM public.contract_consideration_transition_lots e
                        JOIN public.contract_consideration_transitions x ON x.tenant_id = e.tenant_id AND x.id = e.transition_id
                        WHERE e.tenant_id = p_tenant AND e.lot_id = l.id AND x.status = 'effective'
                          AND (x.economic_date,x.semantic_precedence,x.source_id) <
                              (t.economic_date,t.semantic_precedence,t.source_id)),0) AS remaining
                    FROM public.contract_consideration_lots l
                    LEFT JOIN public.contract_consideration_transitions prior ON prior.tenant_id = l.tenant_id AND prior.id = l.transition_id
                    WHERE l.tenant_id = p_tenant AND l.position_id = t.position_id
                      AND (l.lot_kind = 'GENESIS' OR (prior.status = 'effective' AND
                        (prior.economic_date,prior.semantic_precedence,prior.source_id) <
                        (t.economic_date,t.semantic_precedence,t.source_id)))
                      AND ((t.source_type = 'UNIT_HANDOVER_ACCEPTANCE' AND l.semantic_position IN ('UNPERFORMED_UNBILLED','BILLED_UNEARNED'))
                        OR (t.source_type = 'CONTRACTUAL_BILLING_ENTITLEMENT' AND l.semantic_position IN ('UNPERFORMED_UNBILLED','EARNED_UNBILLED')))
                    ORDER BY prior.economic_date NULLS FIRST,prior.semantic_precedence NULLS FIRST,prior.source_id NULLS FIRST,l.id
                  LOOP
                    expected := LEAST(need,predecessor.remaining);
                    SELECT COALESCE(sum(consumed_amount),0) INTO used FROM public.contract_consideration_transition_lots
                      WHERE tenant_id = p_tenant AND transition_id = t.id AND lot_id = predecessor.id;
                    IF expected < 0 OR used <> expected THEN
                      RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Consumption must follow canonical oldest-first selection';
                    END IF;
                    IF t.source_type = 'UNIT_HANDOVER_ACCEPTANCE' AND used <> predecessor.remaining THEN
                      RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Full handover must consume all unperformed capacity';
                    END IF;
                    need := need - used;
                  END LOOP;
                  IF need <> 0 THEN
                    RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Insufficient exact consideration capacity';
                  END IF;
            END $$;

            CREATE OR REPLACE FUNCTION public.cc_validate_graph(p_tenant char(26), p_position char(26)) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
              p public.contract_consideration_positions%ROWTYPE;
              t public.contract_consideration_transitions%ROWTYPE;
              source_contract_id char(26);
              source_economic_date date;
              source_amount numeric(19,2);
              source_currency text;
              source_status text;
              source_reversal_operation_id char(26);
              source_reversal_source_operation_id char(26);
              source_reversal_reason text;
              source_reversal_reference text;
              source_reversed_by bigint;
              source_reversed_at timestamptz;
            BEGIN
              SELECT * INTO p FROM public.contract_consideration_positions WHERE tenant_id = p_tenant AND id = p_position;
              IF NOT FOUND THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Missing consideration adoption';
              END IF;
              IF (SELECT count(*) FROM public.contract_consideration_lots l
                  WHERE l.tenant_id = p_tenant AND l.position_id = p.id AND l.lot_kind = 'GENESIS'
                    AND l.amount = p.consideration_amount AND l.currency = p.currency) <> 1 THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Adoption requires exactly one coherent genesis';
              END IF;
              IF NOT EXISTS (SELECT 1 FROM public.contracts c WHERE c.tenant_id = p_tenant
                             AND c.id = p.contract_id AND c.total_amount = p.consideration_amount AND c.currency = p.currency) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Adoption capacity differs from Contract';
              END IF;
              IF EXISTS (
                SELECT 1 FROM public.contractual_billing_entitlements s
                WHERE s.tenant_id = p_tenant AND s.contract_id = p.contract_id AND s.status = 'effective'
                  AND NOT EXISTS (SELECT 1 FROM public.contract_consideration_transitions x
                    WHERE x.tenant_id = p_tenant AND x.position_id = p.id AND x.source_type = 'CONTRACTUAL_BILLING_ENTITLEMENT'
                      AND x.source_id = s.id AND x.status = 'effective')
                UNION ALL
                SELECT 1 FROM public.unit_handover_acceptances s
                WHERE s.tenant_id = p_tenant AND s.contract_id = p.contract_id AND s.status = 'effective'
                  AND NOT EXISTS (SELECT 1 FROM public.contract_consideration_transitions x
                    WHERE x.tenant_id = p_tenant AND x.position_id = p.id AND x.source_type = 'UNIT_HANDOVER_ACCEPTANCE'
                      AND x.source_id = s.id AND x.status = 'effective')
              ) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Adopted source requires a complete Transition';
              END IF;
              IF EXISTS (
                SELECT 1 FROM public.contract_consideration_transition_lots e
                JOIN public.contract_consideration_lots a ON a.tenant_id = e.tenant_id AND a.id = e.lot_id
                JOIN public.contract_consideration_lots b ON b.tenant_id = e.tenant_id AND b.id = e.successor_lot_id
                JOIN public.contract_consideration_transitions x ON x.tenant_id = e.tenant_id AND x.id = e.transition_id
                LEFT JOIN public.contract_consideration_transitions prior ON prior.tenant_id = a.tenant_id AND prior.id = a.transition_id
                WHERE e.tenant_id = p_tenant AND e.position_id = p.id AND (
                  b.lot_kind <> 'TRANSITION_OUTPUT' OR b.transition_id <> e.transition_id
                  OR (prior.id IS NOT NULL AND (prior.economic_date,prior.semantic_precedence,prior.source_id) >=
                                               (x.economic_date,x.semantic_precedence,x.source_id))
                  OR (x.status = 'effective' AND prior.status = 'reversed')
                  OR NOT ((x.source_type = 'UNIT_HANDOVER_ACCEPTANCE' AND
                           ((a.semantic_position = 'UNPERFORMED_UNBILLED' AND b.semantic_position = 'EARNED_UNBILLED') OR
                            (a.semantic_position = 'BILLED_UNEARNED' AND b.semantic_position = 'BILLED_EARNED')))
                       OR (x.source_type = 'CONTRACTUAL_BILLING_ENTITLEMENT' AND
                           ((a.semantic_position = 'UNPERFORMED_UNBILLED' AND b.semantic_position = 'BILLED_UNEARNED') OR
                            (a.semantic_position = 'EARNED_UNBILLED' AND b.semantic_position = 'BILLED_EARNED'))))
                )) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Invalid consideration edge ownership, order or grammar';
              END IF;
              IF EXISTS (SELECT 1 FROM public.contract_consideration_lots l
                         WHERE l.tenant_id = p_tenant AND l.position_id = p.id AND l.lot_kind = 'TRANSITION_OUTPUT'
                           AND l.amount <> COALESCE((SELECT sum(e.consumed_amount) FROM public.contract_consideration_transition_lots e
                             WHERE e.tenant_id = p_tenant AND e.successor_lot_id = l.id),0)) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Successor lot lacks exact incoming consumption';
              END IF;
              IF EXISTS (SELECT 1 FROM public.contract_consideration_lots l
                         WHERE l.tenant_id = p_tenant AND l.position_id = p.id AND l.amount <
                           COALESCE((SELECT sum(e.consumed_amount) FROM public.contract_consideration_transition_lots e
                             JOIN public.contract_consideration_transitions x ON x.tenant_id = e.tenant_id AND x.id = e.transition_id
                             WHERE e.tenant_id = p_tenant AND e.lot_id = l.id AND x.status = 'effective'),0)) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Consideration lot over-consumed';
              END IF;
              FOR t IN SELECT * FROM public.contract_consideration_transitions
                WHERE tenant_id = p_tenant AND position_id = p.id
                ORDER BY economic_date,semantic_precedence,source_id
              LOOP
                IF t.source_type = 'UNIT_HANDOVER_ACCEPTANCE' THEN
                  SELECT s.contract_id,s.performance_date AS economic_date,s.performance_amount AS amount,s.currency,
                         s.status::text,s.reversal_operation_id,s.reversal_operation_id,
                         s.reversal_reason,s.reversal_reference,s.reversed_by,s.reversed_at
                    INTO source_contract_id,source_economic_date,source_amount,source_currency,
                         source_status,source_reversal_operation_id,source_reversal_source_operation_id,
                         source_reversal_reason,source_reversal_reference,source_reversed_by,source_reversed_at
                    FROM public.unit_handover_acceptances s WHERE s.tenant_id = p_tenant AND s.id = t.source_id;
                ELSE
                  SELECT s.contract_id,s.economic_date,s.amount,s.currency,s.status::text,s.reversal_operation_id,
                         s.source_correction_operation_id,s.reversal_reason,s.source_rescission_reference,
                         s.reversed_by,s.reversed_at
                    INTO source_contract_id,source_economic_date,source_amount,source_currency,
                         source_status,source_reversal_operation_id,source_reversal_source_operation_id,
                         source_reversal_reason,source_reversal_reference,source_reversed_by,source_reversed_at
                    FROM public.contractual_billing_entitlements s WHERE s.tenant_id = p_tenant AND s.id = t.source_id;
                END IF;
                IF NOT FOUND OR (source_contract_id,source_economic_date,source_amount,source_currency,source_status)
                   IS DISTINCT FROM (t.contract_id,t.economic_date,t.transition_amount,t.currency,t.status)
                   OR (t.status = 'reversed' AND
                     (t.reversal_operation_id,t.reversal_source_operation_id,t.reversal_reason,
                      t.reversal_reference,t.reversed_by,t.reversed_at) IS DISTINCT FROM
                     (source_reversal_operation_id,source_reversal_source_operation_id,source_reversal_reason,
                      source_reversal_reference,source_reversed_by,source_reversed_at))
                   OR (t.source_type = 'UNIT_HANDOVER_ACCEPTANCE' AND t.transition_amount <> p.consideration_amount) THEN
                  RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Transition must preserve exact authoritative source truth';
                END IF;
                IF t.transition_amount <> COALESCE((SELECT sum(e.consumed_amount) FROM public.contract_consideration_transition_lots e
                    WHERE e.tenant_id = p_tenant AND e.transition_id = t.id),0)
                   OR t.transition_amount <> COALESCE((SELECT sum(l.amount) FROM public.contract_consideration_lots l
                    WHERE l.tenant_id = p_tenant AND l.transition_id = t.id),0) THEN
                  RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Transition requires complete conserved graph';
                END IF;
                -- Effective history can be replayed from genesis in canonical order.
                -- Reversed graph retains its original edges and intrinsic source/grammar checks.
                IF t.status = 'effective' THEN
                  PERFORM public.cc_validate_transition_selection(p_tenant,t.id);
                END IF;
              END LOOP;
            END $$;

            CREATE OR REPLACE FUNCTION public.cc_graph_final_state() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE root_id char(26);
            BEGIN
              IF TG_TABLE_NAME = 'contract_consideration_positions' THEN
                root_id := NEW.id;
              ELSIF TG_TABLE_NAME IN ('contractual_billing_entitlements','unit_handover_acceptances') THEN
                SELECT id INTO root_id FROM public.contract_consideration_positions
                  WHERE tenant_id = NEW.tenant_id AND contract_id = NEW.contract_id;
                IF NOT FOUND THEN RETURN NULL; END IF;
              ELSE
                root_id := NEW.position_id;
              END IF;
              PERFORM public.cc_validate_graph(NEW.tenant_id,root_id);
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER cc_position_final AFTER INSERT ON public.contract_consideration_positions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();
            CREATE CONSTRAINT TRIGGER cc_transition_final AFTER INSERT OR UPDATE ON public.contract_consideration_transitions
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();
            CREATE CONSTRAINT TRIGGER cc_lot_final AFTER INSERT ON public.contract_consideration_lots
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();
            CREATE CONSTRAINT TRIGGER cc_edge_final AFTER INSERT ON public.contract_consideration_transition_lots
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();
            CREATE CONSTRAINT TRIGGER cc_billing_source_final AFTER INSERT OR UPDATE ON public.contractual_billing_entitlements
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();
            CREATE CONSTRAINT TRIGGER cc_handover_source_final AFTER INSERT OR UPDATE ON public.unit_handover_acceptances
              DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.cc_graph_final_state();

            REVOKE ALL ON public.contract_consideration_positions,public.contract_consideration_transitions,
              public.contract_consideration_lots,public.contract_consideration_transition_lots FROM PUBLIC;
            REVOKE EXECUTE ON FUNCTION public.cc_immutable_history(),public.cc_adoption_source(),
              public.cc_contract_capacity(),public.cc_transition_history(),public.cc_graph_insert_lock(),
              public.cc_validate_transition_selection(character,character),
              public.cc_validate_graph(character,character),public.cc_graph_final_state() FROM PUBLIC;
            SQL);

        $role = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (! is_string($role) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ACCOUNTING_RUNTIME_DB_ROLE must identify the pre-provisioned runtime role.');
        }
        $unsafe = DB::selectOne(<<<'SQL'
            SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = ? AND NOT
              (rolsuper OR rolcreaterole OR rolcreatedb OR rolreplication OR rolbypassrls))
              OR EXISTS (SELECT 1 FROM pg_class WHERE oid = 'public.contract_consideration_positions'::regclass
                         AND pg_get_userbyid(relowner) = ?) AS unsafe
            SQL, [$role, $role]);
        if ((bool) $unsafe->unsafe) {
            throw new RuntimeException('Contract Consideration runtime role must be unprivileged and must not own its tables.');
        }
        DB::unprepared(<<<SQL
            REVOKE ALL ON public.contract_consideration_positions,public.contract_consideration_transitions,
              public.contract_consideration_lots,public.contract_consideration_transition_lots FROM "{$role}";
            GRANT SELECT,INSERT ON public.contract_consideration_positions,public.contract_consideration_transitions,
              public.contract_consideration_lots,public.contract_consideration_transition_lots TO "{$role}";
            GRANT UPDATE ON public.contract_consideration_transitions TO "{$role}";
            REVOKE EXECUTE ON FUNCTION public.cc_immutable_history(),public.cc_adoption_source(),
              public.cc_contract_capacity(),public.cc_transition_history(),public.cc_graph_insert_lock(),
              public.cc_validate_transition_selection(character,character),
              public.cc_validate_graph(character,character),public.cc_graph_final_state() FROM "{$role}";
            SQL);
    }

    public function down(): void
    {
        if ($this->skip()) {
            return;
        }
        if (DB::table('contract_consideration_positions')->exists()) {
            throw new RuntimeException('Cannot remove Contract Consideration while historical adoption exists.');
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER cc_billing_source_final ON public.contractual_billing_entitlements;
            DROP TRIGGER cc_handover_source_final ON public.unit_handover_acceptances;
            DROP TRIGGER cc_contract_capacity_guard ON public.contracts;
            DROP TABLE public.contract_consideration_transition_lots;
            DROP TABLE public.contract_consideration_lots;
            DROP TABLE public.contract_consideration_transitions;
            DROP TABLE public.contract_consideration_positions;
            DROP FUNCTION public.cc_graph_final_state();
            DROP FUNCTION public.cc_validate_graph(character,character);
            DROP FUNCTION public.cc_validate_transition_selection(character,character);
            DROP FUNCTION public.cc_graph_insert_lock();
            DROP FUNCTION public.cc_transition_history();
            DROP FUNCTION public.cc_contract_capacity();
            DROP FUNCTION public.cc_adoption_source();
            DROP FUNCTION public.cc_immutable_history();
            SQL);
    }

    private function skip(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Contract Consideration requires PostgreSQL.');
        }
        if (DB::selectOne('SELECT current_schema() AS name')->name === 'public') {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }
        throw new RuntimeException('Contract Consideration requires the public schema.');
    }
};
