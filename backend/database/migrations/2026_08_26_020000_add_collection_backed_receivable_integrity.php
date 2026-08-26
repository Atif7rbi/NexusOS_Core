<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Collection-backed Receivables requires PostgreSQL.');
        }
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $incompatibleHistory = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
              SELECT 1
              FROM public.receivables receivable
              LEFT JOIN public.collections source_collection
                ON (source_collection.tenant_id,source_collection.id)=(receivable.tenant_id,receivable.collection_id)
              LEFT JOIN public.contracts source_contract
                ON (source_contract.tenant_id,source_contract.id)=(source_collection.tenant_id,source_collection.contract_id)
              LEFT JOIN public.reservations source_reservation
                ON (source_reservation.tenant_id,source_reservation.id)=(source_contract.tenant_id,source_contract.reservation_id)
              WHERE receivable.collection_id IS NOT NULL
                AND (
                  source_collection.id IS NULL
                  OR receivable.contract_id IS DISTINCT FROM source_collection.contract_id
                  OR source_reservation.customer_id IS DISTINCT FROM receivable.customer_id
                  OR source_collection.status IS DISTINCT FROM 'scheduled'
                  OR receivable.recognized_amount IS DISTINCT FROM source_collection.amount
                  OR receivable.due_date IS DISTINCT FROM source_collection.due_date
                  OR receivable.currency IS DISTINCT FROM source_contract.currency
                )
            ) AS invalid
            SQL);
        if ((bool) $incompatibleHistory->invalid) {
            throw new RuntimeException('Collection-backed Receivable migration requires existing Collection references to match authoritative scheduled Collection facts.');
        }
        $multipleEffective = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
              SELECT 1 FROM public.receivables
              WHERE collection_id IS NOT NULL AND status='recognized'
              GROUP BY tenant_id,collection_id HAVING count(*) > 1
            ) AS duplicate
            SQL);
        if ((bool) $multipleEffective->duplicate) {
            throw new RuntimeException('Collection-backed Receivable migration cannot add effective uniqueness while duplicate recognized Collection Receivables exist.');
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX receivables_one_effective_collection_unique
              ON public.receivables(tenant_id,collection_id)
              WHERE status='recognized' AND collection_id IS NOT NULL
            SQL);

        $this->replaceHistoryTrigger(true);
    }

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        $this->replaceHistoryTrigger(false);
        DB::statement('DROP INDEX IF EXISTS public.receivables_one_effective_collection_unique');
    }

    private function replaceHistoryTrigger(bool $collectionBacked): void
    {
        $collectionPredicate = $collectionBacked
            ? <<<'SQL'
                    AND NEW.contract_id=source_collection.contract_id
                    AND source_collection.status='scheduled'
                    AND NEW.recognized_amount=source_collection.amount
                    AND NEW.due_date=source_collection.due_date
                    AND NEW.currency=source_contract.currency
                SQL
            : <<<'SQL'
                    AND (NEW.contract_id IS NULL OR NEW.contract_id=source_collection.contract_id)
                SQL;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.enforce_receivable_history() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            BEGIN
              IF TG_OP='DELETE' THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='receivable deletion is forbidden';
              END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.status<>'recognized' THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable must initially be recognized';
                END IF;
                IF NEW.collection_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM public.collections source_collection
                  JOIN public.contracts source_contract ON (source_contract.tenant_id,source_contract.id)=(source_collection.tenant_id,source_collection.contract_id)
                  JOIN public.reservations source_reservation ON (source_reservation.tenant_id,source_reservation.id)=(source_contract.tenant_id,source_contract.reservation_id)
                  WHERE (source_collection.tenant_id,source_collection.id)=(NEW.tenant_id,NEW.collection_id)
                    AND source_reservation.customer_id=NEW.customer_id
                    {$collectionPredicate}
                  FOR KEY SHARE OF source_collection,source_contract,source_reservation
                ) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable collection provenance is inconsistent';
                END IF;
                IF NEW.collection_id IS NULL AND NEW.contract_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM public.contracts source_contract
                  JOIN public.reservations source_reservation ON (source_reservation.tenant_id,source_reservation.id)=(source_contract.tenant_id,source_contract.reservation_id)
                  WHERE (source_contract.tenant_id,source_contract.id)=(NEW.tenant_id,NEW.contract_id)
                    AND source_reservation.customer_id=NEW.customer_id
                  FOR KEY SHARE OF source_contract,source_reservation
                ) THEN
                  RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable contract provenance is inconsistent';
                END IF;
                RETURN NEW;
              END IF;
              IF (NEW.id,NEW.tenant_id,NEW.recognition_operation_id,NEW.customer_id,NEW.contract_id,NEW.collection_id,NEW.currency,NEW.recognized_amount,NEW.due_date,NEW.recognized_at,NEW.recognized_by,NEW.created_at)
                 IS DISTINCT FROM
                 (OLD.id,OLD.tenant_id,OLD.recognition_operation_id,OLD.customer_id,OLD.contract_id,OLD.collection_id,OLD.currency,OLD.recognized_amount,OLD.due_date,OLD.recognized_at,OLD.recognized_by,OLD.created_at) THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='recognized receivable truth is immutable';
              END IF;
              IF NOT (OLD.status='recognized' AND NEW.status='cancelled') THEN
                RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported receivable lifecycle mutation';
              END IF;
              RETURN NEW;
            END;
            $$;
            REVOKE EXECUTE ON FUNCTION public.enforce_receivable_history() FROM PUBLIC;
            SQL);
    }

    private function skipIsolatedTestSchema(): bool
    {
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        if ($schema === 'public') {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }

        throw new RuntimeException('Collection-backed Receivables requires the public PostgreSQL schema.');
    }
};
