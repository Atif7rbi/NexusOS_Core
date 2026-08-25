<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Accounting runtime hardening requires PostgreSQL.');
        }
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (! is_string($runtimeRole)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            throw new RuntimeException(
                'ACCOUNTING_RUNTIME_DB_ROLE must name the pre-provisioned runtime PostgreSQL role.'
            );
        }

        $role = DB::selectOne(
            'SELECT rolname,rolsuper,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,rolbypassrls FROM pg_catalog.pg_roles WHERE rolname=?',
            [$runtimeRole],
        );

        if ($role === null || $role->rolsuper || $role->rolcreaterole
            || $role->rolcreatedb || $role->rolreplication || $role->rolbypassrls) {
            throw new RuntimeException(
                'Accounting runtime role must exist and be an unprivileged PostgreSQL role.'
            );
        }

        $currentRole = DB::selectOne('SELECT current_user AS role')->role;

        if ($runtimeRole === $currentRole) {
            throw new RuntimeException(
                'Accounting migrations and application runtime must use distinct PostgreSQL roles.'
            );
        }

        $this->assertProtectedObjectOwnership($runtimeRole);

        $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
        $tables = implode(',', array_map(
            static fn (string $table): string => 'public.'.$table,
            [
                'accounting_source_types',
                'accounting_settings',
                'accounts',
                'accounting_periods',
                'journal_entries',
                'journal_lines',
                'opening_balance_operations',
                'accounting_audits',
            ],
        ));

        DB::unprepared("REVOKE ALL ON TABLE {$tables} FROM {$identifier}");
        DB::unprepared("GRANT USAGE ON SCHEMA public TO {$identifier}");
        DB::unprepared("GRANT SELECT ON TABLE public.accounting_source_types TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT ON TABLE public.accounting_settings TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE public.accounts,public.accounting_periods TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE,DELETE ON TABLE public.journal_entries,public.journal_lines,public.opening_balance_operations TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT ON TABLE public.accounting_audits TO {$identifier}");
        DB::unprepared("GRANT SELECT,INSERT,UPDATE ON TABLE public.business_number_sequences TO {$identifier}");

        DB::unprepared(<<<SQL
            REVOKE EXECUTE ON FUNCTION
              public.prevent_accounting_source_type_mutation(),
              public.prevent_accounting_settings_mutation(),
              public.enforce_accounting_activation(),
              public.prevent_activated_tenant_currency_change(),
              public.enforce_account_hierarchy(),
              public.enforce_account_lifecycle_and_history(),
              public.prevent_account_delete(),
              public.enforce_accounting_period_nonoverlap(),
              public.enforce_accounting_period_mutation(),
              public.prevent_accounting_period_delete(),
              public.enforce_journal_entry_insert(),
              public.enforce_journal_entry_mutation(),
              public.enforce_journal_entry_delete(),
              public.validate_system_journal_final_state(),
              public.enforce_journal_line_parent_state(),
              public.enforce_opening_balance_mutation(),
              public.validate_opening_balance_operation(char,char),
              public.validate_opening_balance_final_state(),
              public.schedule_opening_balance_validation(),
              public.validate_accounting_audit_subject(),
              public.prevent_accounting_audit_mutation()
            FROM PUBLIC,{$identifier};
            SQL);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }
        $runtimeRole = getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        if (is_string($runtimeRole)
            && preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $runtimeRole)) {
            $identifier = '"'.str_replace('"', '""', $runtimeRole).'"';
            DB::unprepared("REVOKE ALL ON TABLE public.accounting_source_types,public.accounting_settings,public.accounts,public.accounting_periods,public.journal_entries,public.journal_lines,public.opening_balance_operations,public.accounting_audits FROM {$identifier}");
        }
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema=DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema==='public') { return false; }
        if (app()->environment('testing')) { return true; }
        throw new RuntimeException('Accounting Foundations requires the public PostgreSQL schema.');
    }

    private function assertProtectedObjectOwnership(string $runtimeRole): void
    {
        $ownsSchema=(bool) DB::selectOne(<<<'SQL'
            SELECT EXISTS(
              SELECT 1 FROM pg_catalog.pg_namespace
              WHERE nspname='public' AND pg_catalog.pg_get_userbyid(nspowner)=?
            ) AS owns
            SQL,
            [$runtimeRole],
        )->owns;

        $ownsTable=(bool) DB::selectOne(<<<'SQL'
            SELECT EXISTS(
              SELECT 1
              FROM pg_catalog.pg_class object
              JOIN pg_catalog.pg_namespace schema ON schema.oid=object.relnamespace
              WHERE schema.nspname='public'
                AND object.relkind IN ('r','p')
                AND object.relname IN (
                  'business_number_sequences','accounting_source_types',
                  'accounting_settings','accounts','accounting_periods',
                  'journal_entries','journal_lines','opening_balance_operations',
                  'accounting_audits'
                )
                AND pg_catalog.pg_get_userbyid(object.relowner)=?
            ) AS owns
            SQL,
            [$runtimeRole],
        )->owns;

        $ownsFunction=(bool) DB::selectOne(<<<'SQL'
            SELECT EXISTS(
              SELECT 1
              FROM pg_catalog.pg_proc function
              JOIN pg_catalog.pg_namespace schema ON schema.oid=function.pronamespace
              WHERE schema.nspname='public'
                AND function.proname IN (
                  'prevent_accounting_source_type_mutation',
                  'prevent_accounting_settings_mutation',
                  'enforce_accounting_activation',
                  'prevent_activated_tenant_currency_change',
                  'enforce_account_hierarchy',
                  'enforce_account_lifecycle_and_history',
                  'prevent_account_delete',
                  'enforce_accounting_period_nonoverlap',
                  'enforce_accounting_period_mutation',
                  'prevent_accounting_period_delete',
                  'enforce_journal_entry_insert',
                  'enforce_journal_entry_mutation',
                  'enforce_journal_entry_delete',
                  'validate_system_journal_final_state',
                  'enforce_journal_line_parent_state',
                  'enforce_opening_balance_mutation',
                  'validate_opening_balance_operation',
                  'validate_opening_balance_final_state',
                  'schedule_opening_balance_validation',
                  'validate_accounting_audit_subject',
                  'prevent_accounting_audit_mutation'
                )
                AND pg_catalog.pg_get_userbyid(function.proowner)=?
            ) AS owns
            SQL,
            [$runtimeRole],
        )->owns;

        if ($ownsSchema || $ownsTable || $ownsFunction) {
            throw new RuntimeException(
                'Accounting runtime role must not own the public schema, protected tables, or trigger functions.'
            );
        }
    }
};
