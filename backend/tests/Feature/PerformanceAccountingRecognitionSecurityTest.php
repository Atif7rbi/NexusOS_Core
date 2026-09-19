<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class PerformanceAccountingRecognitionSecurityTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_runtime_role_cannot_create_receivable_ar_provenance_during_slice_two(): void
    {
        $caught = null;

        try {
            $this->asRuntimeRole(function (): void {
                DB::table('accounting_position_origins')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => (string) Str::ulid(),
                    'contract_id' => (string) Str::ulid(),
                    'position_type' => 'CONTRACT_LIABILITY',
                    'origin_recognition_type' => 'RECEIVABLE_AR_RECOGNITION',
                    'origin_recognition_id' => (string) Str::ulid(),
                    'origin_journal_entry_id' => (string) Str::ulid(),
                    'account_id' => (string) Str::ulid(),
                    'economic_source_type' => 'CONTRACTUAL_BILLING_ENTITLEMENT',
                    'economic_source_id' => (string) Str::ulid(),
                    'consideration_transition_id' => (string) Str::ulid(),
                    'consideration_lot_id' => (string) Str::ulid(),
                    'economic_leg_identity' => 'forged-ar-origin',
                    'origin_amount' => '1.00',
                    'currency' => 'SAR',
                    'accounting_date' => '2026-08-20',
                    'status' => 'effective',
                    'created_at' => now(),
                    'reversal_origin_operation_id' => null,
                    'reversed_at' => null,
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame('42501', (string) ($caught->errorInfo[0] ?? ''));
    }

    public function test_runtime_role_cannot_create_performance_provenance_without_exact_recognition_owner(): void
    {
        $context = $this->considerationContext();
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

        $assetAccount = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-SEC-A',
                'name' => 'Performance Security Asset',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'asset',
                'classification' => 'current_asset',
                'parent_id' => null,
            ],
        );
        $revenueAccount = app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => 'PA-SEC-R',
                'name' => 'Performance Security Revenue',
                'description' => null,
                'kind' => 'posting',
                'account_type' => 'revenue',
                'classification' => 'operating_revenue',
                'parent_id' => null,
            ],
        );

        [$source, $graph] = DB::transaction(function () use (
            $context,
            $consideration,
        ): array {
            $source = $this->handoverSource($context);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'EARNED_UNBILLED',
            );

            return [$source, $graph];
        });

        $journal = DB::transaction(
            fn () => app(BusinessPostingServiceInterface::class)->post(
                new BusinessPostingRequest(
                    $context['tenant_id'],
                    $context['actor']->id,
                    'receivable_recognition',
                    (string) Str::ulid(),
                    'SAR',
                    '2026-08-20',
                    'Performance orphan provenance security fixture',
                    [
                        new JournalLineData(
                            $assetAccount,
                            '1000.00',
                            '0.00',
                        ),
                        new JournalLineData(
                            $revenueAccount,
                            '0.00',
                            '1000.00',
                        ),
                    ],
                ),
            ),
        );

        $assetLine = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $journal->journalEntryId)
            ->where('account_id', $assetAccount)
            ->first();

        self::assertNotNull($assetLine);

        $fakeRecognitionId = (string) Str::ulid();
        $originId = (string) Str::ulid();
        $leg = 'PA:ORIGIN:'.$graph['transition_id'].':'.$graph['lot_id'];
        $caught = null;

        try {
            $this->asRuntimeRole(function () use (
                $context,
                $source,
                $graph,
                $journal,
                $assetLine,
                $assetAccount,
                $fakeRecognitionId,
                $originId,
                $leg,
            ): void {
                DB::table('accounting_position_origins')->insert([
                    'id' => $originId,
                    'tenant_id' => $context['tenant_id'],
                    'contract_id' => $context['contract_id'],
                    'position_type' => 'CONTRACT_ASSET',
                    'origin_recognition_type' => 'PERFORMANCE_ACCOUNTING_RECOGNITION',
                    'origin_recognition_id' => $fakeRecognitionId,
                    'origin_journal_entry_id' => $journal->journalEntryId,
                    'account_id' => $assetAccount,
                    'economic_source_type' => 'UNIT_HANDOVER_ACCEPTANCE',
                    'economic_source_id' => $source['id'],
                    'consideration_transition_id' => $graph['transition_id'],
                    'consideration_lot_id' => $graph['lot_id'],
                    'economic_leg_identity' => $leg,
                    'origin_amount' => '1000.00',
                    'currency' => 'SAR',
                    'accounting_date' => '2026-08-20',
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
                    'origin_id' => $originId,
                    'journal_entry_id' => $journal->journalEntryId,
                    'journal_line_id' => $assetLine->id,
                    'amount' => '1000.00',
                    'currency' => 'SAR',
                    'economic_leg_identity' => $leg,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryException::class, $caught);
        self::assertSame('23503', (string) ($caught->errorInfo[0] ?? ''));

        self::assertDatabaseMissing('accounting_position_origins', [
            'id' => $originId,
        ]);
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
