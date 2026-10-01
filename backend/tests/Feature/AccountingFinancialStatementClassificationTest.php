<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use App\Modules\Accounting\Queries\TrialBalanceQuery;
use App\Modules\Accounting\Support\FinancialStatementClassificationCatalog;
use Illuminate\Support\Facades\DB;

final class AccountingFinancialStatementClassificationTest extends AccountingApiTestCase
{
    public function test_catalog_is_complete_ordered_and_matches_database_account_constraints(): void
    {
        [$tenant, $actor] = $this->accountingActor();
        $this->activate($tenant, $actor);
        $entries = FinancialStatementClassificationCatalog::entries();

        self::assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110], array_column($entries, 'order'));
        self::assertSame(['current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'operating_revenue', 'other_revenue', 'cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'], FinancialStatementClassificationCatalog::classifications());
        self::assertSame(['asset', 'liability', 'equity', 'revenue', 'expense'], FinancialStatementClassificationCatalog::accountTypes());

        foreach ($entries as $index => $entry) {
            $id = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, [
                'code' => 'FSC'.($index + 1),
                'name' => $entry['label'],
                'description' => null,
                'kind' => 'posting',
                'account_type' => $entry['account_type'],
                'classification' => $entry['classification'],
                'parent_id' => null,
            ]);
            self::assertSame($entry['classification'], DB::table('accounts')->where('id', $id)->value('classification'));
        }

        self::assertFalse(FinancialStatementClassificationCatalog::isValidPostingPair('asset', 'operating_revenue'));
        self::assertFalse(FinancialStatementClassificationCatalog::isValidPostingPair('expense', 'equity'));
        self::assertNotContains(null, FinancialStatementClassificationCatalog::classifications());
    }

    public function test_archived_account_and_existing_read_models_preserve_classification_metadata(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('FC');
        $this->posted($tenant, $actor, $cash, $equity, '2026-01-01');
        app(ManageAccountAction::class)->archive((string) $tenant->id, $cash, $actor);

        $trialBalance = app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-12-31', includeZero: true);
        self::assertSame('current_asset', collect($trialBalance['rows'])->firstWhere('account_id', $cash)['classification']);
        $ledger = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-01-01', '2026-12-31');
        self::assertSame('current_asset', $ledger['account']['classification']);
        self::assertSame('archived', $ledger['account']['status']);
    }

    public function test_postgresql_rejects_a_representative_invalid_cross_type_pair(): void
    {
        [$tenant, $actor] = $this->accountingActor();
        $this->activate($tenant, $actor);

        $this->expectException(AccountingValidationFailed::class);
        app(ManageAccountAction::class)->create((string) $tenant->id, $actor, [
            'code' => 'FSCBAD',
            'name' => 'Invalid database pair',
            'description' => null,
            'kind' => 'posting',
            'account_type' => 'asset',
            'classification' => 'operating_revenue',
            'parent_id' => null,
        ]);
    }
}
