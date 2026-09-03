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
                'Contractual Billing Entitlements v1 requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE public.contractual_billing_entitlements (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,

              billing_entitlement_operation_id char(26) NOT NULL,

              schedule_id char(26) NOT NULL,
              obligation_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,

              amount numeric(19,2) NOT NULL,
              currency char(3) NOT NULL,
              economic_date date NOT NULL,
              effective_at timestamptz NOT NULL,

              status varchar(16) NOT NULL,

              recognized_by bigint NOT NULL,
              recognized_at timestamptz NOT NULL,

              reversal_operation_id char(26),
              reversed_by bigint,
              reversed_at timestamptz,
              reversal_reason varchar(500),

              source_correction_operation_id char(26),
              source_rescission_reference varchar(500),

              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contractual_billing_entitlements_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contractual_billing_entitlements_operation_unique
                UNIQUE (
                  tenant_id,
                  billing_entitlement_operation_id
                ),

              CONSTRAINT contractual_billing_entitlements_obligation_history_unique
                UNIQUE (tenant_id,obligation_id),

              CONSTRAINT contractual_billing_entitlements_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_schedule_foreign
                FOREIGN KEY (tenant_id,schedule_id)
                REFERENCES public.contractual_billing_schedules(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_obligation_foreign
                FOREIGN KEY (tenant_id,obligation_id)
                REFERENCES public.contractual_billing_obligations(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_contract_foreign
                FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_customer_foreign
                FOREIGN KEY (tenant_id,customer_id)
                REFERENCES public.customers(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_recognized_actor_foreign
                FOREIGN KEY (tenant_id,recognized_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_reversed_actor_foreign
                FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contractual_billing_entitlements_operation_ulid_check
                CHECK (
                  billing_entitlement_operation_id
                    ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_entitlements_reversal_operation_ulid_check
                CHECK (
                  reversal_operation_id IS NULL
                  OR reversal_operation_id
                    ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT cbe_source_correction_operation_ulid_check
                CHECK (
                  source_correction_operation_id IS NULL
                  OR source_correction_operation_id
                    ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                ),

              CONSTRAINT contractual_billing_entitlements_amount_positive_check
                CHECK (amount > 0),

              CONSTRAINT contractual_billing_entitlements_currency_check
                CHECK (currency = 'SAR'),

              CONSTRAINT contractual_billing_entitlements_status_check
                CHECK (status IN ('effective','reversed')),

              CONSTRAINT contractual_billing_entitlements_lifecycle_check
                CHECK (
                  (
                    status = 'effective'
                    AND reversal_operation_id IS NULL
                    AND reversed_by IS NULL
                    AND reversed_at IS NULL
                    AND reversal_reason IS NULL
                    AND source_correction_operation_id IS NULL
                    AND source_rescission_reference IS NULL
                  )
                  OR
                  (
                    status = 'reversed'
                    AND reversal_operation_id IS NOT NULL
                    AND reversed_by IS NOT NULL
                    AND reversed_at IS NOT NULL
                    AND reversed_at >= recognized_at
                    AND reversal_reason IS NOT NULL
                    AND btrim(reversal_reason) <> ''
                    AND source_correction_operation_id IS NOT NULL
                    AND source_rescission_reference IS NOT NULL
                    AND btrim(source_rescission_reference) <> ''
                  )
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX contractual_billing_entitlements_reversal_operation_unique
              ON public.contractual_billing_entitlements(
                tenant_id,
                reversal_operation_id
              )
              WHERE reversal_operation_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_entitlements_schedule_status_index
              ON public.contractual_billing_entitlements(
                tenant_id,
                schedule_id,
                status,
                economic_date,
                id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_entitlements_contract_status_index
              ON public.contractual_billing_entitlements(
                tenant_id,
                contract_id,
                status,
                economic_date,
                id
              )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contractual_billing_entitlements_source_correction_index
              ON public.contractual_billing_entitlements(
                tenant_id,
                source_correction_operation_id
              )
              WHERE source_correction_operation_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::statement(
            'DROP TABLE IF EXISTS public.contractual_billing_entitlements',
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
            'Contractual Billing Entitlements v1 requires the public PostgreSQL schema.',
        );
    }
};
