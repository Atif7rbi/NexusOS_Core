# Accounting General Ledger v1

The General Ledger is a read-only derived view of one same-tenant posting Account and Posted Journal Lines. It has no balance table, migration, source-specific logic, write privilege, audit event, pagination, export, or frontend work.

`GET /api/accounting/reports/general-ledger/{account}?from_date=Y-m-d&to_date=Y-m-d` requires existing `view_ledger` authorization. Account lookup is same-tenant and posting-only; archived posting Accounts remain reportable.

Opening balance includes Posted normal-side deltas before `from_date`. Movements are Posted Lines inclusively between dates, ordered by entry date, journal sequence, line number, journal id, and line id. Asset/expense use debit minus credit; liability/equity/revenue use credit minus debit. Reversals are ordinary Posted movements.

Metadata, opening, movements, running balances, and closing balance come from one PostgreSQL statement and one MVCC snapshot. The closing balance reconciles to Trial Balance normal balance at the same end date.

Opening includes every same-tenant Posted line strictly before `from_date`; movements include both date boundaries. Closing equals opening plus every ordered normal-side movement delta. Each duplicate Account line remains a separate movement. The account lookup is non-disclosing across Tenants, and a valid no-history Account returns zero balances with no movements; an empty range preserves opening as closing. Readers use `view_ledger` and never take ledger write locks. Financial statements, exports, printing, source drill-down, persistent balances, and frontend reporting are out of scope.
