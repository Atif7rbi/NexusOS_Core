# Cash Flow Statement v1

Cash Flow Statement v1 is a derived, direct-method accounting read model. Posted Journal Lines remain the only monetary truth.

Only Accounts recorded in `account_cash_roles` contribute cash movement. A role is immutable and may only identify a current-asset posting Account as `cash` or `cash_equivalent`.

Every Posted Journal with a non-zero designated-cash delta must have exactly one immutable `journal_cash_flow_semantics` activity: `operating`, `investing`, or `financing`. A zero designated-cash delta must have no semantic. PostgreSQL deferred final-state guards enforce this contract at commit. The report fails closed when historical posted cash activity through `to_date` has not been adopted with a semantic.

`GET /api/accounting/reports/cash-flow?from_date=Y-m-d&to_date=Y-m-d` requires `view_ledger`. It returns canonical two-decimal strings for beginning cash, operating, investing, financing, net cash flow, and ending cash. `from_date` and `to_date` are inclusive for period movements; beginning cash is all posted designated-cash movement before `from_date`; ending cash is all such activity through `to_date`.

The query is one PostgreSQL statement, so all values are read from one MVCC snapshot. Reversals are ordinary posted Journals and preserve their original cash-flow activity. Historical adoption adds semantics without changing journal truth. Verified bank receipt cash postings require an assigned cash role and use operating activity.

Out of scope: indirect-method reporting, line-level allocation, materialized cash balances, heuristic classification, financial-statement presentation, and frontend reporting work.
