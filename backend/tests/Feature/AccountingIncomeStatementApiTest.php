<?php

declare(strict_types=1);

namespace Tests\Feature;

final class AccountingIncomeStatementApiTest extends AccountingApiTestCase
{
    public function test_income_statement_api_returns_posted_range_activity_and_rejects_invalid_filters(): void
    {
        [$tenant, $actor, , $cash] = $this->ready('IS');
        $revenue = $this->account($tenant, $actor, 'IS4', 'revenue', 'operating_revenue');
        $this->posted($tenant, $actor, $cash, $revenue, '2026-03-01');
        $this->acting($actor);

        $this->getJson('/api/accounting/reports/income-statement?from_date=2026-03-01&to_date=2026-03-01')
            ->assertOk()
            ->assertJsonPath('data.income_statement.operating_revenue', '100.00')
            ->assertJsonPath('data.income_statement.revenue', '100.00')
            ->assertJsonPath('data.income_statement.net_income', '100.00');

        $this->getJson('/api/accounting/reports/income-statement?from_date=2026-03-02&to_date=2026-03-01')->assertUnprocessable();
        $this->getJson('/api/accounting/reports/income-statement?from_date=2026-03-01&to_date=2026-03-01&tenant_id=x')->assertUnprocessable();
    }
}
