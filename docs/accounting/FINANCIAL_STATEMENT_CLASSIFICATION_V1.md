# Financial Statement Classification v1

Financial Statement Classification v1 formalizes the existing Account metadata vocabulary. It does not calculate balances or financial statements.

Posting Accounts use one compatible classification: assets use `current_asset` or `non_current_asset`; liabilities use `current_liability` or `non_current_liability`; equity uses `equity`; revenue uses `operating_revenue` or `other_revenue`; and expenses use `cost_of_revenue`, `operating_expense`, `finance_cost`, or `other_expense`.

The catalog maps those keys to `balance_sheet` or `income_statement`, machine-stable sections and groups, English labels, and deterministic presentation order. Balance Sheet order is current assets, non-current assets, current liabilities, non-current liabilities, then equity. Income Statement order is operating revenue, other revenue, cost of revenue, operating expenses, finance costs, then other expenses.

Group Accounts have a null classification and are excluded. Archived posting Accounts retain their classification. Existing PostgreSQL constraints remain the persistence authority and preserve structural immutability after Posted history; the application catalog is the canonical consumer contract and does not dynamically discover Tenant data.

`GET /api/accounting/reports/classifications` returns global metadata only and requires `view_ledger`. It accepts no Tenant override or query filters. It returns no balances, Account counts, or Tenant-specific configuration.

Out of scope: Income Statement and Balance Sheet production APIs, calculations, persistent classification storage, Tenant customization, financial-report UI, printing, and exports.
