<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->skipIsolatedTestSchema()) { return; }
        DB::unprepared(<<<'SQL'
            CREATE TABLE public.journal_entries (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL, accounting_period_id char(26),
                entry_date date NOT NULL, description varchar(500) NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'draft', origin varchar(24) NOT NULL,
                source_type varchar(64), source_id char(26), journal_number varchar(50),
                journal_number_year smallint, journal_sequence_number bigint,
                created_by bigint NOT NULL, updated_by bigint NOT NULL, posted_by bigint,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, posted_at timestamptz,
                reverses_journal_entry_id char(26), reversal_reason varchar(500),
                CONSTRAINT journal_entries_pkey PRIMARY KEY(id),
                CONSTRAINT journal_entries_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT journal_entries_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_activation_foreign FOREIGN KEY(tenant_id) REFERENCES public.accounting_settings(tenant_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_period_foreign FOREIGN KEY(tenant_id,accounting_period_id) REFERENCES public.accounting_periods(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_reversal_foreign FOREIGN KEY(tenant_id,reverses_journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_created_actor_foreign FOREIGN KEY(tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_updated_actor_foreign FOREIGN KEY(tenant_id,updated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_posted_actor_foreign FOREIGN KEY(tenant_id,posted_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_source_foreign FOREIGN KEY(origin,source_type) REFERENCES public.accounting_source_types(origin,key) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_entries_tenant_number_unique UNIQUE(tenant_id,journal_number),
                CONSTRAINT journal_entries_tenant_year_sequence_unique UNIQUE(tenant_id,journal_number_year,journal_sequence_number),
                CONSTRAINT journal_entries_description_check CHECK(btrim(description)<>''),
                CONSTRAINT journal_entries_entry_date_check CHECK(entry_date BETWEEN DATE '2000-01-01' AND DATE '9999-12-31'),
                CONSTRAINT journal_entries_status_check CHECK(status IN ('draft','posted')),
                CONSTRAINT journal_entries_origin_check CHECK(origin IN ('manual','business','opening_balance','reversal')),
                CONSTRAINT journal_entries_source_shape_check CHECK(
                  (origin='manual' AND source_type IS NULL AND source_id IS NULL AND reverses_journal_entry_id IS NULL AND reversal_reason IS NULL) OR
                  (origin='business' AND source_type IS NOT NULL AND source_id IS NOT NULL AND reverses_journal_entry_id IS NULL AND reversal_reason IS NULL) OR
                  (origin='opening_balance' AND source_type='opening_balance_operation' AND source_id IS NOT NULL AND reverses_journal_entry_id IS NULL AND reversal_reason IS NULL) OR
                  (origin='reversal' AND source_type='journal_entry' AND source_id IS NOT NULL AND reverses_journal_entry_id IS NOT NULL AND reversal_reason IS NOT NULL)),
                CONSTRAINT journal_entries_reversal_reason_check CHECK(reversal_reason IS NULL OR btrim(reversal_reason)<>''),
                CONSTRAINT journal_entries_reversal_not_self_check CHECK(reverses_journal_entry_id IS NULL OR reverses_journal_entry_id<>id),
                CONSTRAINT journal_entries_state_fields_check CHECK(
                  (status='draft' AND accounting_period_id IS NULL AND journal_number IS NULL AND journal_number_year IS NULL AND journal_sequence_number IS NULL AND posted_by IS NULL AND posted_at IS NULL) OR
                  (status='posted' AND accounting_period_id IS NOT NULL AND journal_number IS NOT NULL AND journal_number_year IS NOT NULL AND journal_sequence_number IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL)),
                CONSTRAINT journal_entries_number_year_check CHECK(journal_number_year IS NULL OR (journal_number_year BETWEEN 2000 AND 9999 AND journal_number_year=extract(year from entry_date))),
                CONSTRAINT journal_entries_sequence_check CHECK(journal_sequence_number IS NULL OR journal_sequence_number>0),
                CONSTRAINT journal_entries_number_format_check CHECK(journal_number IS NULL OR journal_number='JRN-'||journal_number_year::text||'-'||lpad(journal_sequence_number::text,greatest(3,length(journal_sequence_number::text)),'0'))
            );
            CREATE UNIQUE INDEX journal_entries_source_unique ON public.journal_entries(tenant_id,origin,source_type,source_id) WHERE origin<>'manual';
            CREATE UNIQUE INDEX journal_entries_direct_reversal_unique ON public.journal_entries(tenant_id,reverses_journal_entry_id) WHERE reverses_journal_entry_id IS NOT NULL;
            CREATE INDEX journal_entries_tenant_status_date_number_index ON public.journal_entries(tenant_id,status,entry_date,journal_sequence_number);
            CREATE INDEX journal_entries_tenant_period_index ON public.journal_entries(tenant_id,accounting_period_id);

            CREATE TABLE public.journal_lines (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL, journal_entry_id char(26) NOT NULL,
                line_number integer NOT NULL, account_id char(26) NOT NULL,
                debit numeric(19,2) NOT NULL DEFAULT 0.00, credit numeric(19,2) NOT NULL DEFAULT 0.00,
                memo varchar(500), created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                CONSTRAINT journal_lines_pkey PRIMARY KEY(id),
                CONSTRAINT journal_lines_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT journal_lines_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_lines_journal_foreign FOREIGN KEY(tenant_id,journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT journal_lines_account_foreign FOREIGN KEY(tenant_id,account_id) REFERENCES public.accounts(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT journal_lines_journal_line_number_unique UNIQUE(tenant_id,journal_entry_id,line_number),
                CONSTRAINT journal_lines_line_number_check CHECK(line_number>0),
                CONSTRAINT journal_lines_debit_credit_xor_check CHECK((debit>0 AND credit=0) OR (credit>0 AND debit=0)),
                CONSTRAINT journal_lines_memo_check CHECK(memo IS NULL OR btrim(memo)<>'')
            );
            CREATE INDEX journal_lines_tenant_account_journal_index ON public.journal_lines(tenant_id,account_id,journal_entry_id);

            CREATE TABLE public.opening_balance_operations (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL, status varchar(16) NOT NULL DEFAULT 'draft', effect_state varchar(16),
                accounting_date date NOT NULL, journal_entry_id char(26) NOT NULL, latest_effect_journal_entry_id char(26),
                created_by bigint NOT NULL, updated_by bigint NOT NULL, posted_by bigint, effect_updated_by bigint,
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, posted_at timestamptz, effect_updated_at timestamptz,
                CONSTRAINT opening_balance_operations_pkey PRIMARY KEY(id),
                CONSTRAINT opening_balance_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT opening_balance_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_activation_foreign FOREIGN KEY(tenant_id) REFERENCES public.accounting_settings(tenant_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_root_journal_foreign FOREIGN KEY(tenant_id,journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_latest_journal_foreign FOREIGN KEY(tenant_id,latest_effect_journal_entry_id) REFERENCES public.journal_entries(tenant_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_root_journal_unique UNIQUE(tenant_id,journal_entry_id),
                CONSTRAINT opening_balance_created_actor_foreign FOREIGN KEY(tenant_id,created_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_updated_actor_foreign FOREIGN KEY(tenant_id,updated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_posted_actor_foreign FOREIGN KEY(tenant_id,posted_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_effect_actor_foreign FOREIGN KEY(tenant_id,effect_updated_by) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT opening_balance_status_check CHECK(status IN ('draft','posted')),
                CONSTRAINT opening_balance_effect_check CHECK(effect_state IS NULL OR effect_state IN ('effective','neutralized')),
                CONSTRAINT opening_balance_date_check CHECK(accounting_date BETWEEN DATE '2000-01-01' AND DATE '9999-12-31'),
                CONSTRAINT opening_balance_state_fields_check CHECK(
                  (status='draft' AND effect_state IS NULL AND latest_effect_journal_entry_id IS NULL AND posted_by IS NULL AND posted_at IS NULL AND effect_updated_by IS NULL AND effect_updated_at IS NULL) OR
                  (status='posted' AND effect_state IS NOT NULL AND latest_effect_journal_entry_id IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL AND effect_updated_by IS NOT NULL AND effect_updated_at IS NOT NULL))
            );
            CREATE UNIQUE INDEX opening_balance_latest_journal_unique ON public.opening_balance_operations(tenant_id,latest_effect_journal_entry_id) WHERE latest_effect_journal_entry_id IS NOT NULL;
            CREATE UNIQUE INDEX opening_balance_tenant_slot_unique ON public.opening_balance_operations(tenant_id) WHERE status='draft' OR (status='posted' AND effect_state='effective');
            CREATE INDEX opening_balance_tenant_status_index ON public.opening_balance_operations(tenant_id,status,effect_state);

            CREATE TABLE public.accounting_audits (
                id char(26) NOT NULL, tenant_id char(26) NOT NULL, event varchar(80) NOT NULL,
                subject_type varchar(40) NOT NULL, subject_id char(26) NOT NULL, actor_id bigint NOT NULL,
                context jsonb NOT NULL DEFAULT '{}'::jsonb, recorded_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT accounting_audits_pkey PRIMARY KEY(id),
                CONSTRAINT accounting_audits_tenant_id_id_unique UNIQUE(tenant_id,id),
                CONSTRAINT accounting_audits_tenant_foreign FOREIGN KEY(tenant_id) REFERENCES public.tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_audits_activation_foreign FOREIGN KEY(tenant_id) REFERENCES public.accounting_settings(tenant_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_audits_actor_foreign FOREIGN KEY(tenant_id,actor_id) REFERENCES public.tenant_users(tenant_id,user_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT accounting_audits_event_check CHECK(event IN ('accounting.activated','account.created','account.updated','account.archived','account.restored','journal.draft_created','journal.draft_deleted','journal.posted','journal.reversed','period.created','period.boundaries_changed','period.closed','period.reopened','opening_balance.created','opening_balance.draft_deleted','opening_balance.posted','opening_balance.reversed','opening_balance.reactivated')),
                CONSTRAINT accounting_audits_subject_type_check CHECK(subject_type IN ('accounting_settings','account','journal_entry','accounting_period','opening_balance_operation')),
                CONSTRAINT accounting_audits_context_object_check CHECK(jsonb_typeof(context)='object')
            );
            CREATE INDEX accounting_audits_subject_time_index ON public.accounting_audits(tenant_id,subject_type,subject_id,recorded_at,id);
            CREATE INDEX accounting_audits_event_time_index ON public.accounting_audits(tenant_id,event,recorded_at,id);
            CREATE INDEX accounting_audits_actor_time_index ON public.accounting_audits(tenant_id,actor_id,recorded_at,id);
            SQL);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) { return; }
        $tables = ['accounting_audits','opening_balance_operations','journal_lines','journal_entries'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Accounting ledger rollback refused while data exists.');
            }
        }
        DB::unprepared('DROP TABLE public.accounting_audits; DROP TABLE public.opening_balance_operations; DROP TABLE public.journal_lines; DROP TABLE public.journal_entries;');
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema=DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema==='public') { return false; }
        if (app()->environment('testing')) { return true; }
        throw new RuntimeException('Accounting Foundations requires the public PostgreSQL schema.');
    }
};
