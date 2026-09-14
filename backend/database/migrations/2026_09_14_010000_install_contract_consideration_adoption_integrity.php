<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Contract Consideration adoption integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_contract_consideration_adoption_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration adoption deletion is forbidden';
              END IF;

              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration adoption is immutable';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contract_consideration_adoption_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contract_consideration_adoptions
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contract_consideration_adoption_history();


            CREATE OR REPLACE FUNCTION public.enforce_contract_consideration_position_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration Position deletion is forbidden';
              END IF;

              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration Position capacity snapshot is immutable';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contract_consideration_position_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contract_consideration_positions
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contract_consideration_position_history();


            CREATE OR REPLACE FUNCTION public.enforce_contract_consideration_genesis_history()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration genesis lot deletion is forbidden';
              END IF;

              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract Consideration genesis lot is immutable';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contract_consideration_genesis_history_guard
            BEFORE INSERT OR UPDATE OR DELETE
            ON public.contract_consideration_lots
            FOR EACH ROW
            EXECUTE FUNCTION public.enforce_contract_consideration_genesis_history();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.validate_contract_consideration_adoption_final_state()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
              target_tenant_id char(26);
              target_contract_id char(26);
              adoption_row public.contract_consideration_adoptions%ROWTYPE;
              position_row public.contract_consideration_positions%ROWTYPE;
              genesis_row public.contract_consideration_lots%ROWTYPE;
              contract_row public.contracts%ROWTYPE;
              adoption_count bigint;
              position_count bigint;
              genesis_count bigint;
            BEGIN
              target_tenant_id := NEW.tenant_id;
              target_contract_id := NEW.contract_id;

              SELECT COUNT(*)
              INTO adoption_count
              FROM public.contract_consideration_adoptions
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              SELECT COUNT(*)
              INTO position_count
              FROM public.contract_consideration_positions
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              SELECT COUNT(*)
              INTO genesis_count
              FROM public.contract_consideration_lots
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              IF adoption_count <> 1
                 OR position_count <> 1
                 OR genesis_count <> 1 THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'Contract Consideration adoption requires exactly one adoption, Position, and genesis lot';
              END IF;

              SELECT *
              INTO adoption_row
              FROM public.contract_consideration_adoptions
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              SELECT *
              INTO position_row
              FROM public.contract_consideration_positions
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              SELECT *
              INTO genesis_row
              FROM public.contract_consideration_lots
              WHERE tenant_id = target_tenant_id
                AND contract_id = target_contract_id;

              SELECT *
              INTO contract_row
              FROM public.contracts
              WHERE id = target_contract_id;

              IF contract_row.id IS NULL
                 OR contract_row.tenant_id <> target_tenant_id
                 OR adoption_row.status <> 'ADOPTED'
                 OR adoption_row.adoption_basis <> 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES'
                 OR adoption_row.coordination_scope_version <> 'CONTRACT_CONSIDERATION_V1'
                 OR adoption_row.currency <> 'SAR'
                 OR position_row.adoption_id <> adoption_row.id
                 OR position_row.currency <> adoption_row.currency
                 OR position_row.consideration_amount <> adoption_row.consideration_amount
                 OR position_row.source_contract_total_snapshot <> adoption_row.consideration_amount
                 OR genesis_row.position_root_id <> position_row.id
                 OR genesis_row.lot_kind <> 'GENESIS'
                 OR genesis_row.semantic_position <> 'UNPERFORMED_UNBILLED'
                 OR genesis_row.currency <> adoption_row.currency
                 OR genesis_row.amount <> adoption_row.consideration_amount
                 OR contract_row.currency <> adoption_row.currency
                 OR contract_row.total_amount <> adoption_row.consideration_amount THEN
                RAISE EXCEPTION USING
                  ERRCODE = '23514',
                  MESSAGE = 'Contract Consideration adoption final-state projection mismatch';
              END IF;

              RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER contract_consideration_adoption_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.contract_consideration_adoptions
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contract_consideration_adoption_final_state();

            CREATE CONSTRAINT TRIGGER contract_consideration_position_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.contract_consideration_positions
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contract_consideration_adoption_final_state();

            CREATE CONSTRAINT TRIGGER contract_consideration_genesis_final_state_guard
            AFTER INSERT OR UPDATE
            ON public.contract_consideration_lots
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.validate_contract_consideration_adoption_final_state();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.prevent_adopted_contract_consideration_capacity_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF (
                   NEW.total_amount IS DISTINCT FROM OLD.total_amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                 )
                 AND EXISTS (
                   SELECT 1
                   FROM public.contract_consideration_adoptions adoption
                   WHERE adoption.tenant_id = OLD.tenant_id
                     AND adoption.contract_id = OLD.id
                 ) THEN
                RAISE EXCEPTION USING
                  ERRCODE = '55000',
                  MESSAGE = 'Contract consideration capacity is immutable after coordination adoption';
              END IF;

              RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contracts_consideration_capacity_guard
            BEFORE UPDATE OF total_amount,currency
            ON public.contracts
            FOR EACH ROW
            EXECUTE FUNCTION public.prevent_adopted_contract_consideration_capacity_mutation();
            SQL);

        DB::unprepared(<<<'SQL'
            REVOKE EXECUTE ON FUNCTION
              public.enforce_contract_consideration_adoption_history(),
              public.enforce_contract_consideration_position_history(),
              public.enforce_contract_consideration_genesis_history(),
              public.validate_contract_consideration_adoption_final_state(),
              public.prevent_adopted_contract_consideration_capacity_mutation()
            FROM PUBLIC;
            SQL);

        $this->grantRuntimePrivileges();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Contract Consideration adoption integrity requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $this->revokeRuntimePrivileges();

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS
              contracts_consideration_capacity_guard
              ON public.contracts;

            DROP TRIGGER IF EXISTS
              contract_consideration_genesis_final_state_guard
              ON public.contract_consideration_lots;

            DROP TRIGGER IF EXISTS
              contract_consideration_position_final_state_guard
              ON public.contract_consideration_positions;

            DROP TRIGGER IF EXISTS
              contract_consideration_adoption_final_state_guard
              ON public.contract_consideration_adoptions;

            DROP TRIGGER IF EXISTS
              contract_consideration_genesis_history_guard
              ON public.contract_consideration_lots;

            DROP TRIGGER IF EXISTS
              contract_consideration_position_history_guard
              ON public.contract_consideration_positions;

            DROP TRIGGER IF EXISTS
              contract_consideration_adoption_history_guard
              ON public.contract_consideration_adoptions;

            DROP FUNCTION IF EXISTS
              public.prevent_adopted_contract_consideration_capacity_mutation();

            DROP FUNCTION IF EXISTS
              public.validate_contract_consideration_adoption_final_state();

            DROP FUNCTION IF EXISTS
              public.enforce_contract_consideration_genesis_history();

            DROP FUNCTION IF EXISTS
              public.enforce_contract_consideration_position_history();

            DROP FUNCTION IF EXISTS
              public.enforce_contract_consideration_adoption_history();
            SQL);
    }

    private function grantRuntimePrivileges(): void
    {
        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE ALL ON TABLE
               public.contract_consideration_adoptions,
               public.contract_consideration_positions,
               public.contract_consideration_lots
             FROM {$identifier}",
        );

        DB::unprepared(
            "GRANT SELECT,INSERT ON TABLE
               public.contract_consideration_adoptions,
               public.contract_consideration_positions,
               public.contract_consideration_lots
             TO {$identifier}",
        );

        DB::unprepared(<<<SQL
            REVOKE EXECUTE ON FUNCTION
              public.enforce_contract_consideration_adoption_history(),
              public.enforce_contract_consideration_position_history(),
              public.enforce_contract_consideration_genesis_history(),
              public.validate_contract_consideration_adoption_final_state(),
              public.prevent_adopted_contract_consideration_capacity_mutation()
            FROM {$identifier};
            SQL);
    }

    private function revokeRuntimePrivileges(): void
    {
        $runtimeRole = $this->runtimeRole();
        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';

        DB::unprepared(
            "REVOKE ALL ON TABLE
               public.contract_consideration_adoptions,
               public.contract_consideration_positions,
               public.contract_consideration_lots
             FROM {$identifier}",
        );
    }

    private function runtimeRole(): string
    {
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (
            ! is_string($runtimeRole)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)
        ) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.',
            );
        }

        $role = DB::selectOne(
            'SELECT
               rolname,
               rolsuper,
               rolcreaterole,
               rolcreatedb,
               rolreplication,
               rolbypassrls
             FROM pg_catalog.pg_roles
             WHERE rolname=?',
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
                'Contract Consideration runtime role must exist and remain unprivileged.',
            );
        }

        return $runtimeRole;
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema = DB::selectOne(
            'SELECT current_schema() AS name',
        )->name;

        if ($schema === 'public') {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException(
            'Contract Consideration adoption integrity requires the public PostgreSQL schema.',
        );
    }
};
