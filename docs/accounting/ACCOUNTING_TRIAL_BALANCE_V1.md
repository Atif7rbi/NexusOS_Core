# Accounting Trial Balance v1

## Source and scope

Trial Balance is a derived Accounting read model. Its only source is same-tenant
posting Accounts and Posted Journal Entries with their Journal Lines. It creates
no balance table, snapshot, cache, lifecycle, or audit event.

`GET /api/accounting/reports/trial-balance` requires `as_of_date` and accepts
optional `account_type`, `classification`, and `include_zero` filters.

## Semantics

Only `journal_entries.status = posted` and `entry_date <= as_of_date` contribute.
Reversals are ordinary Posted Journals: the original and its reversal both affect
the historical ledger at the dates on which they are posted.

Rows include posting Accounts only, ordered by `code`, then `id`. Archived posting
Accounts with activity remain visible. With `include_zero=false`, only accounts
whose debit and credit totals are both zero are hidden; zero-net accounts with
activity remain visible.

All amounts are PostgreSQL `NUMERIC` calculations returned as two-decimal strings.
`signed_balance` is debit minus credit. Asset and expense normal balances are debit
minus credit; liability, equity, and revenue normal balances are credit minus debit.

Grand debit and credit totals always cover the complete same-tenant Posted ledger at
the requested date. Row filters affect only displayed rows. `is_balanced` reports
whether those unfiltered grand totals are equal.

## Consistency and access

Rows and grand totals are returned by one PostgreSQL statement, so they share one
MVCC snapshot and take no ledger write locks. During concurrent posting the report
can observe either a complete pre-commit ledger or a complete post-commit ledger,
never a partial Journal or mixed snapshots.

The endpoint resolves the active tenant context and requires the existing
`view_ledger` capability. It is tenant-scoped in every ledger join. The established
Accounting runtime role needs no new privileges; its current SELECT grants are
sufficient.

## Out of scope

This phase does not add General Ledger APIs, financial statements, printable or
export reports, frontend reporting pages, materialized balances, or reporting
special-cases for Settlement, receivables, receipts, opening balances, or any other
journal source.
