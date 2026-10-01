<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

final class FinancialStatementClassificationCatalog
{
    /** @var array<string,list<string>> */
    private const CLASSIFICATIONS_BY_TYPE = [
        'asset' => ['current_asset', 'non_current_asset'],
        'liability' => ['current_liability', 'non_current_liability'],
        'equity' => ['equity'],
        'revenue' => ['operating_revenue', 'other_revenue'],
        'expense' => ['cost_of_revenue', 'operating_expense', 'finance_cost', 'other_expense'],
    ];

    /** @var list<array{account_type:string,classification:string,statement:string,section:string,group:string,order:int,label:string}> */
    private const ENTRIES = [
        ['account_type' => 'asset', 'classification' => 'current_asset', 'statement' => 'balance_sheet', 'section' => 'assets', 'group' => 'current_assets', 'order' => 10, 'label' => 'Current Assets'],
        ['account_type' => 'asset', 'classification' => 'non_current_asset', 'statement' => 'balance_sheet', 'section' => 'assets', 'group' => 'non_current_assets', 'order' => 20, 'label' => 'Non-current Assets'],
        ['account_type' => 'liability', 'classification' => 'current_liability', 'statement' => 'balance_sheet', 'section' => 'liabilities', 'group' => 'current_liabilities', 'order' => 30, 'label' => 'Current Liabilities'],
        ['account_type' => 'liability', 'classification' => 'non_current_liability', 'statement' => 'balance_sheet', 'section' => 'liabilities', 'group' => 'non_current_liabilities', 'order' => 40, 'label' => 'Non-current Liabilities'],
        ['account_type' => 'equity', 'classification' => 'equity', 'statement' => 'balance_sheet', 'section' => 'equity', 'group' => 'equity', 'order' => 50, 'label' => 'Equity'],
        ['account_type' => 'revenue', 'classification' => 'operating_revenue', 'statement' => 'income_statement', 'section' => 'revenue', 'group' => 'operating_revenue', 'order' => 60, 'label' => 'Operating Revenue'],
        ['account_type' => 'revenue', 'classification' => 'other_revenue', 'statement' => 'income_statement', 'section' => 'revenue', 'group' => 'other_revenue', 'order' => 70, 'label' => 'Other Revenue'],
        ['account_type' => 'expense', 'classification' => 'cost_of_revenue', 'statement' => 'income_statement', 'section' => 'expense', 'group' => 'cost_of_revenue', 'order' => 80, 'label' => 'Cost of Revenue'],
        ['account_type' => 'expense', 'classification' => 'operating_expense', 'statement' => 'income_statement', 'section' => 'expense', 'group' => 'operating_expenses', 'order' => 90, 'label' => 'Operating Expenses'],
        ['account_type' => 'expense', 'classification' => 'finance_cost', 'statement' => 'income_statement', 'section' => 'expense', 'group' => 'finance_costs', 'order' => 100, 'label' => 'Finance Costs'],
        ['account_type' => 'expense', 'classification' => 'other_expense', 'statement' => 'income_statement', 'section' => 'expense', 'group' => 'other_expenses', 'order' => 110, 'label' => 'Other Expenses'],
    ];

    /** @return list<string> */
    public static function accountTypes(): array
    {
        return array_keys(self::CLASSIFICATIONS_BY_TYPE);
    }

    /** @return list<string> */
    public static function classifications(): array
    {
        return array_column(self::ENTRIES, 'classification');
    }

    /** @return list<string> */
    public static function classificationsFor(string $accountType): array
    {
        return self::CLASSIFICATIONS_BY_TYPE[$accountType] ?? [];
    }

    public static function isValidPostingPair(string $accountType, ?string $classification): bool
    {
        return $classification !== null && in_array($classification, self::classificationsFor($accountType), true);
    }

    /** @return list<array{account_type:string,classification:string,statement:string,section:string,group:string,order:int,label:string}> */
    public static function entries(): array
    {
        return self::ENTRIES;
    }
}
