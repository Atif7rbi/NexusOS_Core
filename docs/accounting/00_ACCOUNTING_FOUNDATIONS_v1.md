# NexusOS Core — Accounting Foundations v1

Version: 1.0
Status: **FROZEN + DDL-READY — pending external review approval**
Architecture baseline: `1fc7c0a348602364a7bafa1ae0f8b5c2a802157c`

## 1. Purpose

Accounting Foundations v1 defines the smallest production-safe general ledger
that future NexusOS modules can use without redesigning ledger truth. It is an
architecture and specification package, not an implementation.

The foundation must support later Receivables, Payments, Expenses, Accounts
Payable, Project Costing, Budgeting, and financial reporting while remaining:

- correct and double-entry;
- auditable and historically stable;
- tenant-safe in PostgreSQL, not only in Laravel;
- concurrency-safe under competing commands;
- migration-safe and operationally explicit;
- extensible through named boundaries rather than speculative frameworks.

## 2. Governing flow

```text
Business Transaction
        ↓
Owning Business Module Accounting Rule
        ↓
Accounting Posting Boundary
        ↓
Journal Entry
        ↓
Journal Lines
        ↓
Ledger Queries / Financial Reporting
```

The owning Business Module decides that an accounting event occurred, why it
occurred, which stable business source identifies it, and which economic lines
its approved accounting rule produces. Accounting Core alone decides whether
those lines may become ledger truth.

Business Modules must not insert, update, or delete `journal_entries` or
`journal_lines`. They call the internal Accounting posting boundary inside the
same database transaction as the source transition.

## 3. Accounting Domain boundary

### 3.1 Accounting Core owns

- Accounting activation and the v1 SAR ledger policy.
- Chart-of-Accounts identity, hierarchy, classification, posting eligibility,
  archive/restore behavior, and structural-history protection.
- Accounting Period ranges, open/closed state, close/reopen control, and the
  authoritative period assigned to each posted Journal.
- Journal drafts created through Accounting use cases.
- Posting-time validation, period assignment, Journal numbering, immutable
  Posted Journals, and immutable Journal Lines.
- Exact reversals and the linear reversal relationship.
- Source-reference validation and authoritative duplicate-posting prevention.
- Initial general-ledger opening-balance onboarding and its effective-state
  correction model.
- Exact decimal persistence, double-entry validation, and ledger query truth.
- Accounting-specific append-only control audit.
- Accounting errors and results returned by the Posting API.

### 3.2 Accounting Core does not own

- Payment, receipt, expense, vendor bill, invoice, contract, reservation, or
  collection-schedule lifecycles.
- The rule that decides when a Business record becomes financially recognized.
- Contract revenue-recognition policy.
- Accounts Receivable or Accounts Payable subledger documents.
- VAT, ZATCA, e-invoicing, bank reconciliation, payroll, fixed assets,
  inventory, budgets, approval workflows, or cost-center dimensions.
- Business-source mutation, authorization, or source-row locking.
- A generic financial-rules DSL, generic event store, universal workflow
  engine, or multi-currency/FX platform.

### 3.3 Existing business concepts remain separate

The following are explicit non-equivalences:

```text
Collection Schedule ≠ Payment
Scheduled Amount ≠ Collected Amount
Contract ≠ Cash Receipt
Contract ≠ automatic Revenue Recognition
Reservation ≠ Accounting Transaction
```

No existing Contract, Reservation, or Collection Schedule creates a Journal as
part of Accounting Foundations v1.

## 4. Aggregate boundaries

| Aggregate / component | Root | Owned state | Boundary rule |
|---|---|---|---|
| AccountingSettings | `accounting_settings` | Tenant activation and immutable ledger currency | One immutable activation row per enabled Tenant |
| Account | `accounts` | Code, kind, type, classification, parent, lifecycle | Hierarchy references other Account roots; no child table |
| AccountingPeriod | `accounting_periods` | Inclusive dates and current control state | Journals reference a Period only at posting |
| JournalEntry | `journal_entries` | Header, source, status, number, date, reversal link | Owns `journal_lines`; Posted aggregate is immutable |
| OpeningBalanceOperation | `opening_balance_operations` | Onboarding workflow and effective/neutralized projection | Owns one opening-balance Journal by contract, not by table containment |
| AccountingAudit | `accounting_audits` | Control provenance | Append-only evidence; never ledger truth |
| Source-type catalog | `accounting_source_types` | Migration-owned stable source vocabulary | Global reference data, not a tenant aggregate |
| Number allocation | existing `business_number_sequences` | Tenant/prefix/year counter | Shared infrastructure reused with `JRN` prefix |

Every Accounting aggregate is tenant-owned. The global source-type catalog is
not an aggregate and contains no tenant data or user-configurable accounting
rules.

## 5. Posting API boundary

Accounting exposes four distinct application capabilities:

1. **Manual Journal** — create/edit/delete a persistent manual Draft; explicitly
   post it through the common posting engine.
2. **Business-generated posting** — an internal, non-HTTP boundary invoked by an
   owning module within the caller's already-open transaction.
3. **Reversal** — create and post an exact opposite Journal against one Posted
   Journal; no mutation of the target Journal.
4. **Opening balance** — manage a persistent onboarding operation and its Draft
   Journal, then post through the same engine.

All four paths converge on the same posting invariants. There is no privileged
path that bypasses period, account, balance, numbering, tenant, source, audit,
or immutability controls.

The detailed contracts are frozen in
`30_JOURNAL_AND_POSTING_v1.md` and
`90_ACCOUNTING_INTEGRATION_CONTRACT_v1.md`.

## 6. Foundational decisions

| Area | Frozen v1 decision |
|---|---|
| Database | PostgreSQL is mandatory for Accounting runtime and tests |
| IDs | Accounting business entities use ULIDs; actor IDs remain existing `users.id` bigints |
| Account types | `asset`, `liability`, `equity`, `revenue`, `expense` |
| Account classifications | Closed reporting vocabulary, type-compatible, stored only on posting Accounts |
| Account hierarchy | Same Tenant and same type; parent must be a group; no self-parent or cycles |
| Journal status | `draft → posted`; Posted is terminal and immutable |
| Reversal | Exact only; one direct reversal per Journal; reversal-of-reversal allowed as a linear chain |
| Accounting date | Explicit `entry_date` (`DATE`), never inferred from operational timestamps |
| Supported date range | `2000-01-01` through `9999-12-31`, matching the existing number-sequence year constraint |
| Period link | Nullable on Draft; resolved, stored, and immutable at posting |
| Amounts | PostgreSQL `NUMERIC(19,2)`; strings at API boundaries; no float/double |
| Currency | SAR-only Accounting v1; non-SAR sources cannot post |
| Numbering | Existing tenant/year/prefix allocator, `JRN`, allocated at posting inside the posting transaction |
| Source identity | Stable migration-owned source type plus ULID source ID; authoritative partial unique index |
| Opening balance | Real Journal; at most one draft-or-effective initial operation per Tenant; correction by exact reversal/replacement |
| Audit | Dedicated append-only Accounting control audit; Journals/Lines remain ledger truth |
| Authorization | Existing role/Support-class convention; no new permission framework in v1 |
| Balances | Derived from Posted Journal Lines; no stored summary balance table |

## 7. Accounting date and operational time

- `entry_date` is the official accounting date and determines period eligibility,
  Journal number year, and date-filtered reports.
- `created_at` records when a database record was created inside its transaction.
- `posted_at` records when the posting transition was executed inside the
  transaction; it becomes durable/visible only if that transaction commits.
- `recorded_at` records when an audit event was inserted inside the same
  transaction; it likewise becomes durable only on commit.

Operational timestamps are `TIMESTAMPTZ` and are stored consistently with the
Core UTC database convention. They never replace `entry_date`.

## 8. Financial reporting readiness

No reports or cached balances are built by this package. The frozen ledger can
produce the required reports from authoritative data:

| Future report | Required authoritative data |
|---|---|
| Trial Balance | Posted lines through an as-of date, Account code/type/classification, exact debit/credit sums |
| General Ledger | Posted Journal date/number/description/source plus ordered lines and Account identity |
| Account Activity | One Account's Posted lines ordered by `entry_date`, Journal number, and line number |
| Balance Sheet | Asset/liability/equity classifications and net Posted balances through an as-of date |
| Income Statement | Revenue/expense classifications and Posted activity within a date range |

Reversals remain ordinary Posted Journals with opposite lines, so all reports
include correction truth without special subtraction flags. Group balances are
derived from descendant posting Accounts. No summary balance is authoritative.

Debit-normal display balance:

```text
asset or expense: SUM(debit) - SUM(credit)
```

Credit-normal display balance:

```text
liability, equity, or revenue: SUM(credit) - SUM(debit)
```

The model permits a balance opposite to the normal side; reports show a signed
balance rather than corrupting or hiding it.

## 9. Deferred extension boundaries

The following are intentionally deferred and create no v1 tables or fields:

- invoices and invoice UI;
- payments, cash application, and reconciliation;
- vendor management, expenses, and Accounts Payable workflow;
- VAT, ZATCA, and e-invoicing;
- bank reconciliation;
- payroll, fixed assets, and inventory;
- project-costing dimensions and a generic dimension engine;
- budgeting;
- multi-currency transaction amounts, base/foreign amounts, rates, and FX gain/loss;
- approval engine;
- full financial reporting UI and materialized balances;
- AR/AP subledger opening balances;
- automated closing entries and retained-earnings transfer.

Future modules extend the stable Posting API, register an immutable source type,
and add explicit Account references to AccountingSettings only when their
approved rules require them. They do not gain direct Journal-table access.

## 10. DDL readiness and non-blocking verification items

All material Accounting v1 decisions are resolved. The package is therefore
**FROZEN + DDL-READY** as an architecture conclusion.

Two environment facts remain `NOT VERIFIED` and must be recorded during the
future implementation preflight:

1. the exact PostgreSQL server version used by each target environment;
2. the target environment's effective `lock_timeout` and statement-timeout
   configuration.

These do not block DDL design. The planned features—row locks, CHECK and foreign
key constraints, partial unique indexes, PL/pgSQL triggers, and deferred
constraint triggers—are standard PostgreSQL capabilities already consistent
with techniques used in this repository. Accounting migrations must still fail
closed when the active driver is not `pgsql`.

## 11. PostgreSQL reference basis

The integrity strategy is aligned with the official PostgreSQL documentation:

- [Explicit locking](https://www.postgresql.org/docs/current/explicit-locking.html)
- [Constraints and partial uniqueness](https://www.postgresql.org/docs/current/ddl-constraints.html)
- [CREATE TRIGGER and deferred constraint triggers](https://www.postgresql.org/docs/current/sql-createtrigger.html)
- [Exact numeric types](https://www.postgresql.org/docs/current/datatype-numeric.html)
- [Client statement and lock timeouts](https://www.postgresql.org/docs/current/runtime-config-client.html)

The links describe capability, not the unverified server version deployed for
NexusOS.

## 12. Completion criteria

This package is complete only when all of the following remain true after
external review:

- every Posted Journal is balanced, tenant-safe, numbered, period-bound, and
  immutable;
- every correction is a new exact Journal rather than a mutation;
- account hierarchy and lifecycle cannot rewrite Posted History;
- source retries cannot double-post;
- Period close and posting serialize on the same Period row;
- opening-balance correction cannot leave two effective initial operations;
- every material control transition writes audit in the same transaction;
- every cross-tenant relation is prevented by database enforcement;
- implementation can proceed from `97_ACCOUNTING_DDL_PLAN_v1.md` without
  inventing a missing accounting rule.
