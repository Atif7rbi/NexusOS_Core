<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageAccountAction;

final class AccountingTrialBalanceApiTest extends AccountingApiTestCase
{
    public function test_trial_balance_api_returns_posted_as_of_rows_and_unfiltered_grand_totals(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('TB');
        $zero = $this->account($tenant, $actor, 'TB9', 'asset', 'current_asset');
        $this->posted($tenant, $actor, $cash, $equity, '2026-03-01');
        $this->posted($tenant, $actor, $cash, $equity, '2026-03-02');
        app(ManageAccountAction::class)->archive((string) $tenant->id, $cash, $actor);
        $this->acting($actor);

        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-03-01&account_type=asset')
            ->assertOk()
            ->assertJsonPath('data.trial_balance.as_of_date', '2026-03-01')
            ->assertJsonPath('data.trial_balance.debit_total', '100.00')
            ->assertJsonPath('data.trial_balance.credit_total', '100.00')
            ->assertJsonPath('data.trial_balance.is_balanced', true)
            ->assertJsonCount(1, 'data.trial_balance.rows')
            ->assertJsonPath('data.trial_balance.rows.0.account_id', $cash)
            ->assertJsonPath('data.trial_balance.rows.0.status', 'archived');

        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-03-01&include_zero=true')
            ->assertOk()
            ->assertJsonCount(3, 'data.trial_balance.rows')
            ->assertJsonPath('data.trial_balance.rows.2.account_id', $zero);
    }

    public function test_trial_balance_api_rejects_invalid_or_unknown_filters_before_reading(): void
    {
        [, $actor] = $this->accountingActor();
        $this->acting($actor);

        $this->getJson('/api/accounting/reports/trial-balance')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=1999-12-31')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31&account_type=invalid')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31&classification=invalid')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31&include_zero=perhaps')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/trial-balance?as_of_date=2026-12-31&unexpected=true')->assertUnprocessable();
    }
}
