<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Queries\TrialBalanceQuery;

final class AccountingTrialBalanceTest extends AccountingApiTestCase
{
    public function test_trial_balance_keeps_zero_net_activity_and_calculates_exact_normal_balances_in_order(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('10');
        $expense = $this->account($tenant, $actor, '5000', 'expense', 'operating_expense');
        $this->posted($tenant, $actor, $cash, $equity, '2026-01-01');
        $this->posted($tenant, $actor, $equity, $cash, '2026-01-02');
        $this->posted($tenant, $actor, $expense, $cash, '2026-01-03');

        $result = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-01-03');

        self::assertSame(['101', '103', '5000'], array_column($result['rows'], 'code'));
        self::assertSame('300.00', $result['debit_total']);
        self::assertSame('300.00', $result['credit_total']);
        self::assertTrue($result['is_balanced']);
        self::assertSame('100.00', $result['rows'][0]['debit_total']);
        self::assertSame('200.00', $result['rows'][0]['credit_total']);
        self::assertSame('-100.00', $result['rows'][0]['normal_balance']);
        self::assertSame('0.00', $result['rows'][1]['normal_balance']);
        self::assertSame('100.00', $result['rows'][2]['normal_balance']);
    }

    public function test_trial_balance_uses_numeric_decimal_values_without_binary_rounding(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('20');
        foreach ([['2026-01-01', '0.10'], ['2026-01-02', '0.20']] as [$date, $amount]) {
            $journal = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, $date, 'Decimal fixture', [
                new JournalLineData($cash, $amount, '0'),
                new JournalLineData($equity, '0', $amount),
            ]);
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $journal, $actor);
        }

        $result = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-01-02');

        self::assertSame('0.30', $result['debit_total']);
        self::assertSame('0.30', $result['credit_total']);
    }
}
