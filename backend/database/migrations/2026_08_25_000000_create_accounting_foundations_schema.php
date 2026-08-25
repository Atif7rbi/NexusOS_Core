<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Accounting Foundations requires PostgreSQL.');
        }
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $type = DB::selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'business_number_sequences'
              AND column_name = 'current_value'
            SQL);

        if ($type === null || $type->data_type !== 'bigint') {
            throw new RuntimeException('Unexpected business number sequence storage type.');
        }

        if (DB::table('business_number_sequences')->where('current_value', '<', 0)->exists()) {
            throw new RuntimeException('Negative business number sequence blocks Accounting migration.');
        }

        if (DB::table('business_number_sequences')->where('prefix', 'JRN')->exists()) {
            throw new RuntimeException('Pre-existing JRN sequence requires classification before migration.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.business_number_sequences
                ADD CONSTRAINT business_number_sequences_value_check
                CHECK (current_value >= 0);

            CREATE TABLE public.accounting_source_types (
                origin varchar(24) NOT NULL,
                key varchar(64) NOT NULL,
                owner_module varchar(64) NOT NULL,
                description varchar(255) NOT NULL,
                CONSTRAINT accounting_source_types_pkey PRIMARY KEY (origin, key),
                CONSTRAINT accounting_source_types_origin_check CHECK (origin IN ('business','opening_balance','reversal')),
                CONSTRAINT accounting_source_types_key_check CHECK (key ~ '^[a-z][a-z0-9_]{0,63}$'),
                CONSTRAINT accounting_source_types_owner_check CHECK (owner_module ~ '^[a-z][a-z0-9_]{0,63}$'),
                CONSTRAINT accounting_source_types_description_check CHECK (btrim(description) <> '')
            );

            INSERT INTO public.accounting_source_types(origin,key,owner_module,description) VALUES
              ('opening_balance','opening_balance_operation','accounting','Root Journal owned by an OpeningBalanceOperation'),
              ('reversal','journal_entry','accounting','Exact reversal of a Posted JournalEntry');

            CREATE TABLE public.accounting_settings (
                id char(26) NOT NULL,
                tenant_id char(26) NOT NULL,
                ledger_currency char(3) NOT NULL,
                activated_by bigint NOT NULL,
                activated_at timestamptz NOT NULL,
                CONSTRAINT accounting_settings_pkey PRIMARY KEY (id),
                CONSTRAINT accounting_settings_tenant_unique UNIQUE (tenant_id),
                CONSTRAINT accounting_settings_tenant_id_id_unique UNIQUE (tenant_id,id),
                CONSTRAINT accounting_settings_tenant_foreign FOREIGN KEY (tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_settings_actor_foreign FOREIGN KEY (tenant_id,activated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_settings_currency_check CHECK (ledger_currency = 'SAR')
            );

            CREATE TABLE public.accounts (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL,
                code varchar(32) NOT NULL, name varchar(160) NOT NULL, description text,
                kind varchar(16) NOT NULL, account_type varchar(16) NOT NULL,
                classification varchar(32), parent_id char(26), status varchar(16) NOT NULL DEFAULT 'active',
                created_by bigint NOT NULL, updated_by bigint NOT NULL,
                archived_at timestamptz, archived_by bigint, restored_at timestamptz, restored_by bigint,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                CONSTRAINT accounts_pkey PRIMARY KEY(id),
                CONSTRAINT accounts_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT accounts_tenant_code_unique UNIQUE(tenant_id,code),
                CONSTRAINT accounts_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_activation_foreign FOREIGN KEY(tenant_id) REFERENCES public.accounting_settings(tenant_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_tenant_parent_foreign FOREIGN KEY(tenant_id,parent_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_created_actor_foreign FOREIGN KEY(tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_updated_actor_foreign FOREIGN KEY(tenant_id,updated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_archived_actor_foreign FOREIGN KEY(tenant_id,archived_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_restored_actor_foreign FOREIGN KEY(tenant_id,restored_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounts_code_check CHECK(code = btrim(code) AND code ~ '^[A-Z0-9]+([._-][A-Z0-9]+)*$'),
                CONSTRAINT accounts_name_check CHECK(btrim(name) <> ''),
                CONSTRAINT accounts_description_check CHECK(description IS NULL OR btrim(description) <> ''),
                CONSTRAINT accounts_kind_check CHECK(kind IN ('group','posting')),
                CONSTRAINT accounts_type_check CHECK(account_type IN ('asset','liability','equity','revenue','expense')),
                CONSTRAINT accounts_kind_classification_check CHECK((kind='group' AND classification IS NULL) OR (kind='posting' AND classification IS NOT NULL)),
                CONSTRAINT accounts_type_classification_check CHECK(classification IS NULL OR
                  (account_type='asset' AND classification IN ('current_asset','non_current_asset')) OR
                  (account_type='liability' AND classification IN ('current_liability','non_current_liability')) OR
                  (account_type='equity' AND classification='equity') OR
                  (account_type='revenue' AND classification IN ('operating_revenue','other_revenue')) OR
                  (account_type='expense' AND classification IN ('cost_of_revenue','operating_expense','finance_cost','other_expense'))),
                CONSTRAINT accounts_parent_not_self_check CHECK(parent_id IS NULL OR parent_id<>id),
                CONSTRAINT accounts_status_check CHECK(status IN ('active','archived')),
                CONSTRAINT accounts_lifecycle_fields_check CHECK(
                  (status='archived' AND archived_at IS NOT NULL AND archived_by IS NOT NULL AND restored_at IS NULL AND restored_by IS NULL) OR
                  (status='active' AND archived_at IS NULL AND archived_by IS NULL AND ((restored_at IS NULL AND restored_by IS NULL) OR (restored_at IS NOT NULL AND restored_by IS NOT NULL))))
            );
            CREATE INDEX accounts_tenant_status_kind_index ON public.accounts(tenant_id,status,kind);
            CREATE INDEX accounts_tenant_parent_index ON public.accounts(tenant_id,parent_id);
            CREATE INDEX accounts_tenant_type_class_index ON public.accounts(tenant_id,account_type,classification);

            CREATE TABLE public.accounting_periods (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL,
                start_date date NOT NULL, end_date date NOT NULL, status varchar(16) NOT NULL DEFAULT 'open',
                created_by bigint NOT NULL, updated_by bigint NOT NULL,
                closed_at timestamptz, closed_by bigint, reopened_at timestamptz, reopened_by bigint, reopen_reason varchar(500),
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                CONSTRAINT accounting_periods_pkey PRIMARY KEY(id),
                CONSTRAINT accounting_periods_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT accounting_periods_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_activation_foreign FOREIGN KEY(tenant_id) REFERENCES public.accounting_settings(tenant_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_created_actor_foreign FOREIGN KEY(tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_updated_actor_foreign FOREIGN KEY(tenant_id,updated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_closed_actor_foreign FOREIGN KEY(tenant_id,closed_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_reopened_actor_foreign FOREIGN KEY(tenant_id,reopened_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_periods_dates_check CHECK(start_date>=DATE '2000-01-01' AND start_date<=end_date AND end_date<=DATE '9999-12-31'),
                CONSTRAINT accounting_periods_status_check CHECK(status IN ('open','closed')),
                CONSTRAINT accounting_periods_reopen_reason_check CHECK(reopen_reason IS NULL OR btrim(reopen_reason)<>''),
                CONSTRAINT accounting_periods_state_fields_check CHECK(
                  (status='closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND reopened_at IS NULL AND reopened_by IS NULL AND reopen_reason IS NULL) OR
                  (status='open' AND closed_at IS NULL AND closed_by IS NULL AND ((reopened_at IS NULL AND reopened_by IS NULL AND reopen_reason IS NULL) OR (reopened_at IS NOT NULL AND reopened_by IS NOT NULL AND reopen_reason IS NOT NULL))))
            );
            CREATE INDEX accounting_periods_tenant_dates_index ON public.accounting_periods(tenant_id,start_date,end_date);
            CREATE INDEX accounting_periods_tenant_status_dates_index ON public.accounting_periods(tenant_id,status,start_date,end_date);
            SQL);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }
        $used = DB::selectOne(<<<'SQL'
            SELECT EXISTS(SELECT 1 FROM public.accounting_settings) AS used
            SQL);
        if ((bool) $used->used || DB::table('business_number_sequences')->where('prefix', 'JRN')->exists()) {
            throw new RuntimeException('Accounting schema rollback refused after activation or JRN allocation.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE public.accounting_periods;
            DROP TABLE public.accounts;
            DROP TABLE public.accounting_settings;
            DROP TABLE public.accounting_source_types;
            ALTER TABLE public.business_number_sequences DROP CONSTRAINT business_number_sequences_value_check;
            SQL);
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema=DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema==='public') { return false; }
        if (app()->environment('testing')) { return true; }
        throw new RuntimeException('Accounting Foundations requires the public PostgreSQL schema.');
    }
};
