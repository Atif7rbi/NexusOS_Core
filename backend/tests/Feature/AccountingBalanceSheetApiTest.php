<?php

declare(strict_types=1);

namespace Tests\Feature;

final class AccountingBalanceSheetApiTest extends AccountingApiTestCase
{
    public function test_balance_sheet_api_validates_and_requires_ledger_authority(): void
    {
        $url = '/api/accounting/reports/balance-sheet?as_of_date=2026-01-01';
        $this->getJson($url)->assertUnauthorized();
        [$tenant, $actor] = $this->ready('BA');
        $this->acting($actor);
        $this->getJson($url)->assertOk()->assertJsonPath('data.balance_sheet.as_of_date', '2026-01-01');
        $this->getJson($url.'&tenant_id=x')->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->getJson('/api/accounting/reports/balance-sheet?as_of_date=bad')->assertUnprocessable()->assertJsonValidationErrors('as_of_date');
    }
}
