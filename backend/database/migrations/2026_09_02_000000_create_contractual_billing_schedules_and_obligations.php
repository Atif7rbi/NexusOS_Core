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
                'Contractual Billing Source v1 requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE public.contractual_billing_schedules (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              schedule_operation_id char(26) NOT NULL,
              billing_model varchar(64) NOT NULL,
              status varchar(16) NOT NULL,

              contractual_timezone varchar(64),

              finalization_operation_id char(26),
              finalized_by bigint,
              finalized_at timestamptz,

              replaces_schedule_id char(26),

              draft_cancellation_operation_id char(26),
              draft_cancelled_by bigint,
              draft_cancelled_at timestamptz,
              draft_cancellation_reason varchar(500),
              draft_cancellation_reference varchar(500),

              source_correction_operation_id char(26),
              source_corrected_by bigint,
              source_corrected_at timestamptz,
              source_correction_reason varchar(500),
              source_correction_reference varchar(500),

              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contractual_billing_schedules_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contractual_billing_schedules_operation_unique
                UNIQUE (tenant_id,schedule_operation_id),

              CONSTRAINT contractual_billing_schedules_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_contract_foreign
                FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_replaces_foreign
                FOREIGN KEY (tenant_id,replaces_schedule_id)
                REFERENCES public.contractual_billing_schedules(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_created_actor_foreign
                FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_finalized_actor_foreign
                FOREIGN KEY (tenant_id,finalized_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_draft_cancelled_actor_foreign
                FOREIGN KEY (tenant_id,draft_cancelled_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_source_corrected_actor_foreign
                FOREIGN KEY (tenant_id,source_corrected_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_schedules_operation_ulid_check
                CHECK (
                  schedule_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_schedules_finalization_operation_ulid_check
                CHECK (
                  finalization_operation_id IS NULL
                  OR finalization_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT cbs_draft_cancel_operation_ulid_check
                CHECK (
                  draft_cancellation_operation_id IS NULL
                  OR draft_cancellation_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT cbs_source_correction_operation_ulid_check
                CHECK (
                  source_correction_operation_id IS NULL
                  OR source_correction_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_schedules_billing_model_check
                CHECK (
                  billing_model =
                    'fixed_date_unconditional_full_schedule'
                ),

              CONSTRAINT contractual_billing_schedules_status_check
                CHECK (
                  status IN (
                    'draft',
                    'finalized',
                    'superseded',
                    'cancelled'
                  )
                ),

              CONSTRAINT contractual_billing_schedules_replacement_identity_check
                CHECK (
                  replaces_schedule_id IS NULL
                  OR replaces_schedule_id <> id
                ),

              CONSTRAINT contractual_billing_schedules_timezone_check
                CHECK (
                  contractual_timezone IS NULL
                  OR btrim(contractual_timezone) <> ''
                ),

              CONSTRAINT contractual_billing_schedules_lifecycle_check
                CHECK (
                  (
                    status = 'draft'
                    AND contractual_timezone IS NULL
                    AND finalization_operation_id IS NULL
                    AND finalized_by IS NULL
                    AND finalized_at IS NULL
                    AND draft_cancellation_operation_id IS NULL
                    AND draft_cancelled_by IS NULL
                    AND draft_cancelled_at IS NULL
                    AND draft_cancellation_reason IS NULL
                    AND draft_cancellation_reference IS NULL
                    AND source_correction_operation_id IS NULL
                    AND source_corrected_by IS NULL
                    AND source_corrected_at IS NULL
                    AND source_correction_reason IS NULL
                    AND source_correction_reference IS NULL
                  )
                  OR
                  (
                    status = 'finalized'
                    AND contractual_timezone IS NOT NULL
                    AND btrim(contractual_timezone) <> ''
                    AND finalization_operation_id IS NOT NULL
                    AND finalized_by IS NOT NULL
                    AND finalized_at IS NOT NULL
                    AND draft_cancellation_operation_id IS NULL
                    AND draft_cancelled_by IS NULL
                    AND draft_cancelled_at IS NULL
                    AND draft_cancellation_reason IS NULL
                    AND draft_cancellation_reference IS NULL
                    AND source_correction_operation_id IS NULL
                    AND source_corrected_by IS NULL
                    AND source_corrected_at IS NULL
                    AND source_correction_reason IS NULL
                    AND source_correction_reference IS NULL
                  )
                  OR
                  (
                    status = 'superseded'
                    AND contractual_timezone IS NOT NULL
                    AND btrim(contractual_timezone) <> ''
                    AND finalization_operation_id IS NOT NULL
                    AND finalized_by IS NOT NULL
                    AND finalized_at IS NOT NULL
                    AND draft_cancellation_operation_id IS NULL
                    AND draft_cancelled_by IS NULL
                    AND draft_cancelled_at IS NULL
                    AND draft_cancellation_reason IS NULL
                    AND draft_cancellation_reference IS NULL
                    AND source_correction_operation_id IS NOT NULL
                    AND source_corrected_by IS NOT NULL
                    AND source_corrected_at IS NOT NULL
                    AND source_corrected_at >= finalized_at
                    AND source_correction_reason IS NOT NULL
                    AND btrim(source_correction_reason) <> ''
                  )
                  OR
                  (
                    status = 'cancelled'
                    AND (
                      (
                        contractual_timezone IS NULL
                        AND finalization_operation_id IS NULL
                        AND finalized_by IS NULL
                        AND finalized_at IS NULL
                        AND draft_cancellation_operation_id IS NOT NULL
                        AND draft_cancelled_by IS NOT NULL
                        AND draft_cancelled_at IS NOT NULL
                        AND draft_cancellation_reason IS NOT NULL
                        AND btrim(draft_cancellation_reason) <> ''
                        AND source_correction_operation_id IS NULL
                        AND source_corrected_by IS NULL
                        AND source_corrected_at IS NULL
                        AND source_correction_reason IS NULL
                        AND source_correction_reference IS NULL
                      )
                      OR
                      (
                        contractual_timezone IS NOT NULL
                        AND btrim(contractual_timezone) <> ''
                        AND finalization_operation_id IS NOT NULL
                        AND finalized_by IS NOT NULL
                        AND finalized_at IS NOT NULL
                        AND draft_cancellation_operation_id IS NULL
                        AND draft_cancelled_by IS NULL
                        AND draft_cancelled_at IS NULL
                        AND draft_cancellation_reason IS NULL
                        AND draft_cancellation_reference IS NULL
                        AND source_correction_operation_id IS NOT NULL
                        AND source_corrected_by IS NOT NULL
                        AND source_corrected_at IS NOT NULL
                        AND source_corrected_at >= finalized_at
                        AND source_correction_reason IS NOT NULL
                        AND btrim(source_correction_reason) <> ''
                      )
                    )
                  )
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contractual_billing_schedules_current_finalized_unique
              ON public.contractual_billing_schedules(tenant_id,contract_id)
              WHERE status='finalized'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contractual_billing_schedules_finalization_operation_unique
              ON public.contractual_billing_schedules(
                tenant_id,
                finalization_operation_id
              )
              WHERE finalization_operation_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cbs_draft_cancel_operation_unique
              ON public.contractual_billing_schedules(
                tenant_id,
                draft_cancellation_operation_id
              )
              WHERE draft_cancellation_operation_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cbs_source_correction_operation_unique
              ON public.contractual_billing_schedules(
                tenant_id,
                source_correction_operation_id
              )
              WHERE source_correction_operation_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_schedules_contract_history_index
              ON public.contractual_billing_schedules(
                tenant_id,
                contract_id,
                created_at,
                id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_schedules_replacement_index
              ON public.contractual_billing_schedules(
                tenant_id,
                replaces_schedule_id
              )
              WHERE replaces_schedule_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE public.contractual_billing_obligations (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              schedule_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              obligation_operation_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,

              amount numeric(19,2) NOT NULL,
              currency char(3) NOT NULL,
              contractual_due_date date NOT NULL,
              trigger_kind varchar(64) NOT NULL,
              contractual_reference varchar(500) NOT NULL,

              draft_membership_status varchar(16) NOT NULL,

              removal_operation_id char(26),
              removed_by bigint,
              removed_at timestamptz,
              removal_reason varchar(500),

              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contractual_billing_obligations_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contractual_billing_obligations_operation_unique
                UNIQUE (tenant_id,obligation_operation_id),

              CONSTRAINT contractual_billing_obligations_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_schedule_foreign
                FOREIGN KEY (tenant_id,schedule_id)
                REFERENCES public.contractual_billing_schedules(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_contract_foreign
                FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_customer_foreign
                FOREIGN KEY (tenant_id,customer_id)
                REFERENCES public.customers(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_created_actor_foreign
                FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_removed_actor_foreign
                FOREIGN KEY (tenant_id,removed_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_obligations_operation_ulid_check
                CHECK (
                  obligation_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_obligations_removal_operation_ulid_check
                CHECK (
                  removal_operation_id IS NULL
                  OR removal_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_obligations_amount_positive_check
                CHECK (amount > 0),

              CONSTRAINT contractual_billing_obligations_currency_check
                CHECK (currency = 'SAR'),

              CONSTRAINT contractual_billing_obligations_trigger_kind_check
                CHECK (
                  trigger_kind = 'fixed_date_unconditional'
                ),

              CONSTRAINT contractual_billing_obligations_reference_check
                CHECK (btrim(contractual_reference) <> ''),

              CONSTRAINT contractual_billing_obligations_membership_status_check
                CHECK (
                  draft_membership_status IN ('included','removed')
                ),

              CONSTRAINT contractual_billing_obligations_membership_lifecycle_check
                CHECK (
                  (
                    draft_membership_status = 'included'
                    AND removal_operation_id IS NULL
                    AND removed_by IS NULL
                    AND removed_at IS NULL
                    AND removal_reason IS NULL
                  )
                  OR
                  (
                    draft_membership_status = 'removed'
                    AND removal_operation_id IS NOT NULL
                    AND removed_by IS NOT NULL
                    AND removed_at IS NOT NULL
                    AND removal_reason IS NOT NULL
                    AND btrim(removal_reason) <> ''
                  )
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contractual_billing_obligations_removal_operation_unique
              ON public.contractual_billing_obligations(
                tenant_id,
                removal_operation_id
              )
              WHERE removal_operation_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_obligations_schedule_membership_index
              ON public.contractual_billing_obligations(
                tenant_id,
                schedule_id,
                draft_membership_status,
                contractual_due_date,
                id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_obligations_contract_index
              ON public.contractual_billing_obligations(
                tenant_id,
                contract_id,
                contractual_due_date,
                id
              )
            SQL);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::statement(
            'DROP TABLE IF EXISTS public.contractual_billing_obligations',
        );

        DB::statement(
            'DROP TABLE IF EXISTS public.contractual_billing_schedules',
        );
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
            'Contractual Billing Source v1 requires the public PostgreSQL schema.',
        );
    }
};
