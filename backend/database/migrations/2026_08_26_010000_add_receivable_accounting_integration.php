<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivable Accounting integration requires PostgreSQL.');
        }
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.receivables ADD COLUMN recognition_operation_id char(26);
            UPDATE public.receivables SET recognition_operation_id=id WHERE recognition_operation_id IS NULL;
            ALTER TABLE public.receivables ALTER COLUMN recognition_operation_id SET NOT NULL;
            ALTER TABLE public.receivables ADD CONSTRAINT receivables_recognition_operation_format_check
              CHECK (recognition_operation_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$');
            ALTER TABLE public.receivables ADD CONSTRAINT receivables_tenant_recognition_operation_unique
              UNIQUE (tenant_id,recognition_operation_id);
            INSERT INTO public.accounting_source_types(origin,key,owner_module,description)
              VALUES('business','receivable_recognition','receivables','Atomic Receivable recognition accounting effect');
            SQL);

        DB::unprepared(<<<'SQL'
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
                    AND (NEW.contract_id IS NULL OR NEW.contract_id=source_collection.contract_id)
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

    public function down(): void
    {
        if ($this->skipIsolatedTestSchema()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.accounting_source_types DISABLE TRIGGER accounting_source_types_immutable_delete;
            DELETE FROM public.accounting_source_types WHERE origin='business' AND key='receivable_recognition';
            ALTER TABLE public.accounting_source_types ENABLE TRIGGER accounting_source_types_immutable_delete;
            ALTER TABLE public.receivables DROP CONSTRAINT receivables_tenant_recognition_operation_unique;
            ALTER TABLE public.receivables DROP CONSTRAINT receivables_recognition_operation_format_check;
            ALTER TABLE public.receivables DROP COLUMN recognition_operation_id;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_receivable_history() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='receivable deletion is forbidden'; END IF;
              IF TG_OP='INSERT' THEN
                IF NEW.status<>'recognized' THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable must initially be recognized'; END IF;
                IF NEW.collection_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM public.collections source_collection
                  JOIN public.contracts source_contract ON (source_contract.tenant_id,source_contract.id)=(source_collection.tenant_id,source_collection.contract_id)
                  JOIN public.reservations source_reservation ON (source_reservation.tenant_id,source_reservation.id)=(source_contract.tenant_id,source_contract.reservation_id)
                  WHERE (source_collection.tenant_id,source_collection.id)=(NEW.tenant_id,NEW.collection_id)
                    AND source_reservation.customer_id=NEW.customer_id
                    AND (NEW.contract_id IS NULL OR NEW.contract_id=source_collection.contract_id)
                  FOR KEY SHARE OF source_collection,source_contract,source_reservation
                ) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable collection provenance is inconsistent'; END IF;
                IF NEW.collection_id IS NULL AND NEW.contract_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM public.contracts source_contract
                  JOIN public.reservations source_reservation ON (source_reservation.tenant_id,source_reservation.id)=(source_contract.tenant_id,source_contract.reservation_id)
                  WHERE (source_contract.tenant_id,source_contract.id)=(NEW.tenant_id,NEW.contract_id)
                    AND source_reservation.customer_id=NEW.customer_id
                  FOR KEY SHARE OF source_contract,source_reservation
                ) THEN RAISE EXCEPTION USING ERRCODE='23514',MESSAGE='receivable contract provenance is inconsistent'; END IF;
                RETURN NEW;
              END IF;
              IF (NEW.id,NEW.tenant_id,NEW.customer_id,NEW.contract_id,NEW.collection_id,NEW.currency,NEW.recognized_amount,NEW.due_date,NEW.recognized_at,NEW.recognized_by,NEW.created_at)
                 IS DISTINCT FROM
                 (OLD.id,OLD.tenant_id,OLD.customer_id,OLD.contract_id,OLD.collection_id,OLD.currency,OLD.recognized_amount,OLD.due_date,OLD.recognized_at,OLD.recognized_by,OLD.created_at)
              THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='recognized receivable truth is immutable'; END IF;
              IF NOT (OLD.status='recognized' AND NEW.status='cancelled') THEN RAISE EXCEPTION USING ERRCODE='55000',MESSAGE='unsupported receivable lifecycle mutation'; END IF;
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

        throw new RuntimeException('Receivable Accounting integration requires the public PostgreSQL schema.');
    }
};
