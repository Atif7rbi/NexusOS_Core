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
                'Unit Handover Performance Source requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.reservations
              ADD CONSTRAINT reservations_tenant_id_id_unique
              UNIQUE (tenant_id,id);

            ALTER TABLE public.units
              ADD CONSTRAINT units_tenant_id_id_unique
              UNIQUE (tenant_id,id);
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.unit_handover_evidence (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,

              contract_id char(26) NOT NULL,
              reservation_id char(26) NOT NULL,
              unit_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,

              handover_evidence_operation_id char(26) NOT NULL,

              handover_evidence_reference varchar(64) NOT NULL,

              readiness_reference varchar(255) NOT NULL,
              readiness_effective_date date NOT NULL,

              acceptance_basis varchar(48) NOT NULL,
              customer_acceptance_reference varchar(255) NOT NULL,
              customer_acceptance_effective_date date NOT NULL,

              effective_date date NOT NULL,

              recorded_by bigint NOT NULL,
              recorded_at timestamptz NOT NULL,

              status varchar(16) NOT NULL,

              reversal_operation_id char(26),
              reversal_reason varchar(500),
              reversal_reference varchar(255),
              reversed_by bigint,
              reversed_at timestamptz,

              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT unit_handover_evidence_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT unit_handover_evidence_operation_unique
                UNIQUE (tenant_id,handover_evidence_operation_id),

              CONSTRAINT unit_handover_evidence_reference_unique
                UNIQUE (tenant_id,handover_evidence_reference),

              CONSTRAINT unit_handover_evidence_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_contract_foreign
                FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_reservation_foreign
                FOREIGN KEY (tenant_id,reservation_id)
                REFERENCES public.reservations(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_unit_foreign
                FOREIGN KEY (tenant_id,unit_id)
                REFERENCES public.units(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_customer_foreign
                FOREIGN KEY (tenant_id,customer_id)
                REFERENCES public.customers(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_recorded_actor_foreign
                FOREIGN KEY (tenant_id,recorded_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_reversed_actor_foreign
                FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_evidence_reference_canonical_check
                CHECK (
                  handover_evidence_reference =
                  upper(btrim(handover_evidence_reference))
                ),

              CONSTRAINT unit_handover_evidence_reference_format_check
                CHECK (
                  handover_evidence_reference ~
                  '^[A-Z0-9][A-Z0-9._/-]{0,63}$'
                ),

              CONSTRAINT unit_handover_evidence_readiness_reference_check
                CHECK (btrim(readiness_reference) <> ''),

              CONSTRAINT unit_handover_evidence_acceptance_basis_check
                CHECK (
                  acceptance_basis = 'explicit_customer_acceptance'
                ),

              CONSTRAINT unit_handover_evidence_customer_acceptance_reference_check
                CHECK (btrim(customer_acceptance_reference) <> ''),

              CONSTRAINT unit_handover_evidence_effective_date_check
                CHECK (
                  effective_date = customer_acceptance_effective_date
                ),

              CONSTRAINT unit_handover_evidence_evidence_order_check
                CHECK (
                  customer_acceptance_effective_date >=
                  readiness_effective_date
                ),

              CONSTRAINT unit_handover_evidence_status_check
                CHECK (
                  status IN ('effective','reversed')
                ),

              CONSTRAINT unit_handover_evidence_lifecycle_check
                CHECK (
                  (
                    status='effective'
                    AND reversal_operation_id IS NULL
                    AND reversal_reason IS NULL
                    AND reversal_reference IS NULL
                    AND reversed_by IS NULL
                    AND reversed_at IS NULL
                  )
                  OR
                  (
                    status='reversed'
                    AND reversal_operation_id IS NOT NULL
                    AND btrim(reversal_reason) <> ''
                    AND reversal_reference IS NOT NULL
                    AND btrim(reversal_reference) <> ''
                    AND reversed_by IS NOT NULL
                    AND reversed_at IS NOT NULL
                  )
                )
            );

            CREATE UNIQUE INDEX
              unit_handover_evidence_reversal_operation_unique
              ON public.unit_handover_evidence(
                tenant_id,
                reversal_operation_id
              )
              WHERE reversal_operation_id IS NOT NULL;

            CREATE INDEX unit_handover_evidence_contract_index
              ON public.unit_handover_evidence(
                tenant_id,
                contract_id
              );

            CREATE INDEX unit_handover_evidence_reservation_index
              ON public.unit_handover_evidence(
                tenant_id,
                reservation_id
              );

            CREATE INDEX unit_handover_evidence_unit_index
              ON public.unit_handover_evidence(
                tenant_id,
                unit_id
              );

            CREATE INDEX unit_handover_evidence_customer_index
              ON public.unit_handover_evidence(
                tenant_id,
                customer_id
              );
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.unit_handover_acceptances (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,

              handover_evidence_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              reservation_id char(26) NOT NULL,
              unit_id char(26) NOT NULL,
              customer_id char(26) NOT NULL,

              handover_acceptance_operation_id char(26) NOT NULL,

              performance_date date NOT NULL,
              performance_amount numeric(12,2) NOT NULL,
              currency varchar(3) NOT NULL,

              status varchar(16) NOT NULL,

              reversal_operation_id char(26),
              reversal_reason varchar(500),
              reversal_reference varchar(255),
              reversed_by bigint,
              reversed_at timestamptz,

              created_by bigint NOT NULL,
              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT unit_handover_acceptances_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT unit_handover_acceptances_operation_unique
                UNIQUE (tenant_id,handover_acceptance_operation_id),

              CONSTRAINT unit_handover_acceptances_evidence_unique
                UNIQUE (tenant_id,handover_evidence_id),

              CONSTRAINT unit_handover_acceptances_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_evidence_foreign
                FOREIGN KEY (tenant_id,handover_evidence_id)
                REFERENCES public.unit_handover_evidence(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_contract_foreign
                FOREIGN KEY (tenant_id,contract_id)
                REFERENCES public.contracts(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_reservation_foreign
                FOREIGN KEY (tenant_id,reservation_id)
                REFERENCES public.reservations(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_unit_foreign
                FOREIGN KEY (tenant_id,unit_id)
                REFERENCES public.units(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_customer_foreign
                FOREIGN KEY (tenant_id,customer_id)
                REFERENCES public.customers(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_created_actor_foreign
                FOREIGN KEY (tenant_id,created_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_reversed_actor_foreign
                FOREIGN KEY (tenant_id,reversed_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT unit_handover_acceptances_amount_check
                CHECK (performance_amount > 0),

              CONSTRAINT unit_handover_acceptances_currency_check
                CHECK (currency = 'SAR'),

              CONSTRAINT unit_handover_acceptances_status_check
                CHECK (
                  status IN ('effective','reversed')
                ),

              CONSTRAINT unit_handover_acceptances_lifecycle_check
                CHECK (
                  (
                    status='effective'
                    AND reversal_operation_id IS NULL
                    AND reversal_reason IS NULL
                    AND reversal_reference IS NULL
                    AND reversed_by IS NULL
                    AND reversed_at IS NULL
                  )
                  OR
                  (
                    status='reversed'
                    AND reversal_operation_id IS NOT NULL
                    AND btrim(reversal_reason) <> ''
                    AND reversal_reference IS NOT NULL
                    AND btrim(reversal_reference) <> ''
                    AND reversed_by IS NOT NULL
                    AND reversed_at IS NOT NULL
                  )
                )
            );

            CREATE UNIQUE INDEX
              unit_handover_acceptances_reversal_operation_unique
              ON public.unit_handover_acceptances(
                tenant_id,
                reversal_operation_id
              )
              WHERE reversal_operation_id IS NOT NULL;

            CREATE UNIQUE INDEX
              unit_handover_acceptances_effective_contract_unique
              ON public.unit_handover_acceptances(
                tenant_id,
                contract_id
              )
              WHERE status='effective';

            CREATE INDEX unit_handover_acceptances_contract_index
              ON public.unit_handover_acceptances(
                tenant_id,
                contract_id
              );

            CREATE INDEX unit_handover_acceptances_unit_index
              ON public.unit_handover_acceptances(
                tenant_id,
                unit_id
              );

            CREATE INDEX unit_handover_acceptances_customer_index
              ON public.unit_handover_acceptances(
                tenant_id,
                customer_id
              );
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Unit Handover Performance Source requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        if (
            DB::table('unit_handover_acceptances')->exists()
            || DB::table('unit_handover_evidence')->exists()
        ) {
            throw new RuntimeException(
                'Cannot safely roll back Unit Handover Performance Source '
                .'while handover history exists.',
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE public.unit_handover_acceptances;
            DROP TABLE public.unit_handover_evidence;

            ALTER TABLE public.units
              DROP CONSTRAINT units_tenant_id_id_unique;

            ALTER TABLE public.reservations
              DROP CONSTRAINT reservations_tenant_id_id_unique;
            SQL);
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
            'Unit Handover Performance Source requires the public PostgreSQL schema.',
        );
    }
};
