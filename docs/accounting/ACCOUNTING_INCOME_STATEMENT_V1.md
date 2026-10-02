# Accounting Income Statement v1

## Source of truth

The Income Statement is a derived PostgreSQL read model. Its only financial
source is the same-tenant set of `posted` journal entries and their journal
lines; it is not a stored balance, projection, or business-source aggregate.

## API and access

`GET /api/accounting/reports/income-statement?from_date=Y-m-d&to_date=Y-m-d`

Both dates are required, canonical `Y-m-d` values and define an inclusive
period. The report requires the existing `view_ledger` capability. Tenant
authority always comes from the active authenticated membership, never from a
request filter. Unsupported query filters are rejected.

## Period and accounting grammar

Only journals whose `entry_date` is in the inclusive range and whose status is
`posted` contribute. Drafts do not contribute. A reversal is an ordinary
posted journal: it affects precisely the range containing its own entry date;
the original historical movement remains visible in earlier periods.

Revenue is calculated on its credit-normal side (`credit - debit`), while
expense is calculated on its debit-normal side (`debit - credit`). All
calculations remain PostgreSQL `NUMERIC` and response amounts are canonical
two-decimal strings.

The response exposes the frozen classifications:

- `operating_revenue` and `other_revenue`
- `cost_of_revenue`, `operating_expense`, `finance_cost`, and `other_expense`

`revenue` is the two revenue categories, `expense` is the four expense
categories, and `net_income = revenue - expense`. Closing entries are ordinary
posted ledger history, so complete and partial closes are reflected as their
actual period residuals.

## Historical and isolation semantics

Archived revenue and expense accounts remain reportable because archival does
not erase posted history. A period with no qualifying movements returns every
amount as `0.00`. Same-tenant joins and tenant predicates prevent another
tenant's accounts or journal lines from contributing to the report.

The report executes as one PostgreSQL statement, so PostgreSQL MVCC supplies a
single coherent snapshot. A concurrent committed journal is observed either as
the complete pre-commit report or the complete post-commit report, never as
partial journal state. The established Accounting runtime database role needs
only its existing read privileges.

## Reconciliation and scope

For a revenue or expense account, its General Ledger movement delta over the
same inclusive period reconciles to its matching Income Statement
classification contribution, using that account's normal-side grammar.

Income Statement v1 does not create balance storage, schema objects, financial
statement exports, a Balance Sheet endpoint, or a frontend financial-reporting
feature.
