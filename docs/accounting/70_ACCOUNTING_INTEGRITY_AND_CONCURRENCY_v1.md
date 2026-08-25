# Accounting Foundations v1 — PostgreSQL Integrity and Concurrency

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Principle

Application validation provides useful failures. PostgreSQL remains the final
authority for structural, tenant, lifecycle, uniqueness, immutable-history, and
posting invariants.

No critical invariant relies on:

- a read followed by an unlocked write;
- frontend state;
- Laravel mass-assignment protection;
- `MAX()+1`;
- a log message;
- a deferred trigger pretending to be a lock;
- an assumption that only one worker runs.

## 2. PostgreSQL baseline

PostgreSQL is verified as the official Core engine and as the required test
engine. The exact deployed server version is **NOT VERIFIED** at the inspected
baseline.

Implementation preflight must execute and record:

```sql
SHOW server_version;
SHOW default_transaction_isolation;
SHOW lock_timeout;
SHOW statement_timeout;
```

Accounting migrations fail before schema mutation unless the active Laravel
driver is `pgsql`.

No optional extension is required by v1. Range overlap uses a serialized trigger
rather than `btree_gist`.

## 3. Integrity ownership matrix

| Invariant | Application only | Domain + Application | DB mechanism | Why |
|---|---:|---:|---|---|
| authenticated actor and capability | Yes | Yes | — | Authorization is contextual, not a row invariant |
| actor had same-Tenant membership identity | — | precheck | composite FK to `tenant_users(tenant_id,user_id)` | Prevent cross-Tenant historical actor persistence |
| active User/membership at command time | — | lock and validate | — | Lifecycle can later change; an FK cannot encode time-of-action state |
| Accounting activated | — | validate | FK from aggregate Tenant to `accounting_settings(tenant_id)` | Direct inserts cannot precede activation |
| SAR ledger | — | validate source | Settings CHECK + Tenant currency mutation trigger | Prevent non-SAR activation/change |
| Account code vocabulary/uniqueness | — | normalize/precheck | CHECK + Tenant UNIQUE | Authoritative against concurrency |
| Account parent same Tenant | — | scoped lookup | composite FK | Structural tenant safety |
| Account parent group/same type | — | hierarchy policy | trigger | Cross-row property |
| Account self-parent/cycle | — | precheck | CHECK + serialized recursive trigger | Concurrent/direct hierarchy protection |
| Account structural history freeze | — | policy under locks | trigger querying Posted Lines | Prevent historical report rewrite |
| Account archive/restore coherence | — | lifecycle policy | CHECK + trigger | Direct mutation protection |
| Period date order/status fields | — | validate | CHECK | Row-local |
| Period non-overlap | — | precheck under parent lock | serialized overlap trigger | Cross-row and concurrency-safe |
| Period boundary history freeze | — | policy under lock | trigger | Posted Period assignment cannot change |
| Period open at posting | — | posting policy under lock | posting trigger | Close/post race protection |
| Draft/Posted header pairs | — | state policy | CHECK + transition trigger | Direct persistence protection |
| direct INSERT as Posted forbidden | — | always insert Draft | insert trigger | One validated posting path |
| Business/reversal Draft cannot survive commit | — | post in creating transaction | deferred Journal final-state trigger | Prevent stale system Draft/source reservation while preserving Draft-first posting |
| Business/reversal Draft explicit delete | — | no delete path | Journal delete trigger | Rollback, not a committed delete, is the only failed-construction cleanup |
| Line debit XOR credit | — | value validation | CHECK | Every Draft/Posted row valid |
| same-Tenant Journal/Account Line | — | scoped lookup | composite FKs | No cross-Tenant ledger relation |
| Line number uniqueness | — | normalize | UNIQUE | Concurrent/direct protection |
| minimum/contiguous Lines | — | aggregate validation | posting trigger | Cross-row aggregate |
| exact balance and positive total | — | aggregate validation | posting trigger | Ledger integrity |
| Posted header/Lines immutable | — | no mutation path | update/delete/child-state triggers | Historical truth |
| Period assigned by entry date | — | resolve under lock | posting trigger + composite FK | Preserve date/control truth |
| Journal number shape/uniqueness | — | allocator | CHECK + UNIQUE | Counter mistakes cannot persist |
| source vocabulary | — | internal command enum | composite FK to immutable source catalog | No arbitrary provenance string |
| source idempotency | — | retry resolution | partial UNIQUE | Authoritative duplicate prevention |
| one direct reversal | — | target precheck | partial UNIQUE | Prevent branching/race |
| reversal exactness/date/target status | — | reversal policy | posting trigger | Prevent disguised adjustment |
| archived Account exception only for exact reversal | — | origin policy | posting trigger | No new ordinary activity |
| Opening one Draft-or-effective slot | — | parent-lock precheck | partial UNIQUE | Authoritative concurrent uniqueness |
| Opening historical effect-date floor | — | compare under Settings lock | immediate guard + deferred constraint trigger | Prevent backdated overlap across replacement/reactivation |
| Opening root/source/date/status consistency | — | operation policy | deferred constraint trigger | Validate final two-aggregate transaction state |
| Opening effect terminal/parity | — | advance under locks | deferred constraint trigger | Projection cannot lie about ledger chain |
| Accounting audit append-only | — | no mutation path | update/delete triggers | Control history |
| audit context object | — | event schema | JSONB CHECK | Reject non-object context |
| required audit event accompanies Action | Yes | mandatory recorder contract | same PostgreSQL transaction supplies atomic rollback, but no generic event-completeness constraint | Event context/reason is use-case data; this follows the verified Core audit convention without duplicating domain logic in triggers |

## 4. Constraint strategy

### 4.1 CHECK constraints

CHECK is used for row-local truths:

- vocabularies;
- lifecycle actor/time field coherence;
- nonblank normalized strings;
- amount precision/sign/XOR;
- positive sequence/line numbers;
- date ordering;
- origin/source/reversal nullable shapes;
- Draft/Posted field pairs;
- Account type/classification/kind matrix;
- SAR-only Settings.

CHECK does not query other rows or tables.

### 4.2 Composite foreign keys

Every tenant-owned parent exposes non-deferrable `UNIQUE (tenant_id,id)`.
Children store Tenant ID and reference both columns. Actor fields reference
`tenant_users(tenant_id,user_id)`.

This is used even though Account/Journal ULIDs are globally unique. It proves
Tenant consistency in the relationship itself.

All Accounting parent and actor FKs use `ON UPDATE RESTRICT ON DELETE RESTRICT`,
except owned Draft Journal Lines may be removed by explicit aggregate deletion.
Posted-delete triggers prevent cascade from becoming a historical-delete path.

### 4.3 UNIQUE and partial UNIQUE

Normal UNIQUE constraints enforce:

- one Settings row per Tenant;
- Account code per Tenant;
- `(tenant_id,id)` composite FK targets;
- Period/Journal relationships where one-to-one;
- Journal number and year/sequence;
- Journal line number within Journal.

Partial unique indexes enforce state-qualified truths:

- non-manual source tuple uniqueness;
- one direct reversal per target Journal;
- one Draft-or-effective Opening Balance slot per Tenant.

Application `exists()` checks are user experience only.

## 5. Trigger strategy

### 5.1 Immediate triggers

Immediate triggers enforce facts that must be valid at the current statement:

- Settings update/delete immutability;
- Tenant currency freeze after activation;
- Account hierarchy and lifecycle/history rules;
- Period overlap/lifecycle/history rules;
- Journal insert/transition/immutability rules;
- Journal Line parent-state and Posted immutability rules;
- source-catalog update/delete immutability;
- audit update/delete immutability.

The Journal posting trigger fires on `UPDATE status draft → posted` after all
Lines and posting fields have been prepared. It locks/rechecks relevant parent
rows and raises a named integrity exception before invalid ledger truth commits.

### 5.2 Deferred constraint triggers

`DEFERRABLE INITIALLY DEFERRED` constraint triggers are limited to two final
transaction-state problems:

1. a Journal final-state guard rejects any committed `business` or `reversal`
   Draft; and
2. OpeningBalanceOperation cross-table consistency validates:

   - Operation ↔ root Journal source/date/status;
   - Posted effect fields;
   - latest chain terminal and parity;
   - valid projection advance.

Both Operation and Journal mutations schedule the Opening validator so changing
either side cannot escape final validation.

Deferred triggers are validation, not concurrency control. Every competing
Opening path acquires the same AccountingSettings parent lock and relevant row
locks before mutation.

### 5.3 Trigger error contract

Trigger functions raise a stable SQLSTATE/constraint name. Application exception
translation maps these to semantic errors. Trigger messages are diagnostic and
are not exposed raw.

### 5.4 Privileged bypass

PostgreSQL superusers and replication settings can bypass ordinary application
protections. Production application credentials must not own tables, disable
triggers, truncate Accounting tables, or execute migration functions.

Privileged repair is an operational incident/migration process, not a supported
Accounting command.

## 6. Transaction isolation

Default `READ COMMITTED` plus explicit row/coordination locks is sufficient for
the frozen invariants. v1 does not require all Accounting traffic to run at
`SERIALIZABLE`.

The architecture does not depend on a pre-read snapshot staying unchanged.
Every mutable decision is re-read under the specified locks, and uniqueness/FKs
remain authoritative.

## 7. Global lock-order contract

Not every operation uses every level. When it uses multiple levels, it acquires
them in this order:

1. **Actor membership and User rows** — membership first, then User, when a
   financial command locks current authorization state.
2. **Owning Business source aggregate root** — Business-generated posting only.
3. **Tenant row** — Accounting activation/currency mutation only.
4. **AccountingSettings coordination row** — hierarchy/range/opening-slot
   operations only.
5. **Existing Journal target/root rows** — deterministic role then ULID order.
6. **OpeningBalanceOperation** — when an opening chain is involved.
7. **Existing Journal Lines** — `line_number,id` order.
8. **AccountingPeriod** — the resolved/specified Period row.
9. **Accounts** — distinct IDs in lexical ULID order.
10. **BusinessNumberSequence** — exact Tenant/`JRN`/year row.
11. **New writes and AccountingAudit inserts** — append after validation.

Newly inserted rows do not require a prior row lock, but their creation must not
change the relative order of existing locks.

An operation that does not need a higher-level lock must not acquire it later
after a lower level. For example, Period close locks its Period and never then
tries to lock AccountingSettings.

## 8. Operation lock protocols

### 8.1 Manual Journal posting

```text
actor membership and User
→ Draft Journal
→ Lines ordered
→ resolved Period
→ Accounts sorted
→ JRN row
→ posting/audit writes
```

### 8.2 Business-generated posting

```text
actor membership and User
→ source aggregate root
→ insert Journal/Lines as Draft
→ resolved Period
→ Accounts sorted
→ JRN row
→ posting/audit writes
```

The owning module must not lock an Accounting Account/Period first and later
return to its source root. That reverse order is forbidden.

### 8.3 Reversal

For a non-opening chain:

```text
actor membership and User
→ target Journal
→ target Lines
→ resolved reversal Period
→ Accounts sorted
→ JRN row
→ new reversal/audit writes
```

For an opening chain, actor membership/User precede AccountingSettings; Settings
is acquired before the target Journal, then the Operation follows the target.

### 8.4 Period create/boundary edit

```text
actor membership and User
→ AccountingSettings
→ Period when existing
→ Posted-history query
→ overlap query
→ write/audit
```

### 8.5 Period close/reopen

```text
actor membership and User
→ Period
→ state write/audit
```

Posting and close/reopen share the Period lock.

### 8.6 Account create/structural/archive/restore

```text
actor membership and User
→ AccountingSettings
→ affected Account/subtree rows sorted by ULID
→ parent/ancestor rows sorted when not already included
→ Posted-history/hierarchy check
→ write/audit
```

Posting shares the referenced Account row locks. A hierarchy trigger independently
locks/validates the coordination parent for direct writes.

### 8.7 Opening Balance slot operations

```text
actor membership and User
→ AccountingSettings
→ target/root Journal
→ OpeningBalanceOperation
→ Lines
→ Period
→ Accounts sorted
→ JRN row
→ effect and audit writes
```

Creation uses the Settings row before inserting new Operation/Journal rows.

### 8.8 Number allocation

The caller has already acquired all semantic locks. It then:

1. inserts the counter row if absent using the existing unique key;
2. selects exact `(tenant_id,'JRN',entry-year)` `FOR UPDATE`;
3. verifies nonnegative state and increments inside the outer transaction with
   PostgreSQL `UPDATE ... RETURNING current_value`;
4. derives the number from the returned bigint string without PHP arithmetic;
5. never decrements/reuses after commit.

PostgreSQL bigint overflow and the explicit nonnegative CHECK fail closed. The
allocator translates exhaustion; it never wraps, resets, or allocates through a
floating-point cast.

## 9. Parent-lock protocols and direct writes

The mandatory parent lock is not merely an application convention:

- Journal Line mutation triggers inspect/lock the Journal parent;
- Period range trigger locks AccountingSettings;
- Account hierarchy trigger locks AccountingSettings;
- Opening slot/effect application paths lock AccountingSettings and deferred
  validation rejects an inconsistent final state.

Direct bulk DML is unsupported. A direct UPDATE obtains a row lock before a
row-level trigger can run and may therefore become a deadlock victim when it
violates the published application lock order. PostgreSQL deadlock detection and
rollback preserve integrity; this is not permission to bypass Actions.

## 10. Deadlock avoidance and deterministic ordering

- Never rely on database-return order; explicitly sort ULIDs.
- Lock each distinct Account once.
- Lock Journal Lines by `line_number,id`.
- For more than one Journal, use semantic role first (target before owned root),
  then ULID within the role.
- Source-module documentation must publish its source-root lock order before
  enabling Accounting integration.
- Do not call external APIs, queues, email, filesystem, or user callbacks while
  holding Accounting locks.
- Build and validate input outside the transaction when it does not depend on
  mutable database state.

## 11. Bounded waits

Each Accounting write transaction sets a transaction-local lock timeout. The v1
default is 5 seconds:

```text
SET LOCAL lock_timeout = '5s'
```

It is application configuration, not a global PostgreSQL setting. A deployment
may lower it after load testing; raising it requires an operational review so a
web request cannot wait indefinitely.

The existing environment's `statement_timeout` is NOT VERIFIED. Implementation
must ensure any statement timeout is greater than lock timeout and still bounded
for web traffic.

## 12. Retry behavior

The outermost transaction owner is the only retry owner.

| Failure | SQLSTATE example | Retry policy |
|---|---|---|
| deadlock victim | `40P01` | retry complete transaction, maximum 3 total attempts, jittered backoff |
| serialization failure | `40001` | same complete-transaction retry policy |
| lock timeout / lock unavailable | `55P03` | abort and return retryable concurrency conflict; no hidden same-request loop |
| unique idempotency collision | `23505` | resolve existing source Journal; do not generic-retry |
| FK/CHECK/trigger integrity | `23...` or named custom state | translate semantic failure; do not retry |
| connection loss / unknown commit | driver-specific | caller retries whole idempotent Business command; source tuple determines committed result |

Nested helpers, including number allocation, cannot independently retry or
commit. A Business-generated posting retry repeats source locking, transition
validation, Journal resolution, and audit as one unit.

## 13. Transaction boundaries

### Accounting-owned transactions

- Accounting activation;
- Account lifecycle/structure;
- manual Draft mutations/posting;
- reversal;
- Period lifecycle/ranges;
- Opening Balance lifecycle.

### Caller-owned transaction

Business-generated posting is invoked inside the owning Business Action's
transaction on the same PostgreSQL connection. Accounting must detect and reject
use outside an active transaction for this internal method.

No after-commit queue is allowed to provide required ledger atomicity in v1.

## 14. Constraint and error translation

Implementation maintains a closed map from named Accounting constraints/triggers
to domain error codes. Unknown integrity failures become an internal
`accounting_integrity_violation` with server-side correlation logging.

The map must cover at least:

- Account code/hierarchy/lifecycle/history;
- Period overlap/state/history;
- Journal state/source/number;
- Line XOR/number/tenant;
- posting balance/account/period;
- reversal uniqueness/exactness;
- Opening slot/effect consistency;
- audit immutability;
- actor membership FKs.

Pre-check and database-race failure for the same invariant return the same
public semantic code.

## 15. Tenant isolation

Tenant isolation is layered:

1. active membership resolves Tenant context;
2. every application query scopes by Tenant;
3. every Accounting root directly references Tenant and activated Settings;
4. every cross-root/child relationship uses a composite Tenant FK;
5. actors use same-Tenant membership composite FKs;
6. posting triggers recheck Tenant on Accounts, Period, source shape, and
   reversal target;
7. database constraint tests attempt direct cross-Tenant writes.

Row-Level Security is **DEFERRED**. The current Core does not use RLS, and adding
it only for Accounting would create an unreviewed connection/session policy.
Composite constraints remain mandatory if RLS is adopted later.

## 16. Audit and rollback

Required audit rows are inserted before commit on the same connection.

- If audit insert fails, the business/Accounting mutation rolls back.
- If posting fails, no successful-business audit remains.
- Application log output is secondary and may occur only with transaction-aware
  wording; it is not evidence of commit.
- Audit does not use an after-commit queue.

## 17. Integrity review checklist for migrations

Before a future migration is approved, verify:

- constraint and trigger names are deterministic and within PostgreSQL limits;
- all referenced composite columns have non-partial UNIQUE targets;
- trigger functions schema-qualify tables or run under a controlled search path;
- functions do not trust caller-supplied Tenant/actor values;
- triggers cover INSERT, UPDATE, DELETE, and parent deletion as applicable;
- rollback refuses unsafe history loss rather than silently dropping controls;
- migration preflight detects conflicting existing data before adding a
  constraint;
- all functions/triggers are removed in reverse dependency order in `down`, if a
  safe down migration exists;
- the application role cannot disable triggers or truncate tables;
- real PostgreSQL concurrency tests reproduce each shared-lock race.
