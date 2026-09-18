<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\AccountingRecognition\Actions\AdoptPerformanceAccounting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class AccountingRecognitionProvenanceIntegrityTest extends TestCase
{
    use CreatesContractConsiderationFixtures;
    use RefreshDatabase;

    public function test_contract_asset_origin_is_bound_to_exact_economic_leg_and_debit_line(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $accounts = $this->accountingContext($context);

        [$source, $graph] = DB::transaction(function () use ($context, $consideration): array {
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

        $journal = $this->post(
            $context,
            '2026-08-20',
            $accounts['contract_asset'],
            $accounts['revenue'],
            '1000.00',
        );

        $originId = $this->insertOrigin(
            $context,
            $source,
            $graph,
            $journal,
            $accounts['contract_asset'],
            'CONTRACT_ASSET',
            'PERFORMANCE_ACCOUNTING_RECOGNITION',
            '1000.00',
            'PERFORMANCE:EARNED_UNBILLED',
        );

        self::assertDatabaseHas('accounting_position_origins', [
            'id' => $originId,
            'status' => 'effective',
            'origin_amount' => '1000.00',
        ]);

        $wrongJournal = $this->post(
            $context,
            '2026-08-20',
            $accounts['other_asset'],
            $accounts['contract_asset'],
            '1000.00',
        );

        $this->assertSqlRejected(
            fn () => $this->insertOriginRows(
                $context,
                $source,
                $graph,
                $wrongJournal,
                $accounts['contract_asset'],
                'CONTRACT_ASSET',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
                '1000.00',
                'PERFORMANCE:EARNED_UNBILLED',
                false,
            ),
        );
    }

    public function test_controlled_origin_line_must_be_fully_explained_by_exact_allocations(): void
    {
        $context = $this->considerationContext();
        $obligations = $this->billingObligations(
            $context,
            ['600.00', '400.00'],
            '2026-08-19',
        );
        $consideration = $this->adopt($context);
        $accounts = $this->accountingContext($context);

        [$source, $graph] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligations,
        ): array {
            $source = $this->billingSource($context, $obligations[0]);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source, $graph];
        });

        $journal = $this->post(
            $context,
            '2026-08-19',
            $accounts['ar'],
            $accounts['contract_liability'],
            '1000.00',
        );

        $this->assertSqlRejected(
            fn () => $this->insertOriginRows(
                $context,
                $source,
                $graph,
                $journal,
                $accounts['contract_liability'],
                'CONTRACT_LIABILITY',
                'RECEIVABLE_AR_RECOGNITION',
                '600.00',
                'BILLING:BILLED_UNEARNED',
                true,
            ),
        );
    }

    public function test_exact_protocol_liability_origin_allows_clean_adoption(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-19',
        )[0];
        $consideration = $this->adopt($context);
        $accounts = $this->accountingContext($context);

        [$source, $graph] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source, $graph];
        });

        $journal = $this->post(
            $context,
            '2026-08-19',
            $accounts['ar'],
            $accounts['contract_liability'],
            '1000.00',
        );

        $originId = $this->insertOrigin(
            $context,
            $source,
            $graph,
            $journal,
            $accounts['contract_liability'],
            'CONTRACT_LIABILITY',
            'RECEIVABLE_AR_RECOGNITION',
            '1000.00',
            'BILLING:BILLED_UNEARNED',
        );

        $adoption = app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        self::assertDatabaseHas('performance_accounting_adoptions', [
            'id' => $adoption['adoption_id'],
            'contract_id' => $context['contract_id'],
            'adoption_basis' => 'CLEAN_PROTOCOL_READY',
        ]);
        self::assertDatabaseHas('accounting_position_origins', [
            'id' => $originId,
            'position_type' => 'CONTRACT_LIABILITY',
        ]);
    }

    public function test_consumption_uses_exact_predecessor_account_direction_and_is_capacity_safe(): void
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-19',
        )[0];
        $consideration = $this->adopt($context);
        $accounts = $this->accountingContext($context);

        [$billingSource, $billingGraph] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source, $graph];
        });

        $billingJournal = $this->post(
            $context,
            '2026-08-19',
            $accounts['ar'],
            $accounts['contract_liability'],
            '1000.00',
        );
        $originId = $this->insertOrigin(
            $context,
            $billingSource,
            $billingGraph,
            $billingJournal,
            $accounts['contract_liability'],
            'CONTRACT_LIABILITY',
            'RECEIVABLE_AR_RECOGNITION',
            '1000.00',
            'BILLING:BILLED_UNEARNED',
        );

        app(AdoptPerformanceAccounting::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contract_id' => $context['contract_id'],
                'performance_accounting_adoption_operation_id' => (string) Str::ulid(),
            ],
        );

        [$handoverSource, $handoverGraph] = DB::transaction(function () use (
            $context,
            $consideration,
            $billingGraph,
        ): array {
            $source = $this->handoverSource($context);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $billingGraph['lot_id'],
                'BILLED_EARNED',
            );

            return [$source, $graph];
        });

        $performanceJournal = $this->post(
            $context,
            '2026-08-20',
            $accounts['contract_liability'],
            $accounts['revenue'],
            '600.00',
        );

        $consumptionId = $this->insertConsumption(
            $context,
            $originId,
            $handoverGraph,
            $performanceJournal,
            '600.00',
            'PERFORMANCE:BILLED_EARNED',
        );

        self::assertDatabaseHas('accounting_position_consumptions', [
            'id' => $consumptionId,
            'origin_id' => $originId,
            'amount' => '600.00',
            'status' => 'effective',
        ]);

        $secondJournal = $this->post(
            $context,
            '2026-08-20',
            $accounts['contract_liability'],
            $accounts['revenue'],
            '600.00',
        );

        $this->assertSqlRejected(
            fn () => $this->insertConsumptionRows(
                $context,
                $originId,
                $handoverGraph,
                $secondJournal,
                '600.00',
                'PERFORMANCE:BILLED_EARNED',
            ),
        );

        $this->assertSqlRejected(function () use ($originId): void {
            DB::table('accounting_position_origins')
                ->where('id', $originId)
                ->update([
                    'status' => 'reversed',
                    'reversal_origin_operation_id' => (string) Str::ulid(),
                    'reversed_at' => now(),
                ]);
        });
    }

    public function test_allocation_economic_leg_identity_must_match_its_owner(): void
    {
        $context = $this->considerationContext();
        $consideration = $this->adopt($context);
        $accounts = $this->accountingContext($context);

        [$source, $graph] = DB::transaction(function () use ($context, $consideration): array {
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

        $journal = $this->post(
            $context,
            '2026-08-20',
            $accounts['contract_asset'],
            $accounts['revenue'],
            '1000.00',
        );

        $this->assertSqlRejected(
            fn () => $this->insertOriginRows(
                $context,
                $source,
                $graph,
                $journal,
                $accounts['contract_asset'],
                'CONTRACT_ASSET',
                'PERFORMANCE_ACCOUNTING_RECOGNITION',
                '1000.00',
                'WRONG-ALLOCATION-LEG',
                true,
                'PERFORMANCE:EARNED_UNBILLED',
            ),
        );
    }

    private function accountingContext(array $context): array
    {
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

        $create = fn (
            string $code,
            string $type,
            string $classification,
        ): string => app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => 'Recognition '.$code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );

        return [
            'ar' => $create('AR200', 'asset', 'current_asset'),
            'other_asset' => $create('OA200', 'asset', 'current_asset'),
            'contract_asset' => $create('CA200', 'asset', 'current_asset'),
            'contract_liability' => $create(
                'CL200',
                'liability',
                'current_liability',
            ),
            'revenue' => $create('REV200', 'revenue', 'operating_revenue'),
        ];
    }

    private function post(
        array $context,
        string $date,
        string $debitAccountId,
        string $creditAccountId,
        string $amount,
    ): array {
        $result = DB::transaction(
            fn () => app(BusinessPostingServiceInterface::class)->post(
                new BusinessPostingRequest(
                    $context['tenant_id'],
                    $context['actor']->id,
                    'receivable_recognition',
                    (string) Str::ulid(),
                    'SAR',
                    $date,
                    'Accounting Recognition provenance fixture',
                    [
                        new JournalLineData($debitAccountId, $amount, '0'),
                        new JournalLineData($creditAccountId, '0', $amount),
                    ],
                ),
            ),
        );

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $context['tenant_id'])
            ->where('journal_entry_id', $result->journalEntryId)
            ->orderBy('line_number')
            ->get()
            ->keyBy('account_id');

        return [
            'id' => $result->journalEntryId,
            'date' => $date,
            'lines' => $lines,
        ];
    }

    private function insertOrigin(
        array $context,
        array $source,
        array $graph,
        array $journal,
        string $accountId,
        string $positionType,
        string $recognitionType,
        string $amount,
        string $legIdentity,
    ): string {
        return DB::transaction(
            fn (): string => $this->insertOriginRows(
                $context,
                $source,
                $graph,
                $journal,
                $accountId,
                $positionType,
                $recognitionType,
                $amount,
                $legIdentity,
                true,
            ),
        );
    }

    private function insertOriginRows(
        array $context,
        array $source,
        array $graph,
        array $journal,
        string $accountId,
        string $positionType,
        string $recognitionType,
        string $amount,
        string $allocationLegIdentity,
        bool $useAccountLine,
        ?string $originLegIdentity = null,
    ): string {
        $originLegIdentity ??= $allocationLegIdentity;
        $originId = (string) Str::ulid();
        $recognitionId = (string) Str::ulid();

        DB::table('accounting_position_origins')->insert([
            'id' => $originId,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'position_type' => $positionType,
            'origin_recognition_type' => $recognitionType,
            'origin_recognition_id' => $recognitionId,
            'origin_journal_entry_id' => $journal['id'],
            'account_id' => $accountId,
            'economic_source_type' => $source['source_type'],
            'economic_source_id' => $source['id'],
            'consideration_transition_id' => $graph['transition_id'],
            'consideration_lot_id' => $graph['lot_id'],
            'economic_leg_identity' => $originLegIdentity,
            'origin_amount' => $amount,
            'currency' => 'SAR',
            'accounting_date' => $journal['date'],
            'status' => 'effective',
            'created_at' => now(),
            'reversal_origin_operation_id' => null,
            'reversed_at' => null,
        ]);

        $line = $useAccountLine
            ? $journal['lines']->get($accountId)
            : $journal['lines']->first();

        if ($line === null) {
            throw new \LogicException('Missing Journal line fixture.');
        }

        DB::table('accounting_position_origin_journal_line_allocations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'origin_id' => $originId,
            'journal_entry_id' => $journal['id'],
            'journal_line_id' => $line->id,
            'amount' => $amount,
            'currency' => 'SAR',
            'economic_leg_identity' => $allocationLegIdentity,
            'created_at' => now(),
        ]);

        return $originId;
    }

    private function insertConsumption(
        array $context,
        string $originId,
        array $graph,
        array $journal,
        string $amount,
        string $legIdentity,
    ): string {
        return DB::transaction(
            fn (): string => $this->insertConsumptionRows(
                $context,
                $originId,
                $graph,
                $journal,
                $amount,
                $legIdentity,
            ),
        );
    }

    private function insertConsumptionRows(
        array $context,
        string $originId,
        array $graph,
        array $journal,
        string $amount,
        string $legIdentity,
    ): string {
        $origin = DB::table('accounting_position_origins')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $originId)
            ->first();

        if ($origin === null) {
            throw new \LogicException('Missing Accounting Position Origin fixture.');
        }

        $id = (string) Str::ulid();

        DB::table('accounting_position_consumptions')->insert([
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'origin_id' => $originId,
            'consuming_recognition_type' => $origin->position_type === 'CONTRACT_ASSET'
                ? 'RECEIVABLE_AR_RECOGNITION'
                : 'PERFORMANCE_ACCOUNTING_RECOGNITION',
            'consuming_recognition_id' => (string) Str::ulid(),
            'consuming_journal_entry_id' => $journal['id'],
            'consideration_transition_id' => $graph['transition_id'],
            'consideration_lot_id' => $graph['lot_id'],
            'economic_leg_identity' => $legIdentity,
            'amount' => $amount,
            'currency' => 'SAR',
            'status' => 'effective',
            'created_at' => now(),
            'reversal_operation_id' => null,
            'reversed_at' => null,
        ]);

        $line = $journal['lines']->get($origin->account_id);
        if ($line === null) {
            throw new \LogicException('Missing predecessor Account Journal line fixture.');
        }

        DB::table('accounting_position_consumption_journal_line_allocations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'consumption_id' => $id,
            'journal_entry_id' => $journal['id'],
            'journal_line_id' => $line->id,
            'amount' => $amount,
            'currency' => 'SAR',
            'economic_leg_identity' => $legIdentity,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function assertSqlRejected(callable $operation): void
    {
        DB::beginTransaction();

        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted inconsistent Accounting Recognition provenance.');
        } catch (QueryException $exception) {
            self::assertContains(
                (string) ($exception->errorInfo[0] ?? ''),
                ['23514', '23503', '23505', '55000'],
                $exception->getMessage(),
            );
        } finally {
            DB::rollBack();
        }
    }
}
