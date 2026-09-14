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
                'Contract Consideration adoption requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE public.contract_consideration_adoptions (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,

              coordination_adoption_operation_id char(26) NOT NULL,

              consideration_amount numeric(12,2) NOT NULL,
              currency varchar(3) NOT NULL,
              adoption_basis varchar(64) NOT NULL,
              coordination_scope_version varchar(64) NOT NULL,
              status varchar(16) NOT NULL,

              adopted_by bigint NOT NULL,
              adopted_at timestamptz NOT NULL,

              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contract_consideration_adoptions_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contract_consideration_adoptions_contract_unique
                UNIQUE (tenant_id,contract_id),

              CONSTRAINT contract_consideration_adoptions_operation_unique
                UNIQUE (tenant_id,coordination_adoption_operation_id),

              CONSTRAINT contract_consideration_adoptions_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_adoptions_contract_foreign
                FOREIGN KEY (contract_id)
                REFERENCES public.contracts(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_adoptions_actor_foreign
                FOREIGN KEY (tenant_id,adopted_by)
                REFERENCES public.tenant_users(tenant_id,user_id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_adoptions_amount_check
                CHECK (consideration_amount > 0),

              CONSTRAINT contract_consideration_adoptions_currency_check
                CHECK (currency = 'SAR'),

              CONSTRAINT contract_consideration_adoptions_basis_check
                CHECK (adoption_basis = 'CLEAN_NO_PRIOR_SUPPORTED_SOURCES'),

              CONSTRAINT contract_consideration_adoptions_scope_check
                CHECK (coordination_scope_version = 'CONTRACT_CONSIDERATION_V1'),

              CONSTRAINT contract_consideration_adoptions_status_check
                CHECK (status = 'ADOPTED')
            );

            CREATE TABLE public.contract_consideration_positions (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              adoption_id char(26) NOT NULL,

              consideration_amount numeric(12,2) NOT NULL,
              currency varchar(3) NOT NULL,
              source_contract_total_snapshot numeric(12,2) NOT NULL,

              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contract_consideration_positions_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contract_consideration_positions_contract_unique
                UNIQUE (tenant_id,contract_id),

              CONSTRAINT contract_consideration_positions_adoption_unique
                UNIQUE (tenant_id,adoption_id),

              CONSTRAINT contract_consideration_positions_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_positions_contract_foreign
                FOREIGN KEY (contract_id)
                REFERENCES public.contracts(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_positions_adoption_foreign
                FOREIGN KEY (tenant_id,adoption_id)
                REFERENCES public.contract_consideration_adoptions(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_positions_amount_check
                CHECK (consideration_amount > 0),

              CONSTRAINT contract_consideration_positions_snapshot_check
                CHECK (
                  source_contract_total_snapshot = consideration_amount
                ),

              CONSTRAINT contract_consideration_positions_currency_check
                CHECK (currency = 'SAR')
            );

            CREATE TABLE public.contract_consideration_lots (
              id char(26) PRIMARY KEY,
              tenant_id char(26) NOT NULL,
              contract_id char(26) NOT NULL,
              position_root_id char(26) NOT NULL,

              lot_kind varchar(32) NOT NULL,
              amount numeric(12,2) NOT NULL,
              currency varchar(3) NOT NULL,
              semantic_position varchar(48) NOT NULL,

              created_at timestamptz NOT NULL,
              updated_at timestamptz NOT NULL,

              CONSTRAINT contract_consideration_lots_tenant_id_id_unique
                UNIQUE (tenant_id,id),

              CONSTRAINT contract_consideration_lots_position_unique
                UNIQUE (tenant_id,position_root_id),

              CONSTRAINT contract_consideration_lots_tenant_foreign
                FOREIGN KEY (tenant_id)
                REFERENCES public.tenants(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_lots_contract_foreign
                FOREIGN KEY (contract_id)
                REFERENCES public.contracts(id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_lots_position_foreign
                FOREIGN KEY (tenant_id,position_root_id)
                REFERENCES public.contract_consideration_positions(tenant_id,id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT,

              CONSTRAINT contract_consideration_lots_kind_check
                CHECK (lot_kind = 'GENESIS'),

              CONSTRAINT contract_consideration_lots_amount_check
                CHECK (amount > 0),

              CONSTRAINT contract_consideration_lots_currency_check
                CHECK (currency = 'SAR'),

              CONSTRAINT contract_consideration_lots_semantic_position_check
                CHECK (semantic_position = 'UNPERFORMED_UNBILLED')
            );

            CREATE INDEX contract_consideration_adoptions_contract_index
              ON public.contract_consideration_adoptions(tenant_id,contract_id);

            CREATE INDEX contract_consideration_positions_contract_index
              ON public.contract_consideration_positions(tenant_id,contract_id);

            CREATE INDEX contract_consideration_lots_contract_index
              ON public.contract_consideration_lots(tenant_id,contract_id);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Contract Consideration adoption requires PostgreSQL.',
            );
        }

        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        if (
            DB::table('contract_consideration_adoptions')->exists()
            || DB::table('contract_consideration_positions')->exists()
            || DB::table('contract_consideration_lots')->exists()
        ) {
            throw new RuntimeException(
                'Cannot safely roll back Contract Consideration adoption while historical coordination truth exists.',
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE public.contract_consideration_lots;
            DROP TABLE public.contract_consideration_positions;
            DROP TABLE public.contract_consideration_adoptions;
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
            'Contract Consideration adoption requires the public PostgreSQL schema.',
        );
    }
};
