<?php

declare(strict_types=1);

namespace Tests\Feature;

final class AccountingGeneralLedgerApiTest extends AccountingApiTestCase
{
    public function test_general_ledger_api_returns_posted_account_movements_and_validation_errors(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('GL');
        $this->posted($tenant, $actor, $cash, $equity, '2026-03-01');
        $this->acting($actor);

        $this->getJson("/api/accounting/reports/general-ledger/{$cash}?from_date=2026-03-01&to_date=2026-03-01")
            ->assertOk()->assertJsonPath('data.general_ledger.account.id', $cash)
            ->assertJsonPath('data.general_ledger.opening_balance', '0.00')
            ->assertJsonPath('data.general_ledger.closing_balance', '100.00')
            ->assertJsonCount(1, 'data.general_ledger.movements');

        $this->getJson("/api/accounting/reports/general-ledger/{$cash}?from_date=2026-03-02&to_date=2026-03-01")->assertUnprocessable();
        $this->getJson("/api/accounting/reports/general-ledger/{$cash}?from_date=2026-03-01&to_date=2026-03-01&unexpected=true")->assertUnprocessable();
    }
}
