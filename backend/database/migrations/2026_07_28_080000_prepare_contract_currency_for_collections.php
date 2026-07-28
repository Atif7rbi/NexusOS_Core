<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUPPORTED_CURRENCIES = ['SAR', 'USD'];

    public function up(): void
    {
        $hasUnsupportedTenantCurrency = DB::table('tenants')
            ->whereNull('currency')
            ->orWhereNotIn('currency', self::SUPPORTED_CURRENCIES)
            ->exists();

        if ($hasUnsupportedTenantCurrency) {
            throw new RuntimeException(
                'Cannot add the tenant currency constraint while unsupported '
                .'or NULL tenant currencies exist.'
            );
        }

        DB::statement(
            "ALTER TABLE tenants
             ADD CONSTRAINT tenants_currency_check
             CHECK (currency IN ('SAR', 'USD'))"
        );

        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable();
        });

        DB::table('contracts')
            ->whereNull('currency')
            ->update(['currency' => 'SAR']);

        if (DB::table('contracts')->whereNull('currency')->exists()) {
            throw new RuntimeException(
                'Cannot enforce contracts.currency NOT NULL while NULL values exist.'
            );
        }

        DB::statement('ALTER TABLE contracts ALTER COLUMN currency SET NOT NULL');

        DB::statement(
            "ALTER TABLE contracts
             ADD CONSTRAINT contracts_currency_check
             CHECK (currency IN ('SAR', 'USD'))"
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_contract_currency_change()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION
                        'contracts.currency is immutable after contract creation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER contracts_currency_immutable
            BEFORE UPDATE OF currency
            ON contracts
            FOR EACH ROW
            EXECUTE FUNCTION prevent_contract_currency_change();
        SQL);

        DB::statement(
            'ALTER TABLE contracts
             ADD CONSTRAINT contracts_tenant_id_id_unique
             UNIQUE (tenant_id, id)'
        );
    }

    public function down(): void
    {
        if (DB::table('contracts')->exists()) {
            throw new RuntimeException(
                'Cannot safely roll back contract currency preparation while '
                .'contracts exist. Use a forward-fix migration instead.'
            );
        }

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_tenant_id_id_unique');
        DB::statement('DROP TRIGGER IF EXISTS contracts_currency_immutable ON contracts');
        DB::statement('DROP FUNCTION IF EXISTS prevent_contract_currency_change()');
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_currency_check');

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });

        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_currency_check');
    }
};
