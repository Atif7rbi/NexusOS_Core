<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Queries\BalanceSheetQuery;
use App\Modules\Accounting\Queries\TrialBalanceQuery;

final class AccountingBalanceSheetTest extends AccountingApiTestCase
{
    public function test_balance_sheet_derives_classifications_earnings_and_trial_balance_truth(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('BS');
        $nonCurrentAsset = $this->account($tenant, $actor, '1500', 'asset', 'non_current_asset');
        $currentLiability = $this->account($tenant, $actor, '2000', 'liability', 'current_liability');
        $nonCurrentLiability = $this->account($tenant, $actor, '2500', 'liability', 'non_current_liability');
        $equity = $this->account($tenant, $actor, '3000', 'equity', 'equity');
        $revenue = $this->account($tenant, $actor, '4000', 'revenue', 'operating_revenue');
        $expense = $this->account($tenant, $actor, '5000', 'expense', 'operating_expense');
        foreach ([[new JournalLineData($cash, '100.10', '0'), new JournalLineData($revenue, '0', '100.10')], [new JournalLineData($nonCurrentAsset, '50.00', '0'), new JournalLineData($nonCurrentLiability, '0', '50.00')], [new JournalLineData($expense, '0.20', '0'), new JournalLineData($cash, '0', '0.20')], [new JournalLineData($cash, '20.00', '0'), new JournalLineData($currentLiability, '0', '20.00')], [new JournalLineData($cash, '10.00', '0'), new JournalLineData($equity, '0', '10.00')]] as $lines) {
            $id = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-01-31', 'Balance sheet', $lines);
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $id, $actor);
        }
        $result = app(BalanceSheetQuery::class)->execute((string) $tenant->id, '2026-01-31');
        self::assertSame('129.90', $result['current_assets']);
        self::assertSame('50.00', $result['non_current_assets']);
        self::assertSame('179.90', $result['assets']);
        self::assertSame('20.00', $result['current_liabilities']);
        self::assertSame('50.00', $result['non_current_liabilities']);
        self::assertSame('70.00', $result['liabilities']);
        self::assertSame('10.00', $result['equity_accounts']);
        self::assertSame('99.90', $result['current_earnings']);
        self::assertSame('109.90', $result['equity']);
        self::assertSame('179.90', $result['liabilities_and_equity']);
        $trial = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-01-31', includeZero: true);
        self::assertTrue($trial['is_balanced']);
    }
}
