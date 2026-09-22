<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ReceivableArRecognitionSecurityTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_runtime_role_cannot_forge_receivable_ar_provenance_without_exact_owner(): void
    {
        [$context, $recognition, $origin] = $this->recognizedContext();
        $allocation = DB::table(
            'accounting_position_origin_journal_line_allocations',
        )
            ->where('tenant_id', $context['tenant_id'])
            ->where('origin_id', $origin->id)
            ->first();

        self::assertNotNull($allocation);

        $fakeRecognitionId = (string) Str::ulid();
        $fakeOriginId = (string) Str::ulid();
        $caught = null;

        try {
            $this->asRuntimeRole(function () use (
                $context,
                $origin,
                $allocation,
                $fakeRecognitionId,
                $fakeOriginId,
            ): void {
                DB::table('accounting_position_origins')->insert([
                    'id' => $fakeOriginId,
                    'tenant_id' => $context['tenant_id'],
                    'contract_id' => $context['contract_id'],
                    'position_type' => $origin->position_type,
                    'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
                    'origin_recognition_id' => $fakeRecognitionId,
                    'origin_journal_entry_id' => $origin->origin_journal_entry_id,
                    'account_id' => $origin->account_id,
                    'economic_source_type' => $origin->economic_source_type,
                    'economic_source_id' => $origin->economic_source_id,
                    'consideration_transition_id' => $origin->consideration_transition_id,
                    'consideration_lot_id' => $origin->consideration_lot_id,
                    'economic_leg_identity' => 'AR:FORGED:'.$fakeRecognitionId,
                    'origin_amount' => $origin->origin_amount,
                    'currency' => 'SAR',
                    'accounting_date' => $origin->accounting_date,
                    'status' => 'effective',
                    'created_at' => now(),
                    'reversal_origin_operation_id' => null,
                    'reversed_at' => null,
                ]);

                DB::table(
                    'accounting_position_origin_journal_line_allocations',
                )->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $context['tenant_id'],
                    'contract_id' => $context['contract_id'],
                    'origin_id' => $fakeOriginId,
                    'journal_entry_id' => $allocation->journal_entry_id,
                    'journal_line_id' => $allocation->journal_line_id,
                    'amount' => $origin->origin_amount,
                    'currency' => 'SAR',
                    'economic_leg_identity' => 'AR:FORGED:'.$fakeRecognitionId,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($recognition);
        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame(
            '23503',
            (string) ($caught->errorInfo[0] ?? ''),
            $caught->getMessage(),
        );
    }

    public function test_runtime_role_cannot_mutate_canonical_receivable_ar_history(): void
    {
        [$context, $recognition] = $this->recognizedContext();
        $caught = null;

        try {
            $this->asRuntimeRole(function () use (
                $context,
                $recognition,
            ): void {
                DB::table('receivable_ar_recognitions')
                    ->where('tenant_id', $context['tenant_id'])
                    ->where('id', $recognition->id)
                    ->update([
                        'receivable_amount' => '999.00',
                    ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame(
            '55000',
            (string) ($caught->errorInfo[0] ?? ''),
            $caught->getMessage(),
        );

        self::assertSame(
            '1000.00',
            DB::table('receivable_ar_recognitions')
                ->where('id', $recognition->id)
                ->value('receivable_amount'),
        );
    }

    public function test_direct_sql_exact_reversal_cannot_commit_while_recognition_remains_posted(): void
    {
        [$context, $recognition] = $this->recognizedContext();

        $this->assertDirectSqlRejected(function () use (
            $context,
            $recognition,
        ): void {
            $this->createDirectReversalJournal(
                $context,
                $recognition,
                'Forged direct reversal',
            );
        });
    }

    public function test_direct_sql_standalone_recognition_reversal_cannot_commit_without_source_reversal(): void
    {
        [$context, $recognition, $origin] = $this->recognizedContext();

        $this->assertDirectSqlRejected(function () use (
            $context,
            $recognition,
            $origin,
        ): void {
            $operationId = (string) Str::ulid();
            $reversedAt = now();
            $reversalJournalId = $this->createDirectReversalJournal(
                $context,
                $recognition,
                'Standalone AR reversal',
            );

            DB::table('accounting_position_origins')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $origin->id)
                ->update([
                    'status' => 'reversed',
                    'reversal_origin_operation_id' => $operationId,
                    'reversed_at' => $reversedAt,
                ]);

            DB::table('receivable_ar_recognitions')
                ->where('tenant_id', $context['tenant_id'])
                ->where('id', $recognition->id)
                ->update([
                    'status' => 'reversed',
                    'reversal_operation_id' => $operationId,
                    'reversal_journal_entry_id' => $reversalJournalId,
                    'reversed_by' => $context['actor']->id,
                    'reversed_at' => $reversedAt,
                ]);
        });
    }

    private function recognizedContext(): array
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);

        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );

        app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $ar = $this->account(
            $context,
            'AR-SEC-CTRL',
            'asset',
            'current_asset',
        );
        $asset = $this->account(
            $context,
            'AR-SEC-ASSET',
            'asset',
            'current_asset',
        );
        $liability = $this->account(
            $context,
            'AR-SEC-LIAB',
            'liability',
            'current_liability',
        );

        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $ar,
        );

        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $asset,
            $liability,
        );

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);

            $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source];
        });

        app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            [
                'receivable_establishment_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contractual_billing_entitlement_id' => $source['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        $recognition = DB::table('receivable_ar_recognitions')
            ->where('id', $recognitionId)
            ->first();
        $origin = DB::table('accounting_position_origins')
            ->where(
                'origin_recognition_type',
                'RECEIVABLE_AR_RECOGNITION',
            )
            ->where('origin_recognition_id', $recognitionId)
            ->first();

        self::assertNotNull($recognition);
        self::assertNotNull($origin);

        return [$context, $recognition, $origin];
    }

    private function assertDirectSqlRejected(callable $callback): void
    {
        DB::beginTransaction();

        try {
            $callback();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail(
                'PostgreSQL accepted inconsistent Receivable AR final state.',
            );
        } catch (QueryException $exception) {
            self::assertContains(
                (string) ($exception->errorInfo[0] ?? ''),
                ['23514', '23503', '55000'],
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function createDirectReversalJournal(
        array $context,
        object $recognition,
        string $reason,
    ): string {
        $target = DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $recognition->journal_entry_id)
            ->first();

        self::assertNotNull($target);

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $target->id)
            ->orderBy('line_number')
            ->get();

        self::assertGreaterThanOrEqual(2, $lines->count());

        $id = (string) Str::ulid();
        $at = now();

        DB::table('journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'entry_date' => $target->entry_date,
            'description' => 'Direct SQL reversal fixture',
            'status' => 'draft',
            'origin' => 'reversal',
            'source_type' => 'journal_entry',
            'source_id' => $target->id,
            'created_by' => $context['actor']->id,
            'updated_by' => $context['actor']->id,
            'created_at' => $at,
            'updated_at' => $at,
            'reverses_journal_entry_id' => $target->id,
            'reversal_reason' => $reason,
        ]);

        foreach ($lines as $line) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $context['tenant_id'],
                'journal_entry_id' => $id,
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => $line->memo,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $sequence = ((int) DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_number_year', 2026)
            ->max('journal_sequence_number')) + 1;
        $number = 'JRN-2026-'.str_pad(
            (string) $sequence,
            max(3, strlen((string) $sequence)),
            '0',
            STR_PAD_LEFT,
        );

        DB::table('journal_entries')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $id)
            ->update([
                'status' => 'posted',
                'accounting_period_id' => $target->accounting_period_id,
                'journal_number' => $number,
                'journal_number_year' => 2026,
                'journal_sequence_number' => $sequence,
                'posted_by' => $context['actor']->id,
                'posted_at' => $at,
                'updated_by' => $context['actor']->id,
                'updated_at' => $at,
            ]);

        return $id;
    }

    private function account(
        array $context,
        string $code,
        string $type,
        string $classification,
    ): string {
        return app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => $code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );
    }

    private function asRuntimeRole(callable $callback): mixed
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new \RuntimeException(
                'Invalid ACCOUNTING_RUNTIME_DB_ROLE.',
            );
        }

        $identifier = '"'.str_replace('"', '""', $role).'"';

        return DB::transaction(function () use (
            $identifier,
            $callback,
        ): mixed {
            DB::statement("SET LOCAL ROLE {$identifier}");

            $result = $callback();

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            return $result;
        });
    }
}
