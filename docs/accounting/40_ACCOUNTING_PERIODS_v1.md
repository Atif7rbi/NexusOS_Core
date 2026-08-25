# Accounting Foundations v1 — Accounting Periods

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Purpose

An AccountingPeriod is a Tenant-owned control range that decides whether a
Journal with an explicit accounting date may post. It is not a fiscal-year
aggregate, closing-entry engine, or reporting cache.

## 2. Model

Each Period contains:

- ULID `id` and `tenant_id`;
- inclusive `start_date` and `end_date` (`DATE`);
- status `open` or `closed`;
- required `created_by` and `created_at`;
- required last mutation `updated_by` and `updated_at`;
- current-close pair `closed_at`, `closed_by`;
- current-reopen triple `reopened_at`, `reopened_by`, `reopen_reason`.

There is no `locked` state in v1. `closed` already has one unambiguous effect:
no new Journal can post into the Period.

## 3. Date range rules

1. Both boundaries are required calendar dates.
2. Both boundaries are within `2000-01-01..9999-12-31`, matching the verified
   `business_number_sequences.year` range used for every Posted Journal.
3. `start_date <= end_date`.
4. Boundaries are inclusive.
5. Periods for the same Tenant must not overlap.
6. Periods for different Tenants are independent.
7. Gaps are allowed.
8. Adjacent Periods are allowed only when the next starts after the prior end.

Examples:

| Existing | Proposed | Result |
|---|---|---|
| Jan 1–Jan 31 | Feb 1–Feb 28 | allowed, adjacent |
| Jan 1–Jan 31 | Jan 31–Feb 28 | rejected, Jan 31 overlaps |
| Jan 1–Jan 31 | Mar 1–Mar 31 | allowed, February gap |

Gaps intentionally make posting fail for uncovered dates. NexusOS does not
silently manufacture a Period or choose the nearest one.

## 4. Overlap enforcement

The Core does not verify the availability of the optional `btree_gist`
extension. Accounting v1 therefore does not require an exclusion-constraint
extension.

A PostgreSQL trigger provides authoritative concurrent overlap protection:

1. require an AccountingSettings row;
2. lock that Tenant's AccountingSettings coordination row `FOR UPDATE`;
3. query any other Period for the Tenant where:

```text
existing.start_date <= proposed.end_date
AND existing.end_date >= proposed.start_date
```

4. reject with named constraint semantics when a row exists.

All create and boundary-edit Actions acquire the same coordination row before
writing. The lock serializes competing range changes for one Tenant. A simple
application pre-check without the shared lock is forbidden.

## 5. Journal relationship

### 5.1 Frozen decision

`journal_entries.accounting_period_id` is stored.

It is:

- null while Draft;
- resolved from `(tenant_id,entry_date)` at posting;
- required when Posted;
- same-Tenant through a composite FK;
- immutable after posting.

Storing the resolved ID preserves historical control truth: the system can show
which Period authorized posting even after that Period is later closed and
reopened.

### 5.2 Why resolve-at-posting is not enough by itself

If a Posted Journal stored only `entry_date`, later Period-boundary remediation
could silently change its implied Period. The stored FK prevents that ambiguity.

### 5.3 Why Draft does not store a Period

Drafts are not ledger truth. A date can be entered before a Period exists or
after it closes. Binding a Draft early would create stale control state. The
current range and status are re-evaluated only when posting is attempted.

## 6. Posting eligibility

The posting transaction:

1. locks the Draft Journal;
2. selects the one same-Tenant Period containing `entry_date`;
3. locks that Period `FOR UPDATE`;
4. rejects when none exists;
5. treats more than one match as a database-integrity fault, although overlap
   protection should make it impossible;
6. rejects unless status is `open`;
7. stores the Period ID before transitioning Journal to Posted;
8. lets the database posting trigger recheck Tenant, date containment, and open
   status.

The Period row lock is shared by posting, close, and reopen. Therefore:

- if posting locks first, close waits and the Journal can commit before closure;
- if close locks first, posting waits, then observes `closed` and fails;
- no Journal can commit into a Period that was already closed when it obtained
  the common lock.

## 7. Draft behavior

- A Draft may carry a date in a closed Period.
- A Draft may carry a date in a gap.
- A Draft may carry a future date.
- Draft creation/edit does not lock a Period.
- Closing a Period does not require deleting or modifying Drafts.
- Posting always evaluates current Period truth.

The application should display posting ineligibility when known, but this is
advisory until the posting transaction locks and rechecks the Period.

## 8. Create and boundary mutation

### 8.1 Create

New Periods are always `open`. Creating a pre-closed Period is forbidden because
it would fabricate close evidence without a close transition.

The Action locks AccountingSettings, checks the range, inserts the Period with
the actor, and records `period.created` in one transaction.

### 8.2 Boundary edit

Boundary editing is allowed only when:

- the Period is open;
- the coordination row and Period are locked;
- no Posted Journal references the Period;
- the resulting range is valid and non-overlapping;
- the actor is authorized.

Draft Journal dates do not block editing because they have no Period FK. The UI
may warn about affected Drafts, but warning is not an invariant.

If any Posted Journal references the Period, both dates are immutable forever,
including after reopen. This prevents historical reassignment.

## 9. Close

Closing changes `open → closed` and sets `closed_at` and `closed_by`.
`reopened_at`, `reopened_by`, and `reopen_reason` are cleared because they
describe only the current open-after-reopen state; immutable audit retains every
earlier cycle.

Closing requires:

- locked Period;
- current status `open`;
- active actor with `close_period` capability;
- successful `period.closed` audit insertion in the same transaction.

The following do not block close:

- an empty Period;
- Draft Journals dated inside it;
- its end date being current or future;
- reversed Journals, because they remain valid Posted ledger entries.

There is no automatic close by date or scheduler in v1.

## 10. Reopen

Reopening changes `closed → open` and requires:

- locked Period;
- current status `closed`;
- active Tenant administrator authority;
- trimmed nonblank reason up to 500 characters;
- `reopened_at`, `reopened_by`, and reason recorded on the Period;
- append-only `period.reopened` audit with the reason in the same transaction.

The current close fields are cleared on reopen. Every prior close and reopen
remains in AccountingAudit, so repeated cycles do not overwrite control
history.

Reopen does not alter any Posted Journal and does not automatically post queued
Drafts. It merely allows a later explicit posting attempt.

## 11. Reversal and closed Periods

A reversal is a new Journal and must post into an open Period. Its target may be
in a closed Period.

The normal correction path is:

```text
closed historical Period target
→ choose explicit date in a currently open Period
→ post exact reversal there
```

Reopening the historical Period solely to backdate a correction is a separate
privileged business decision and requires its own reason/audit. The Reversal
Action cannot reopen a Period implicitly.

## 12. Delete semantics

Physical Period deletion is forbidden in v1, even when unused. An erroneous
unused Period can have its boundaries corrected while open and history-free.

This avoids dangling creation/control audit and keeps period administration
traceable. Posted Journal FKs also use `RESTRICT`.

## 13. State-field coherence

The database CHECK/trigger contract is:

| Current state | Close fields | Reopen fields |
|---|---|---|
| newly-created open | all null | all null |
| closed | `closed_at` and `closed_by` non-null | all null |
| reopened open | all null | `reopened_at`, `reopened_by`, and nonblank reason all non-null |

Direct changes that do not match an allowed transition are rejected. Actor FKs
are same-Tenant and restrictive.

## 14. Concurrency protocol

| Operation | Required lock order |
|---|---|
| create Period | actor membership/User → AccountingSettings coordination row → overlap query/write |
| edit boundaries | actor membership/User → AccountingSettings → Period → Posted-history check → overlap query/write |
| close | actor membership/User → Period → audit insert |
| reopen | actor membership/User → Period → audit insert |
| post Journal | actor membership/User → Journal → Lines → Period → Accounts → number row → audit |

Create/edit serialize with other range structural mutations. Close/reopen and
posting serialize on the exact Period row without imposing a Tenant-wide lock on
normal posting.

All transactions set a bounded local lock timeout and translate lock failure.
Deadlock/serialization retries are owned by the outer use-case transaction.

## 15. Layer ownership

| Rule | DB | Domain/Application |
|---|---|---|
| valid vocabulary and date ordering | CHECK | typed validation |
| no overlap under concurrency | coordination lock + trigger | same lock + semantic precheck |
| one matching Period | overlap invariant | resolve and classify errors |
| open at posting | posting trigger under Period lock | posting policy under same lock |
| close/reopen transition coherence | trigger + CHECK | explicit Actions and authorization |
| reason required | CHECK | normalization and user error |
| boundary immutability after Posted History | trigger | policy/preflight under locks |
| audit append-only/transactional | audit triggers/FKs | recorder called before commit |
| authorization | no | AccountingAuthorization and active membership |
