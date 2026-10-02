# Accounting Balance Sheet v1

Balance Sheet v1 is a read-only, same-tenant report derived solely from posted journal entries, journal lines, and posting accounts through an inclusive `as_of_date`. It requires `view_ledger` and is exposed at `GET /api/accounting/reports/balance-sheet`.

One PostgreSQL statement provides a coherent MVCC snapshot. Arithmetic stays PostgreSQL `NUMERIC` and all amounts are two-decimal strings. Assets use debit minus credit; liabilities and equity use credit minus debit. Archived accounts remain included and draft journals do not contribute.

The response groups current/non-current assets and liabilities, `equity_accounts`, and derived `current_earnings` (cumulative revenue normal-side activity minus expense normal-side activity). `equity` is their sum; closing and reversal journals remain ordinary historical ledger truth.

The report is tenant scoped and reconciles to Trial Balance ledger truth at the same date. It has no materialized balances, migration, fiscal-year transformation, retained-earnings workflow, exports, or frontend reporting feature.
