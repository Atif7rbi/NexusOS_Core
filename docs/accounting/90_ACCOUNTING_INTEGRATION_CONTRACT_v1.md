# Accounting Foundations v1 — Application and Integration Contract

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Boundary rule

Only Accounting Application services write `accounts`, `accounting_periods`,
`journal_entries`, `journal_lines`, `opening_balance_operations`, or
`accounting_audits`.

Business Modules supply an approved economic posting specification through the
internal boundary. They never receive a Journal repository or table writer.

## 2. Application use-case families

### 2.1 Chart and activation

- `ActivateAccountingAction`
- `CreateAccountAction`
- `UpdateAccountAction`
- `ArchiveAccountAction`
- `RestoreAccountAction`

### 2.2 Period control

- `CreateAccountingPeriodAction`
- `UpdateAccountingPeriodBoundariesAction`
- `CloseAccountingPeriodAction`
- `ReopenAccountingPeriodAction`

### 2.3 Manual Journal

- `CreateManualJournalDraftAction`
- `UpdateManualJournalDraftAction`
- `DeleteManualJournalDraftAction`
- `PostManualJournalAction`

### 2.4 Shared/internal posting

- `PostBusinessTransaction`
- internal `PostJournalOperation` used only by authorized Accounting Actions

### 2.5 Correction and onboarding

- `ReverseJournalAction`
- `CreateOpeningBalanceDraftAction`
- `UpdateOpeningBalanceDraftAction`
- `DeleteOpeningBalanceDraftAction`
- `PostOpeningBalanceAction`

Names are implementation guidance; responsibility boundaries are normative.

## 3. Manual versus Business posting

| Concern | Manual Journal | Business-generated Journal |
|---|---|---|
| Who decides economic event | Accounting user | Owning Business Module rule |
| Draft persists | Yes | No; transaction-local |
| Origin | `manual` | `business` |
| Source tuple | Null | Required registered key + ULID |
| Transaction owner | Accounting Action | Owning Business Action |
| Source row lock | None | Mandatory before Accounting call |
| Idempotency identity | Explicit Draft ID/user action | Source tuple |
| Authorization | Accounting capability | Business transition authorization plus Accounting actor validity |
| Posting invariants | Common engine | Same common engine |

Neither path can select a Journal number, Period ID, Posted status, or
operational timestamp.

PostgreSQL rejects commit if a Business-generated Journal remains Draft. The
owning transaction must either reach Posted or roll back completely.

## 4. Source-type catalog

### 4.1 Purpose

`accounting_source_types` is an immutable, migration-owned global catalog. It
keeps provenance generic without accepting arbitrary user strings.

Columns:

- `origin`: `business`, `opening_balance`, or `reversal`;
- `key`: stable snake_case key, maximum 64 characters;
- `owner_module`: stable module key, maximum 64 characters;
- `description`: engineering description, maximum 255 characters.

Primary key is `(origin,key)`. UPDATE and DELETE are forbidden. The catalog is
not tenant-editable and contains no rules or Account mapping.

### 4.2 v1 registrations

| Origin | Key | Owner |
|---|---|---|
| `opening_balance` | `opening_balance_operation` | `accounting` |
| `reversal` | `journal_entry` | `accounting` |

No `business` key is registered until its owning module has an approved
integration.

### 4.3 Future examples

Potential keys include `payment_posted`, `expense_posted`,
`vendor_bill_posted`, or `adjustment_posted`. These examples are not registered
or authorized by this package.

Each future integration migration must:

1. insert one stable source type;
2. document the precise source state that triggers it;
3. document its deterministic line rule and Account settings;
4. define source currency behavior;
5. add atomicity, idempotency, tenant, rollback, and concurrency tests;
6. never reuse a key for a different economic event.

## 5. Source identity

Every non-manual Journal has:

```text
(tenant_id, origin, source_type, source_id)
```

`source_id` is a ULID identifying the owning event identity. If a Business
aggregate has one posting event, its aggregate ULID may be used. If it can emit
multiple postings with the same source type, the module must own durable ULID
event records or use distinct reviewed event keys. It must not use a random ID
generated only for each retry.

Generic source FK enforcement is impossible across unrelated tables. The owning
Business Action must lock and validate its source row. The Accounting partial
unique index remains authoritative for one Journal per source event.

Source IDs are not PHP class names, URLs, controller names, database table names,
or free-form references.

## 6. Business posting command

The internal command contains only:

- Tenant ID from active application context;
- authorized actor identity;
- registered `source_type` and stable `source_id`;
- source currency (`SAR` required);
- explicit `entry_date`;
- nonblank Journal description;
- ordered Lines:
  - Account ULID;
  - canonical two-decimal debit string;
  - canonical two-decimal credit string;
  - optional memo.

The boundary does not accept:

- `origin` (it is fixed to `business` by the method);
- Journal ID unless an internal deterministic construction contract needs it;
- Journal number/year/sequence;
- Period ID;
- status;
- posted/created timestamps;
- actor from an HTTP payload;
- Account codes in place of IDs;
- float/double amounts;
- arbitrary audit context.

## 7. Source locking contract

Before calling Accounting, the owning Business Action must:

1. begin the outer PostgreSQL transaction;
2. set the bounded local lock timeout;
3. resolve and lock active actor membership, then the User row, as required by
   the Accounting actor contract;
4. lock the source aggregate root `FOR UPDATE`;
5. re-read source lifecycle and Tenant under lock;
6. authorize the business transition;
7. construct deterministic posting Lines from locked source truth;
8. persist the source transition provisionally;
9. call Accounting on the same connection and transaction.

Accounting then follows its Journal/Period/Account/number lock order. The source
module must never acquire Accounting locks first and return to lock its source.

The source lock is mandatory even though source idempotency has a UNIQUE index.
The lock protects business lifecycle correctness; the index protects ledger
duplication.

## 8. Transaction ownership and atomicity

### 8.1 Same-database invariant

For v1, a Business transition that requires a Journal must commit atomically:

```text
source state transition
+ Posted Journal and Lines
+ source idempotency identity
+ Accounting audit
+ source module audit
= one PostgreSQL commit
```

If any required write fails, all roll back.

### 8.2 Internal method behavior

`PostBusinessTransaction`:

- requires an already-active transaction;
- never starts/commits an independent transaction;
- never owns an inner retry loop;
- uses the caller's connection;
- returns before outer commit with a provisional result;
- emits no external success notification before commit.

The outer Business Action owns maximum transaction retries and returns success
only after commit.

### 8.3 Separate-database future

Outbox/saga/eventual-consistency accounting is **DEFERRED**. If a future module
uses another database, it requires a new architecture. An asynchronous queue is
not an acceptable substitute for the v1 same-database invariant.

## 9. Idempotency algorithm

### 9.1 Normal first attempt

1. lock source;
2. query Journal by complete source tuple;
3. if none, create/post once;
4. commit source and Journal together.

### 9.2 Committed retry

If a Posted Journal already exists, Accounting compares the canonical requested
posting specification against it:

- Tenant, origin, source type/ID;
- entry date;
- description;
- ordered line numbers;
- Account IDs;
- exact debit/credit strings;
- memos.

If they match, return the existing Journal with
`idempotent_replay=true`. No new number or audit event is created.

If any material value differs, fail with `accounting_source_conflict`. The same
source identity cannot be repurposed after accounting rules or source data
change.

### 9.3 Concurrent attempt

The shared source row lock should serialize correct callers. The partial unique
source index is the final defense.

If a unique race still occurs, the failed transaction is rolled back. The outer
idempotency resolver starts a fresh transaction, locks/reloads source and
Journal, then applies the committed-retry comparison. It does not continue using
an aborted PostgreSQL transaction.

### 9.4 Unknown commit outcome

After connection loss, the caller retries the complete stable source command.
The source tuple reveals whether the prior commit succeeded. It never generates
a new source ID for the retry.

## 10. Inconsistent source states

The following are integrity incidents, not ordinary automatic repairs:

| Observed state | Required behavior |
|---|---|
| source says financially posted, no Journal exists | fail closed with `source_accounting_inconsistent`; do not guess Lines |
| Posted Journal exists, source transition absent | fail closed; investigate atomicity violation |
| source tuple exists with different Lines/date | `accounting_source_conflict` |
| source currency not SAR | `accounting_currency_unsupported` |
| unregistered source type | `accounting_source_type_unregistered` |

Repair requires an explicitly reviewed remediation command/migration and audit.

## 11. Reversal boundary

Reversal is owned exclusively by Accounting because it validates ledger
exactness and reversal-chain cardinality.

Input:

- Tenant-context target Journal ULID;
- explicit reversal date;
- nonblank reason;
- authorized current actor.

Accounting loads target source provenance for audit but does not call the
Business Module to mutate the original source. Reversal of financial effect and
reopening/cancelling a Business document are separate domain decisions. A future
Business workflow that needs both must own one outer transaction and call the
Accounting reversal operation under an explicit integration contract.

## 12. Opening Balance boundary

Opening Balance owns its Draft Journal and is not a Business-source integration.
Its Actions own their transactions and use the registered internal source type.

Generic manual endpoints and `PostBusinessTransaction` reject an
`opening_balance` origin/key. Only the OpeningBalance aggregate can create it.

## 13. Result contract

A successful posting result contains:

| Field | Meaning |
|---|---|
| `journal_id` | immutable Journal ULID |
| `tenant_id` | owner |
| `status` | `posted` |
| `journal_number` | allocated immutable number |
| `entry_date` | accounting date |
| `accounting_period_id` | resolved immutable Period |
| `origin` | posting origin |
| `source_type`, `source_id` | nullable only for manual |
| `posted_at` | operational timestamp |
| `idempotent_replay` | true only when returning a prior matching Business result |

Lines may be returned through a separate read resource. The result is not an
Eloquent entity passed across module boundaries.

## 14. Error model

Domain/Application exceptions use stable semantic categories:

- `accounting_not_active`;
- `accounting_currency_unsupported`;
- `accounting_actor_not_authorized`;
- `accounting_source_type_unregistered`;
- `accounting_source_conflict`;
- `source_accounting_inconsistent`;
- `journal_not_draft`;
- `journal_unbalanced`;
- `journal_lines_invalid`;
- `account_not_postable`;
- `account_archived`;
- `accounting_period_missing`;
- `accounting_period_closed`;
- `journal_already_reversed`;
- `reversal_not_exact`;
- `accounting_concurrency_conflict`;
- `accounting_integrity_violation`.

Presentation translates these without exposing PostgreSQL messages. Business
Modules may translate an Accounting exception into their own public API error
while preserving rollback and correlation.

## 15. Manual transaction boundary

Manual Draft creation/edit/delete each owns a short Accounting transaction.
Manual posting owns a separate transaction and revalidates everything under
locks. A successful earlier Draft save is not proof that posting will succeed.

This separation permits legitimate incomplete Drafts without weakening ledger
truth.

## 16. External side effects

Email, notifications, analytics jobs, webhooks, file export, and external APIs
must not run while Accounting locks are held.

If future after-commit notifications are added, they communicate a committed
Journal ID. Their delivery failure never rolls back or changes ledger truth, and
their message is not an Accounting audit record.

## 17. Integration acceptance checklist

A Business integration is not approved until its package answers:

1. What exact source lifecycle transition creates the economic event?
2. Which row is the source root and what is its lock order?
3. What stable source type/ID identifies one posting event?
4. What are the deterministic debit/credit rules and Account Settings FKs?
5. What is the source currency and rounding rule?
6. Does cancellation require no posting, an exact reversal, or a separate
   adjustment?
7. Can one source generate more than one event, and how are their IDs distinct?
8. How do retries compare an existing Journal?
9. Are source transition, Journal, both audits, and number in one transaction?
10. Do PostgreSQL tests prove concurrent duplicate calls post exactly once?
11. What happens when the Period is closed or an Account archived?
12. Does the implementation avoid all direct Journal repository/table access?
