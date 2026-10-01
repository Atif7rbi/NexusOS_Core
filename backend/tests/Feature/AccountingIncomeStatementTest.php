<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use App\Modules\Accounting\Queries\IncomeStatementQuery;

final class AccountingIncomeStatementTest extends AccountingApiTestCase
{
    public function test_exact_numeric_decimal_arithmetic_has_no_float_artifacts(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('ID');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $expense = $this->account($tenant, $actor, '5000', 'expense', 'operating_expense');
        foreach ([['0.10', $revenue, true], ['0.20', $revenue, true], ['0.10', $expense, false]] as [$amount, $account, $isRevenue]) {
            $lines = $isRevenue ? [new JournalLineData($cash, $amount, '0'), new JournalLineData($account, '0', $amount)] : [new JournalLineData($account, $amount, '0'), new JournalLineData($cash, '0', $amount)];
            $id = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-06-01', 'Decimal', $lines);
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $id, $actor);
        }
        $result = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-06-01', '2026-06-01');
        self::assertSame('0.30', $result['operating_revenue']);
        self::assertSame('0.10', $result['operating_expense']);
        self::assertSame('0.30', $result['revenue']);
        self::assertSame('0.10', $result['expense']);
        self::assertSame('0.20', $result['net_income']);
    }

    public function test_date_boundaries_drafts_and_empty_ranges_follow_posted_range_truth(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('IB');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $this->posted($tenant, $actor, $cash, $revenue, '2026-01-01');
        $this->posted($tenant, $actor, $cash, $revenue, '2026-01-31');
        $this->posted($tenant, $actor, $cash, $revenue, '2026-02-01');
        $draft = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-15', 'Draft revenue', [new JournalLineData($cash, '999.00', '0'), new JournalLineData($revenue, '0', '999.00')]);
        self::assertNotEmpty($draft);
        $range = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');
        self::assertSame('200.00', $range['operating_revenue']);
        self::assertSame('200.00', $range['revenue']);
        self::assertSame('200.00', $range['net_income']);
        $empty = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-03-01', '2026-03-31');
        foreach (['operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense', 'revenue', 'expense', 'net_income'] as $field) {
            self::assertSame('0.00', $empty[$field]);
        }
    }

    public function test_archived_revenue_account_remains_reportable_and_reversals_follow_entry_date(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('IR');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journalId = app(ManageManualJournalAction::class)->create(
            (string) $tenant->id,
            $actor,
            '2026-01-15',
            'Original revenue',
            [new JournalLineData($cash, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')],
        );
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journalId, $actor);
        app(ManageAccountAction::class)->archive((string) $tenant->id, $revenue, $actor);
        app(ReverseJournalAction::class)->execute((string) $tenant->id, $journalId, $actor, '2026-02-05', 'Reverse revenue');

        $beforeReversal = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');
        self::assertSame('100.00', $beforeReversal['operating_revenue']);
        self::assertSame('100.00', $beforeReversal['revenue']);
        self::assertSame('100.00', $beforeReversal['net_income']);

        $afterReversal = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-02-28');
        self::assertSame('0.00', $afterReversal['operating_revenue']);
        self::assertSame('0.00', $afterReversal['revenue']);
        self::assertSame('0.00', $afterReversal['net_income']);
    }

    public function test_complete_and_partial_closing_entries_leave_exact_period_residuals(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('IC');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $expense = $this->account($tenant, $actor, '5000', 'expense', 'operating_expense');
        $equity = $this->account($tenant, $actor, '3000', 'equity', 'equity');

        foreach ([[new JournalLineData($cash, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')], [new JournalLineData($expense, '40.00', '0'), new JournalLineData($cash, '0', '40.00')]] as $lines) {
            $id = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-10', 'Period activity', $lines);
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $id, $actor);
        }
        $partialId = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-31', 'Partial close', [new JournalLineData($revenue, '25.00', '0'), new JournalLineData($equity, '10.00', '0'), new JournalLineData($expense, '0', '10.00'), new JournalLineData($equity, '0', '25.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $partialId, $actor);
        $partial = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');
        self::assertSame('75.00', $partial['revenue']);
        self::assertSame('30.00', $partial['expense']);
        self::assertSame('45.00', $partial['net_income']);

        $completeId = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-02-01', 'Complete close', [new JournalLineData($revenue, '75.00', '0'), new JournalLineData($equity, '30.00', '0'), new JournalLineData($expense, '0', '30.00'), new JournalLineData($equity, '0', '75.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $completeId, $actor);
        $complete = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-02-01');
        self::assertSame('0.00', $complete['revenue']);
        self::assertSame('0.00', $complete['expense']);
        self::assertSame('0.00', $complete['net_income']);
    }

    public function test_tenant_isolation_and_general_ledger_period_delta_reconcile_to_income_statement(): void
    {
        [$tenantA, $actorA, , $cashA] = $this->ready('IA');
        $revenueA = $this->account($tenantA, $actorA, '4000', 'revenue', 'operating_revenue');
        $id = app(ManageManualJournalAction::class)->create((string) $tenantA->id, $actorA, '2026-04-15', 'Tenant A revenue', [new JournalLineData($cashA, '100.00', '0'), new JournalLineData($revenueA, '0', '100.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenantA->id, $id, $actorA);

        [$tenantB, $actorB, , $cashB] = $this->ready('IB');
        $revenueB = $this->account($tenantB, $actorB, '4000', 'revenue', 'operating_revenue');
        $id = app(ManageManualJournalAction::class)->create((string) $tenantB->id, $actorB, '2026-04-15', 'Tenant B revenue', [new JournalLineData($cashB, '999.00', '0'), new JournalLineData($revenueB, '0', '999.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenantB->id, $id, $actorB);

        $incomeStatement = app(IncomeStatementQuery::class)->execute((string) $tenantA->id, '2026-04-01', '2026-04-30');
        self::assertSame('100.00', $incomeStatement['operating_revenue']);
        self::assertSame('100.00', $incomeStatement['revenue']);
        self::assertSame('100.00', $incomeStatement['net_income']);

        $generalLedger = app(GeneralLedgerQuery::class)->execute((string) $tenantA->id, $revenueA, '2026-04-01', '2026-04-30');
        self::assertSame('0.00', $generalLedger['opening_balance']);
        self::assertSame('100.00', $generalLedger['movements'][0]['credit']);
        self::assertSame('0.00', $generalLedger['movements'][0]['debit']);
        self::assertSame($incomeStatement['operating_revenue'], $generalLedger['closing_balance']);
    }

    public function test_all_six_classifications_and_summary_invariants_use_exact_posted_range_truth(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('IX');
        $types = ['operating_revenue' => ['4000', 'revenue', 'operating_revenue', '10.10'], 'other_revenue' => ['4100', 'revenue', 'other_revenue', '0.20'], 'cost_of_revenue' => ['5000', 'expense', 'cost_of_revenue', '3.00'], 'operating_expense' => ['5100', 'expense', 'operating_expense', '2.00'], 'finance_cost' => ['5200', 'expense', 'finance_cost', '1.00'], 'other_expense' => ['5300', 'expense', 'other_expense', '0.10']];
        foreach ($types as $classification => [$code, $type, $placement, $amount]) {
            $account = $this->account($tenant, $actor, $code, $type, $placement);
            $lines = $type === 'revenue' ? [new JournalLineData($cash, $amount, '0'), new JournalLineData($account, '0', $amount)] : [new JournalLineData($account, $amount, '0'), new JournalLineData($cash, '0', $amount)];
            $id = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-06-01', $classification, $lines);
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $id, $actor);
        }
        $result = app(IncomeStatementQuery::class)->execute((string) $tenant->id, '2026-06-01', '2026-06-01');
        self::assertSame('10.10', $result['operating_revenue']);
        self::assertSame('0.20', $result['other_revenue']);
        self::assertSame('3.00', $result['cost_of_revenue']);
        self::assertSame('2.00', $result['operating_expense']);
        self::assertSame('1.00', $result['finance_cost']);
        self::assertSame('0.10', $result['other_expense']);
        self::assertSame('10.30', $result['revenue']);
        self::assertSame('6.10', $result['expense']);
        self::assertSame('4.20', $result['net_income']);
    }
}
