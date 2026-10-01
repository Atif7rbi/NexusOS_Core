<?php

declare(strict_types=1);

namespace Tests\Feature;

final class AccountingFinancialStatementClassificationApiTest extends AccountingApiTestCase
{
    public function test_catalog_api_returns_canonical_metadata_in_frozen_order(): void
    {
        [, $actor] = $this->ready('FA');
        $this->acting($actor);

        $this->getJson('/api/accounting/reports/classifications')
            ->assertOk()
            ->assertJsonCount(11, 'data.classifications')
            ->assertJsonPath('data.classifications.0.classification', 'current_asset')
            ->assertJsonPath('data.classifications.0.statement', 'balance_sheet')
            ->assertJsonPath('data.classifications.0.group', 'current_assets')
            ->assertJsonPath('data.classifications.10.classification', 'other_expense')
            ->assertJsonPath('data.classifications.10.order', 110);

        $this->getJson('/api/accounting/reports/classifications?tenant_id=other')->assertUnprocessable();
    }

    public function test_account_api_validation_uses_the_canonical_type_classification_contract(): void
    {
        [, $actor] = $this->ready('FV');
        $this->acting($actor);

        $this->postJson('/api/accounting/accounts', [
            'code' => 'FVI1', 'name' => 'Valid Asset', 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'current_asset',
        ])->assertCreated()->assertJsonPath('data.account.classification', 'current_asset');

        $this->postJson('/api/accounting/accounts', [
            'code' => 'FVI2', 'name' => 'Invalid Asset', 'kind' => 'posting', 'account_type' => 'asset', 'classification' => 'operating_revenue',
        ])->assertUnprocessable()->assertJsonValidationErrors('classification');

        $this->postJson('/api/accounting/accounts', [
            'code' => 'FVI3', 'name' => 'Invalid Group', 'kind' => 'group', 'account_type' => 'asset', 'classification' => 'current_asset',
        ])->assertUnprocessable()->assertJsonValidationErrors('classification');
    }
}
