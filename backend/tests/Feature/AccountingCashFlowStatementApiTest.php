<?php

declare(strict_types=1);

namespace Tests\Feature;

final class AccountingCashFlowStatementApiTest extends AccountingApiTestCase
{
    public function test_cash_flow_api_requires_ledger_authority_and_validates_its_closed_contract(): void
    {
        $url = '/api/accounting/reports/cash-flow?from_date=2026-01-01&to_date=2026-01-31';
        $this->getJson($url)->assertUnauthorized();

        [, $actor] = $this->ready('CA');
        $this->acting($actor);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.cash_flow.from_date', '2026-01-01')
            ->assertJsonPath('data.cash_flow.ending_cash', '0.00');
        $this->getJson($url.'&tenant_id=other')->assertUnprocessable()->assertJsonValidationErrors('query');
        $this->getJson('/api/accounting/reports/cash-flow?from_date=2026-02-01&to_date=2026-01-31')->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }
}
