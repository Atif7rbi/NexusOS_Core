# Accounting Foundations v1 — Journal and Posting

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Ledger source of truth

The ledger is the set of `journal_entries` with `status='posted'` and their
immutable `journal_lines`.

Drafts are workflow state, not ledger entries. Audit rows are provenance, not
ledger entries. Reversal effect is represented by new Posted Lines, not a flag
that causes reports to ignore the target.

## 2. JournalEntry header

The v1 header contains:

| Concept | Field | Frozen rule |
|---|---|---|
| Identity | `id` | ULID, immutable |
| Tenant | `tenant_id` | Required; every child/parent relationship is tenant-composite |
| Accounting date | `entry_date` | Required `DATE` in `2000-01-01..9999-12-31`; authoritative business/accounting date |
| Period | `accounting_period_id` | Null in Draft; required and immutable when Posted |
| Description | `description` | Required, trimmed, nonblank, max 500 characters |
| Status | `status` | `draft` or `posted` |
| Origin | `origin` | `manual`, `business`, `opening_balance`, or `reversal` |
| Source key | `source_type` | Null for manual; registered stable key otherwise |
| Source identity | `source_id` | Null for manual; ULID otherwise |
| Number | `journal_number` | Null in Draft; assigned at posting |
| Number year | `journal_number_year` | Null in Draft; equals year of `entry_date` when Posted |
| Sequence | `journal_sequence_number` | Null in Draft; positive when Posted |
| Creation evidence | `created_by`, `created_at` | Required same-Tenant actor and `TIMESTAMPTZ` |
| Last Draft edit | `updated_by`, `updated_at` | Required same-Tenant actor/time; frozen at posting |
| Posting evidence | `posted_by`, `posted_at` | Null in Draft; required when Posted |
| Reversal target | `reverses_journal_entry_id` | Present only for reversal origin; same Tenant, target Posted |
| Reversal reason | `reversal_reason` | Present and nonblank only for reversal origin; max 500 |

No controller name, PHP class, URL, arbitrary source label, currency field,
approval field, cached total, or reversal status is stored.

All ledger amounts are SAR because the Tenant cannot activate Accounting with a
different currency and every source boundary validates source currency.

## 3. Origin and source shapes

| Origin | `source_type` | `source_id` | Reversal fields | Creation path |
|---|---|---|---|---|
| `manual` | null | null | null | persistent manual Draft use case |
| `business` | registered business event key | owning source/event ULID | null | internal cross-module Posting API |
| `opening_balance` | `opening_balance_operation` | Operation ULID | null | OpeningBalanceOperation only |
| `reversal` | `journal_entry` | target Journal ULID | target ID plus reason | Reversal Action only |

`accounting_source_types` has an immutable composite key `(origin,key)`. The v1
Accounting migration registers the two internal keys above. A future Business
Module registers its stable event key through a reviewed migration before its
Posting API can use it.

A partial unique index on
`(tenant_id,origin,source_type,source_id)` for non-manual origins is the
authoritative duplicate-posting guard.

## 4. JournalLine model

Each Line contains:

- ULID `id`;
- `tenant_id`;
- same-Tenant `journal_entry_id`;
- positive integer `line_number`;
- same-Tenant `account_id`;
- `debit NUMERIC(19,2)`;
- `credit NUMERIC(19,2)`;
- optional nonblank `memo` up to 500 characters;
- `created_at` and `updated_at` operational timestamps while Draft.

Line actor columns are not added. The Journal's `created_by`/`updated_by` and
Accounting audit identify aggregate edits without repeating the same actor on
every owned child.

### 4.1 Row invariant: debit XOR credit

Every persisted Line, including a Draft Line, satisfies exactly one side:

```text
(debit > 0.00 AND credit = 0.00)
OR
(credit > 0.00 AND debit = 0.00)
```

Both positive, both zero, negative values, and null values are rejected by the
stored-row constraints. The supported Application boundary rejects inputs with
more than two fractional digits before PostgreSQL type coercion.

### 4.2 Aggregate invariants at posting

A Journal may post only when:

```text
COUNT(lines) >= 2
SUM(debit) = SUM(credit)
SUM(debit) > 0.00
MIN(line_number) = 1
MAX(line_number) = COUNT(lines)
```

The unique key `(tenant_id,journal_entry_id,line_number)` plus the min/max/count
test proves contiguity. Exact `NUMERIC` equality is used; there is no epsilon.

### 4.3 Duplicate Accounts

The same Account may appear on multiple Lines in one Journal. Distinct Lines can
represent separate business components or memos. The Posting API does not merge
them. Line number remains their deterministic order.

### 4.4 Draft completeness

A Draft may temporarily have zero, one, or an unbalanced set of Lines. Every
individual persisted Line must still satisfy the XOR and value constraints.
Aggregate completeness is enforced only on the `draft → posted` transition.

## 5. Draft lifecycle

### 5.1 Creation

All Journals are inserted as `draft`, including Business, Opening Balance, and
Reversal Journals that are posted later in the same transaction. A database
trigger rejects direct INSERT with `status='posted'`.

Manual Drafts persist across requests. Business and reversal Drafts are
transaction-local. An Opening Balance Draft persists but is editable only
through its owning Operation.

A deferred Journal final-state constraint rejects commit while a `business` or
`reversal` Journal remains Draft. This permits the mandatory insert-as-Draft then
post sequence inside one transaction without allowing a stale system Draft to
reserve a source or reversal target after commit.

Manual Draft creation records `journal.draft_created` in the creation
transaction. Transaction-local Business/reversal Drafts do not emit a Draft
event, and Opening Balance uses its own `opening_balance.created` event.

### 5.2 Edit

- The application locks the parent Journal before any Line insert/update/delete.
- Line triggers lock/check the same parent and reject mutation if it is Posted.
- Manual endpoints may mutate only `origin='manual'`.
- Opening endpoints may mutate only the Journal linked to their locked Draft
  Operation.
- Source tuple and origin are immutable after Journal creation, including while
  Draft.
- Tenant and ID are always immutable.

### 5.3 Delete

A manual Draft may be deleted by an authorized user. An Opening Draft is deleted
only through `DeleteOpeningBalanceDraftAction`, which removes the owned Draft
Journal/Lines and Operation atomically.

Manual deletion records `journal.draft_deleted` before removing the aggregate.
Opening deletion records `opening_balance.draft_deleted`; it does not emit a
second generic Journal-deletion event.

Business and reversal Journals never expose a persistent Draft-delete use case;
a database delete guard rejects their explicit deletion. A failed transaction
rolls them back, and the final-state constraint rejects an accidental successful
commit that leaves either origin Draft.

Posted Journals and Lines can never be deleted. `TRUNCATE` is denied to the
application database role as an operational deployment control; ordinary table
triggers are not treated as protection against privileged database repair.

## 6. Posting transition

The common posting engine performs these steps in one transaction:

1. require Accounting active and resolve/lock the active actor membership;
2. lock the Draft Journal root;
3. validate immutable origin/source shape and caller ownership;
4. lock all current Journal Lines in `line_number,id` order;
5. resolve exactly one Period containing `entry_date`, then lock that Period;
6. require the Period to be open;
7. gather distinct Account IDs and lock Accounts in lexical ULID order;
8. validate same Tenant, posting kind, classification, and lifecycle eligibility;
9. validate line count, contiguity, XOR, positive total, and exact balance;
10. run origin-specific validation, including source idempotency, opening links,
    or exact reversal comparison;
11. lock/increment the existing `JRN` Tenant/year number row;
12. set Period, number, posted actor/time, and update status to `posted`;
13. let the PostgreSQL posting trigger independently revalidate the aggregate;
14. insert required Accounting audit events;
15. advance Opening effect projection when applicable;
16. commit, including deferred cross-table constraints.

If any step fails, Journal changes, Lines, number counter, effect projection, the
business source transition, and audit rows all roll back.

The application validates before the status update for semantic errors. The
database posting trigger is authoritative against direct writes and races.

## 7. Posted immutability

Once status is `posted`:

- every Journal header column is immutable;
- no Line may be inserted, updated, or deleted;
- the Journal cannot be deleted;
- its source tuple, Period, number, actors, and dates remain unchanged;
- the target does not change when a reversal is posted;
- Account master-data lifecycle can change only under the rules in the Chart of
  Accounts document.

Database triggers protect both parent and child tables. Application model guards
or hidden fields are complementary only.

## 8. Journal numbering

### 8.1 Existing infrastructure reused

Accounting uses `business_number_sequences` with:

```text
tenant_id = Journal tenant
prefix    = JRN
year      = EXTRACT(YEAR FROM entry_date)
```

The formatted number is:

```text
JRN-YYYY-NNN
```

`NNN` is a minimum width, not a maximum. Sequence 1000 formats as `1000`.
The frozen PostgreSQL-equivalent formatter is:

```text
'JRN-' || year || '-' ||
lpad(sequence::text, greatest(3, length(sequence::text)), '0')
```

Using `lpad(sequence::text, 3, '0')` is forbidden because PostgreSQL truncates a
string longer than the requested output length.

After locking the existing counter row, the allocator increments in PostgreSQL
and obtains the value with `UPDATE ... RETURNING current_value`. It does not cast
the stored bigint through PHP arithmetic. A negative legacy value or bigint
exhaustion is a hard numbering/integrity failure, never a wrap or reset.

### 8.2 Allocation time

- Drafts have no number.
- Allocation happens only after all validations and locks succeed, immediately
  before the posting status update.
- Allocation participates in the outer transaction; it must not commit or own a
  retry independently.
- `MAX()+1` is forbidden.

### 8.3 Uniqueness and validation

The Journal table enforces:

- unique `(tenant_id,journal_number)`;
- unique `(tenant_id,journal_number_year,journal_sequence_number)`;
- positive sequence;
- number year equals `entry_date` year;
- number string equals the frozen formatter.

### 8.4 Gaps and reuse

- A failed/rolled-back posting also rolls back the counter increment, so it does
  not consume a number.
- A committed number is never reused.
- Posted Journals cannot be deleted, so normal operation produces a monotonic
  committed series per Tenant/year.
- The system does not promise mathematical gaplessness after privileged data
  repair, import remediation, or a future approved migration. Such intervention
  requires audit and reconciliation; the allocator must never decrement to fill
  a gap.

## 9. Money representation

### 9.1 Storage

All Ledger debit/credit amounts use:

```text
NUMERIC(19,2)
```

This supplies 17 integer digits and exactly two fractional digits, exceeds the
existing Core `DECIMAL(12,2)` and `DECIMAL(15,2)` business ranges, and matches
the SAR minor unit without introducing sub-halalah ledger amounts.

`float`, `double`, JavaScript number arithmetic, and binary approximate equality
are forbidden for posting calculations.

### 9.2 Boundary format

- Inputs and outputs carry canonical fixed-decimal strings with exactly two
  fractional digits. The nonnegative lexical shape is
  `^(0|[1-9][0-9]{0,16})\.[0-9]{2}$`; examples are `"0.00"` and
  `"1250.00"`. Leading plus/minus signs, exponent notation, grouping
  separators, leading zeroes, and omitted/extra fractional digits are rejected.
- The Accounting Amount value object uses `brick/math` `BigDecimal` for every
  in-process parse, scale check, addition, and comparison. Implementation must
  add `brick/math` as a direct Composer production dependency; its presence only
  as a transitive locked package at the inspected baseline is not sufficient.
- Scale conversion uses an exact/no-rounding mode. A value that cannot be
  represented at scale 2 is rejected rather than rounded.
- The domain rejects more than two fractional digits; it does not silently round
  a submitted ledger amount. PostgreSQL `NUMERIC(19,2)` itself rounds during type
  coercion, so direct-SQL tests must document that engine behavior and supported
  write paths must never depend on it as an input policy.
- The domain canonicalizes accepted zero to `0.00` but the XOR CHECK prevents a
  Line from having zero on both sides.
- Business Modules own any calculation required to derive their Lines and must
  declare a reviewed rounding rule before reaching the boundary.
- PostgreSQL independently recomputes posting totals with `SUM(NUMERIC)`. PHP
  never casts an amount to `float` or `double`.

### 9.3 Zero-total Journal

A balanced Journal whose debit and credit totals are both zero is forbidden.
With the row XOR and minimum-two-lines rules this is already unreachable, but
the posting aggregate validation checks positive total explicitly.

## 10. Reversal architecture

### 10.1 Exact reversal only

A reversal Journal must have:

- the same Tenant as its target;
- `origin='reversal'`;
- source type `journal_entry`;
- `source_id = reverses_journal_entry_id = target.id`;
- a nonblank reason;
- a target ID different from its own Journal ID;
- the same Line count;
- the same line numbers and Account IDs;
- for every line number:
  - reversal debit = target credit;
  - reversal credit = target debit.

Line memo may contain a generated reversal explanation, but no monetary or
Account variation is allowed.

Partial reversal is **DEFERRED**. A partial correction is a separately justified
manual/business adjustment Journal, not a Journal marked as a reversal.

### 10.2 Relationship cardinality

A partial unique index on `(tenant_id,reverses_journal_entry_id)` where target is
not null permits at most one direct reversal for a Journal.

Reversal-of-reversal is allowed. Because each node has at most one direct child
and a reversal can target only an already Posted immutable Journal, the graph is
a chronological linear chain. Branching and cycles are impossible under the
posting and uniqueness rules.

The Reversal Action requires the selected target to be the current terminal
Journal in its chain. Attempting to reverse a Journal that already has a direct
reversal returns `journal_already_reversed`.

### 10.3 Date and Period

- Reversal `entry_date` is explicit.
- It must be greater than or equal to the target `entry_date`.
- It resolves to an open Period exactly like every posting.
- A Journal in a closed Period can be reversed in a later open Period.
- Posting back into a closed Period is forbidden; reopening requires the
  separate privileged Period action.

### 10.4 Archived Accounts

Archived Accounts are eligible only for an exact reversal. Exact line matching
prevents using this exception to post new economic composition. The Accounts are
still locked and must remain same-Tenant posting Accounts.

### 10.5 Original state

The target Journal remains `posted`. There is no mutable `reversed_at`,
`reversed_by`, or `status='reversed'` field on it. Its direct reversal is found
by the immutable relationship and indexed query. `journal.reversed` audit
records the actor/reason/reversal ID as control provenance.

## 11. Manual Journal posting sequence

```mermaid
sequenceDiagram
    participant C as Client
    participant A as Manual Journal Action
    participant P as Posting Engine
    participant D as PostgreSQL
    participant U as Audit Recorder
    C->>A: Post Draft journal ID
    A->>D: BEGIN; lock active membership
    A->>P: post locked manual Draft
    P->>D: Lock Journal, Lines, Period, Accounts
    P->>D: Validate balance, state, Tenant, date
    P->>D: Lock JRN counter; allocate number
    P->>D: UPDATE draft to posted
    D-->>P: Posting trigger validates aggregate
    P->>U: Record journal.posted
    U->>D: INSERT append-only audit
    A->>D: COMMIT
    A-->>C: Posted result with number
```

## 12. Business-generated posting sequence

```mermaid
sequenceDiagram
    participant B as Business Action
    participant S as Source Aggregate
    participant P as Posting Engine
    participant D as PostgreSQL
    participant U as Audit Recorder
    B->>D: BEGIN; set bounded lock timeout
    B->>S: Lock source root; validate transition
    B->>D: Persist source transition provisionally
    B->>P: PostBusinessTransaction command
    P->>D: Lock/check source tuple uniqueness
    P->>D: Insert Draft and Lines
    P->>D: Lock Period, Accounts, JRN counter
    P->>D: Validate and update to posted
    P->>U: Record posting audit
    U->>D: INSERT append-only audit
    B->>D: COMMIT source + Journal + audit
    P-->>B: Posted or idempotent-existing result
```

## 13. Reversal sequence

```mermaid
sequenceDiagram
    participant C as Client
    participant A as Reversal Action
    participant P as Posting Engine
    participant D as PostgreSQL
    participant U as Audit Recorder
    C->>A: Target ID, reversal date, reason
    A->>D: BEGIN; lock actor and target Journal
    A->>D: Verify target is Posted and terminal
    A->>D: Lock target Lines; build exact opposites
    A->>P: Post reversal Draft
    P->>D: Lock open Period and Accounts in order
    P->>D: Allocate JRN number; post reversal
    D-->>P: Exactness and uniqueness validated
    P->>U: Record journal.posted + journal.reversed
    U->>D: INSERT append-only audits
    A->>D: Advance opening effect projection if applicable
    A->>D: COMMIT
    A-->>C: Reversal Journal result
```

## 14. Posting result

Successful posting returns an immutable result containing:

- Journal ID;
- Tenant ID;
- status `posted`;
- Journal number;
- entry date and Accounting Period ID;
- origin and source tuple when present;
- posted timestamp;
- `idempotent_replay` boolean for Business posting only.

It does not return or accept a caller-selected Journal number, Period ID, status,
posted timestamp, or actor ID from public request data.

## 15. Failure categories

The public/application error contract distinguishes:

- Accounting inactive or unsupported currency;
- Journal missing/not Draft/already Posted;
- source type unregistered or source conflict;
- no Period, ambiguous Period (integrity failure), or Period closed;
- Account missing, cross-Tenant, group, archived, or structurally invalid;
- invalid Line/XOR/precision/contiguity/minimum;
- unbalanced or zero-total Journal;
- reversal target missing/not Posted/already reversed/date invalid/not exact;
- number allocation failure;
- lock timeout, deadlock victim, or serialization retry exhaustion;
- database integrity violation.

Raw SQL text, table names, source data, and PostgreSQL diagnostics are never
returned to callers. Named constraint/SQLSTATE translation is specified in
`70_ACCOUNTING_INTEGRITY_AND_CONCURRENCY_v1.md`.
