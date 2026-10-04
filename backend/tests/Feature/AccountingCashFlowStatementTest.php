<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageCashFlowSemanticsAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Queries\CashFlowStatementQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountingCashFlowStatementTest extends AccountingApiTestCase
{
    public function test_direct_method_uses_posted_cash_journal_truth_with_opening_and_exact_categories(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CF');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $expense = $this->account($tenant, $actor, '5000', 'expense', 'operating_expense');
        $asset = $this->account($tenant, $actor, '1500', 'asset', 'non_current_asset');
        $this->period($tenant, $actor, '2025-01-01', '2025-12-31');
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);

        $this->postJournal($tenant->id, $actor, '2025-12-31', [new JournalLineData($cash, '25.00', '0'), new JournalLineData($revenue, '0', '25.00')], 'operating');
        $this->postJournal($tenant->id, $actor, '2026-01-01', [new JournalLineData($cash, '100.10', '0'), new JournalLineData($revenue, '0', '100.10')], 'operating');
        $this->postJournal($tenant->id, $actor, '2026-01-31', [new JournalLineData($asset, '40.00', '0'), new JournalLineData($cash, '0', '40.00')], 'investing');
        $this->postJournal($tenant->id, $actor, '2026-02-01', [new JournalLineData($expense, '5.00', '0'), new JournalLineData($cash, '0', '5.00')], 'operating');

        $result = app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');

        self::assertSame('25.00', $result['beginning_cash']);
        self::assertSame('100.10', $result['operating_activities']);
        self::assertSame('-40.00', $result['investing_activities']);
        self::assertSame('0.00', $result['financing_activities']);
        self::assertSame('60.10', $result['net_change_in_cash']);
        self::assertSame('85.10', $result['ending_cash']);
    }

    public function test_reporting_fails_closed_when_adopted_posted_cash_history_has_no_semantic(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CI');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Historical cash receipt', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);

        $this->expectException(AccountingValidationFailed::class);
        $this->expectExceptionMessage('CASH_FLOW_CLASSIFICATION_INCOMPLETE');
        app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');
    }

    public function test_pre_range_unclassified_cash_history_contributes_to_beginning_cash_without_failing_the_period(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CB');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $this->period($tenant, $actor, '2025-01-01', '2025-12-31');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2025-12-01', 'Historical cash receipt', [new JournalLineData($cash, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);

        $result = app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31');

        self::assertSame('100.00', $result['beginning_cash']);
        self::assertSame('0.00', $result['net_change_in_cash']);
        self::assertSame('100.00', $result['ending_cash']);
    }

    public function test_historical_adoption_classifies_existing_posted_cash_journal_without_mutating_journal_truth(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CH');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Historical receipt', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        $semantics = app(ManageCashFlowSemanticsAction::class);
        $semantics->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $semanticId = $semantics->adoptHistoricalSemantic((string) $tenant->id, $journal, 'operating', (string) Str::ulid(), $actor);

        self::assertNotEmpty($semanticId);
        self::assertSame('10.00', app(CashFlowStatementQuery::class)->execute((string) $tenant->id, '2026-01-01', '2026-01-31')['operating_activities']);
    }

    public function test_historical_adoption_replays_exactly_and_writes_one_audit_fact(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CP');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Historical receipt', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        $semantics = app(ManageCashFlowSemanticsAction::class);
        $semantics->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $operation = (string) Str::ulid();
        $first = $semantics->adoptHistoricalSemantic((string) $tenant->id, $journal, 'operating', $operation, $actor);
        $replay = $semantics->adoptHistoricalSemantic((string) $tenant->id, $journal, 'operating', $operation, $actor);

        self::assertSame($first, $replay);
        self::assertSame(1, DB::table('accounting_audits')->where('event', 'cash_flow.historical_semantic_adopted')->where('subject_id', $journal)->count());
    }

    public function test_historical_adoption_rejects_conflicting_replay(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CX');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Historical receipt', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        $semantics = app(ManageCashFlowSemanticsAction::class);
        $semantics->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        $operation = (string) Str::ulid();
        $semantics->adoptHistoricalSemantic((string) $tenant->id, $journal, 'operating', $operation, $actor);

        $this->expectException(AccountingConflict::class);
        $semantics->adoptHistoricalSemantic((string) $tenant->id, $journal, 'investing', $operation, $actor);
    }

    public function test_historical_adoption_cannot_cross_tenant_boundaries(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('CT');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-01', 'Tenant A history', [new JournalLineData($cash, '10.00', '0'), new JournalLineData($revenue, '0', '10.00')]);
        app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        app(ManageCashFlowSemanticsAction::class)->assignCashRole((string) $tenant->id, $cash, 'cash', (string) Str::ulid(), $actor);
        [$otherTenant, $otherActor] = $this->ready('CU');

        $this->expectException(AccountingValidationFailed::class);
        app(ManageCashFlowSemanticsAction::class)->adoptHistoricalSemantic((string) $otherTenant->id, $journal, 'operating', (string) Str::ulid(), $otherActor);
    }

    /** @param list<JournalLineData> $lines */
    private function postJournal(string $tenantId, object $actor, string $date, array $lines, string $activity): void
    {
        $journal = app(ManageManualJournalAction::class)->create($tenantId, $actor, $date, 'Cash flow', $lines, $activity);
        app(ManageManualJournalAction::class)->post($tenantId, $journal, $actor);
    }
}
