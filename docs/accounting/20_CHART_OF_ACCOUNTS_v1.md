# Accounting Foundations v1 — Chart of Accounts

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Purpose

The Chart of Accounts supplies stable ledger identities and reporting placement.
It is not a configurable accounting-rules engine. Account behavior is explicit
and deliberately small.

## 2. Account model

An Account contains only:

- ULID identity and Tenant ownership;
- unique canonical code;
- human name and optional description;
- `kind` (`group` or `posting`);
- one of five Account types;
- a type-compatible reporting classification for posting Accounts;
- optional parent Account;
- lifecycle state and actor/time metadata;
- creation/update metadata.

It does not contain an opening balance, cached balance, currency, normal-balance
column, magic integration role, tax treatment, cost center, or external rule.

## 3. Account Type

The closed v1 vocabulary is:

```text
asset
liability
equity
revenue
expense
```

These types are sufficient for double-entry sign convention and the top-level
financial statements. They are not expanded into `cash`, `receivable`,
`payable`, or `bank`; those concepts are account roles or classifications, not
fundamental Account types.

Normal display side is derived, not stored:

| Account Type | Normal side | Display balance formula |
|---|---|---|
| asset | debit | debit minus credit |
| expense | debit | debit minus credit |
| liability | credit | credit minus debit |
| equity | credit | credit minus debit |
| revenue | credit | credit minus debit |

A Line is not rejected merely because it posts to the non-normal side. A contra
or correction balance can therefore be represented without a speculative
`normal_balance_override` field.

## 4. Account Classification

### 4.1 Meaning

Account Type describes the fundamental accounting family. Classification gives
a posting Account its v1 financial-statement placement.

Classification is not an Account role. For example, Accounts Receivable and a
cash Account are both `current_asset`; a future Receivables module chooses its
control Account through an explicit Settings FK, not by searching for a magic
classification or code.

### 4.2 Closed vocabulary and valid mapping

| Account Type | Allowed posting classification |
|---|---|
| `asset` | `current_asset`, `non_current_asset` |
| `liability` | `current_liability`, `non_current_liability` |
| `equity` | `equity` |
| `revenue` | `operating_revenue`, `other_revenue` |
| `expense` | `cost_of_revenue`, `operating_expense`, `finance_cost`, `other_expense` |

Rules:

- a posting Account must have exactly one classification;
- a group Account must have `classification = NULL`;
- Account Type is mandatory for both kinds;
- a CHECK constraint enforces the type/classification matrix;
- adding a future classification requires an architecture decision and forward
  migration; arbitrary values are rejected.

This vocabulary is sufficient for Trial Balance, Balance Sheet, and Income
Statement grouping without pretending that detailed report presentation has
already been designed.

## 5. Account Code

### 5.1 Canonical form

- maximum length: 32 characters;
- stored after trim and uppercase normalization;
- allowed shape:

```text
^[A-Z0-9]+([._-][A-Z0-9]+)*$
```

- unique across all active and archived Accounts within one Tenant;
- reusable across different Tenants;
- never generated with `MAX()+1`;
- not interpreted as the hierarchy.

Valid examples include `1000`, `1101.01`, and `BANK-SAR`. Spaces, empty
segments, controller names, and locale-dependent characters are rejected.

### 5.2 Stability

Code may be corrected only before the Account/subtree has Posted History. Once
history exists, code is immutable. Archiving does not release it. This keeps
imports, reports, and external references stable.

The explicit `parent_id` relationship, not code prefix, determines hierarchy.
Renumbering a parent does not silently move descendants.

## 6. Hierarchy

### 6.1 Frozen rules

1. `parent_id` is nullable; null means a root.
2. Parent and child must have the same `tenant_id`.
3. Parent and child must have the same `account_type`.
4. Parent must have `kind = group`.
5. An active Account must have only active ancestors; an archived Account may
   remain under an active or archived parent.
6. A posting Account cannot have children.
7. `parent_id <> id`.
8. The graph must be acyclic.
9. A group may be temporarily empty.
10. Hierarchy depth is not given an arbitrary numeric limit in v1.
11. Posting/reporting queries use a recursive CTE and must protect against an
    unexpected cycle even though the database rejects cycles.

### 6.2 Cycle prevention

Simple CHECKs cannot inspect ancestor rows. PostgreSQL therefore uses a
hierarchy trigger that:

1. acquires the Tenant's `accounting_settings` coordination row;
2. verifies the same-Tenant composite parent reference;
3. locks the selected parent;
4. verifies parent kind and matching type;
5. rejects an archived ancestor for an active Account;
6. walks ancestors with a recursive CTE;
7. rejects when the moving Account appears in the ancestor path.

Every application structural mutation acquires the same coordination row before
locking affected Accounts. This serializes competing hierarchy rewrites for one
Tenant, preventing two individually valid concurrent changes from committing a
cycle.

Deferred constraint triggers are not used as locks. The hierarchy check is
immediate; the coordination/row locks provide concurrency serialization.

### 6.3 Moving a subtree

Changing `parent_id` moves the Account and all descendants. It is allowed only
when no posting Account in the moved subtree has any Posted Journal Line.

Adding a newly created, history-free child beneath a group with existing history
is allowed because it does not reclassify an existing Line.

## 7. Posting versus group Accounts

| Behavior | Group | Posting |
|---|---:|---:|
| May be parent | Yes | No |
| May have Journal Lines | No | Yes, while active |
| Has classification | No | Yes |
| Balance | Derived from descendant posting Accounts | Derived from own Posted Lines |
| May be empty | Yes | N/A |
| Archive with active descendants | No | N/A |

The posting engine locks every referenced Account and verifies `kind=posting`.
A foreign key alone is insufficient because it cannot enforce kind or current
lifecycle state.

## 8. Lifecycle

### 8.1 Create

Create always produces an active Account. The command requires:

- active AccountingSettings;
- active authorized actor and same-Tenant membership;
- canonical unique code;
- nonblank name;
- valid kind/type/classification combination;
- valid active-parent/ancestor chain and acyclic hierarchy.

### 8.2 Edit

Descriptive edit consists of `name` and `description` only. It is allowed on an
active Account even after Posted History and is always audited.

Structural edit consists of:

- `code`;
- `kind`;
- `account_type`;
- `classification`;
- `parent_id`.

It is allowed only on an active Account and only when the affected Account
subtree has no Posted History. Further rules:

- group → posting requires no children and a valid classification;
- posting → group requires no Posted History and clears classification;
- type change is allowed only for a history-free posting leaf or an empty
  history-free group; a group with children must first move its history-free
  children through valid same-type parent changes, then change while empty;
- parent change follows the move rules above.

Bulk structural changes are not a generic endpoint. Each committed state must
remain hierarchy-valid; the design does not rely on temporarily mixed parent and
child types inside a transaction.

### 8.3 Archive

Archive is lifecycle state, not deletion.

- An active posting Account may be archived even with Posted History.
- An active group may be archived only when it has no active descendant.
- Archived Accounts are excluded from manual, business, and opening-balance new
  postings.
- An exact reversal may use an archived posting Account.
- Existing reports continue to include it.
- Code remains reserved.

Posting and archive lock the same Account row. If posting wins first, archive
observes the new history and may still archive after the posting. If archive wins
first, ordinary posting observes archived state and fails. No posting commits
using an Account that was already archived when its lock was obtained.

### 8.4 Restore

Restore changes an archived Account to active only when every ancestor is
active. A group is restored before its descendants. Restore does not modify
history or automatically restore children.

### 8.5 Delete

Account deletion is forbidden in v1, including an unused Account. A mistaken
unused Account is archived. This preserves its creation/control audit and keeps
code history unambiguous.

## 9. Historical immutability matrix

| Field / relationship | Before Posted History | After Account or affected subtree has Posted History | While archived |
|---|---:|---:|---:|
| `code` | editable through structural Action | immutable | immutable |
| `name` | editable | editable with audit | immutable until restore |
| `description` | editable | editable with audit | immutable until restore |
| `kind` | conditionally editable | immutable | immutable |
| `account_type` | conditionally editable | immutable | immutable |
| `classification` | conditionally editable | immutable | immutable |
| `parent_id` | conditionally editable | immutable for a subtree with history | immutable |
| `status` | archive allowed | archive/restore allowed | restore allowed |
| ID / Tenant / creation actor/time | immutable | immutable | immutable |

"Existing Posted History" means at least one `journal_line` whose parent
Journal has `status='posted'`. Draft Lines do not freeze Account structure.

## 10. Layer responsibility

| Invariant / behavior | Database | Domain | Application |
|---|---|---|---|
| Required values and vocabularies | NOT NULL + CHECK | typed enums/value validation | request parsing |
| Code canonical shape and Tenant uniqueness | regex CHECK + UNIQUE | normalization and semantic error | validation response |
| Same-Tenant parent | composite FK | scoped lookup | passes Tenant only from active membership |
| Same-type/group parent | trigger | hierarchy policy | orchestrates locks |
| Active child has active ancestors | hierarchy/lifecycle trigger | lifecycle policy | ancestor locks and precheck |
| Self-parent/cycle | CHECK + trigger | preflight policy | coordination-row lock and retry translation |
| Posting Account eligibility | posting trigger | posting policy | locks referenced Accounts in sorted ID order |
| Structural history freeze | trigger querying Posted Lines | structural-change policy | coordination/subtree locks |
| Archive/restore rules | lifecycle trigger + CHECK | lifecycle policy | transaction, actor, audit |
| Authorization | not a database role decision | AccountingAuthorization | authenticated boundary and 403 mapping |
| Audit | append-only table/trigger | event/context contract | writes in same transaction |

Application validation gives useful errors. It is not authoritative against
concurrent or direct persistence.

## 11. Account selection by future modules

Future modules must reference configured Account IDs through named,
Tenant-composite foreign keys. They must not search by:

- magic code such as `110101`;
- Account name;
- classification alone;
- tree position;
- first Account of a type.

An approved module may add Settings references such as a receivables control
Account or default bank Account. That migration must define required Account
type/kind/lifecycle checks and archive behavior. No such field exists in v1.

## 12. Query and index requirements

Required access paths are:

- unique Tenant/code lookup;
- Tenant/status/kind list filtering;
- Tenant/parent hierarchy traversal;
- Tenant/type/classification reporting filter;
- Journal-history existence by Tenant/Account through Journal Lines.

Indexes are listed by name in `97_ACCOUNTING_DDL_PLAN_v1.md`. No stored nested
set, materialized path, or denormalized hierarchy is introduced.
