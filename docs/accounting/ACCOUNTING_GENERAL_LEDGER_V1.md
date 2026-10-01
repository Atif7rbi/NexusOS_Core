# Accounting General Ledger v1

The General Ledger is a read-only derived view of one same-tenant posting Account and Posted Journal Lines. It has no balance table, migration, source-specific logic, write privilege, audit event, pagination, export, or frontend work.

`GET /api/accounting/reports/general-ledger/{account}?from_date=Y-m-d&to_date=Y-m-d` requires existing `view_ledger` authorization. Account lookup is same-tenant and posting-only; archived posting Accounts remain reportable.

Opening balance includes Posted normal-side deltas before `from_date`. Movements are Posted Lines inclusively between dates, ordered by entry date, journal sequence, line number, journal id, and line id. Asset/expense use debit minus credit; liability/equity/revenue use credit minus debit. Reversals are ordinary Posted movements.

Metadata, opening, movements, running balances, and closing balance come from one PostgreSQL statement and one MVCC snapshot. The closing balance reconciles to Trial Balance normal balance at the same end date.
