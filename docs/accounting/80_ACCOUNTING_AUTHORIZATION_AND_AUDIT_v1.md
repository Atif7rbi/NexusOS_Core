# Accounting Foundations v1 — Authorization and Audit

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Existing authorization reality

The inspected Core has:

- global role values on `users`;
- active Tenant membership resolution;
- module-specific authorization Support classes;
- shared `TenantAdministratorAuthority` for `system_owner` and
  `administrator`;
- an existing `accountant` role;
- no persisted permissions/role-permissions schema in the baseline.

Accounting v1 reuses that pattern. It does not build a permission framework.

Every Accounting command first requires:

1. active User status;
2. active Tenant status;
3. active same-Tenant membership;
4. an allowed current role/capability;
5. a membership/User recheck under lock for financial state transitions.

`system_owner` does not bypass membership evidence. If acting within a Tenant,
that User must have an active membership resolvable by the normal boundary.

## 2. Semantic capabilities

Accounting defines stable capability names even though v1 maps them to current
roles. This keeps use cases explicit and allows a future RBAC migration without
changing domain Actions.

| Capability | Tenant administrator authority | Accountant | Other roles |
|---|---:|---:|---:|
| `accounting.view_ledger` | Yes | Yes | No |
| `accounting.manage_chart` | Yes | Yes | No |
| `accounting.create_manual_draft` | Yes | Yes | No |
| `accounting.edit_manual_draft` | Yes | Yes | No |
| `accounting.delete_manual_draft` | Yes | Yes | No |
| `accounting.post_journal` | Yes | Yes | No |
| `accounting.reverse_journal` | Yes | Yes | No |
| `accounting.close_period` | Yes | Yes | No |
| `accounting.reopen_period` | Yes | No | No |
| `accounting.manage_opening_balance` | Yes | Yes | No |
| `accounting.manage_settings` | Yes | No | No |

`Tenant administrator authority` means the existing shared authority class,
subject to active membership.

The v1 mapping intentionally does not implement maker/checker separation or an
approval engine. Those controls require a separate approved workflow.

## 3. Business-generated posting authorization

`PostBusinessTransaction` is not an HTTP/controller permission and cannot be
invoked by a generic user endpoint.

- The owning Business Action authenticates and authorizes its transition.
- It locks the source aggregate and supplies the already-authorized actor.
- Accounting revalidates active same-Tenant actor identity, source registration,
  and all ledger invariants.
- Accounting does not reinterpret whether the actor was allowed to post the
  Payment/Expense/Vendor Bill; that remains the owning module's rule.
- The method is an internal application service and requires an active outer
  database transaction.

An automated source without a human actor is **DEFERRED** until NexusOS defines a
durable service-principal identity. v1 does not use `0`, a fake User, or a null
actor as historical evidence.

## 4. Authorization component

One `AccountingAuthorization` Support component exposes methods corresponding
to the capability names. Controllers and Actions do not duplicate role arrays.

Responsibilities:

- map current roles through `TenantAdministratorAuthority` and
  `User::ROLE_ACCOUNTANT`;
- require the correct capability;
- support response affordances without becoming the final transactional check;
- throw one Accounting authorization exception translated to 403.

The Action still locks and rechecks membership/User state because authorization
may change between request validation and mutation.

## 5. Actor foreign-key semantics

### 5.1 Frozen rule

All Accounting actor references are historical/control evidence and use:

```text
FOREIGN KEY (tenant_id, actor_id)
REFERENCES tenant_users (tenant_id, user_id)
ON UPDATE RESTRICT
ON DELETE RESTRICT
```

This is intentionally stricter than older operational `updated_by SET NULL`
conventions.

### 5.2 Field matrix

| Entity | Actor field | Meaning | Nullability | Delete behavior |
|---|---|---|---:|---|
| AccountingSettings | `activated_by` | enabled Accounting | never null | RESTRICT |
| Account | `created_by` | created master identity | never null | RESTRICT |
| Account | `updated_by` | performed most recent allowed edit | never null | RESTRICT |
| Account | `archived_by` | current archive transition | required only archived | RESTRICT |
| Account | `restored_by` | current restored-active transition | paired when applicable | RESTRICT |
| JournalEntry | `created_by` | created Draft/transaction-local Journal | never null | RESTRICT |
| JournalEntry | `updated_by` | last Draft edit or posting actor | never null | RESTRICT |
| JournalEntry | `posted_by` | made ledger truth | required Posted | RESTRICT |
| AccountingPeriod | `created_by` | created range | never null | RESTRICT |
| AccountingPeriod | `updated_by` | most recent boundary/state mutation | never null | RESTRICT |
| AccountingPeriod | `closed_by` | current close transition | required closed | RESTRICT |
| AccountingPeriod | `reopened_by` | current reopen transition | required reopened-open | RESTRICT |
| OpeningBalanceOperation | `created_by` | created initial operation | never null | RESTRICT |
| OpeningBalanceOperation | `updated_by` | last Draft editor/poster | never null | RESTRICT |
| OpeningBalanceOperation | `posted_by` | posted root Journal | required Posted | RESTRICT |
| OpeningBalanceOperation | `effect_updated_by` | advanced current reversal parity | required Posted | RESTRICT |
| AccountingAudit | `actor_id` | performed audited action | never null | RESTRICT |

Operational lifecycle removes access by changing User/membership status. It does
not delete evidence. If a privileged physical User deletion is attempted after
Accounting history exists, restrictive FKs correctly block it.

## 6. Ledger truth versus audit truth

| Concern | Authoritative record |
|---|---|
| money, debit/credit, Account, date, Period, number, source | Posted Journal and Lines |
| current Account label/hierarchy allowed by history rules | Account |
| current Period open/closed state | AccountingPeriod |
| current initial-opening effect projection | OpeningBalanceOperation, validated against Journal chain |
| who/why/control transition context | AccountingAudit plus entity actor fields |

Audit cannot repair, cancel, or override a Journal. Reports never sum audit
context.

## 7. AccountingAudit model

Each row contains:

- ULID `id`;
- `tenant_id` with direct Tenant/AccountingSettings ownership;
- closed `event` key;
- closed `subject_type`;
- ULID `subject_id`;
- required same-Tenant `actor_id`;
- `context JSONB NOT NULL DEFAULT '{}'::jsonb`;
- database-assigned `recorded_at TIMESTAMPTZ`.

There are no `updated_at`, `deleted_at`, Line snapshots, IP-address requirement,
generic URL, PHP class, controller, or mutable delivery status fields.

### 7.1 Subject vocabulary

```text
accounting_settings
account
journal_entry
accounting_period
opening_balance_operation
```

A trigger maps subject type to its table, verifies that the subject belongs to
the same Tenant when the audit is inserted, and verifies that the event is valid
for that subject type.

Generic polymorphic FKs do not exist in PostgreSQL, so this trigger is the
database tenant-integrity mechanism at insertion. Subject deletion is forbidden
except for the named Draft aggregate deletion use cases. The deletion event is
inserted while the Draft still exists; after deletion, it and every earlier
audit row for that Draft intentionally retain the former ULID. Audit rows are
never deleted merely because their permitted Draft subject was deleted.

### 7.2 Context contract

- must be a JSON object;
- is event-shaped and created by Accounting domain code;
- contains only control facts needed to explain the transition;
- stores money as fixed-decimal strings;
- excludes passwords, tokens, raw requests, private infrastructure, and complete
  Journal Lines;
- keys are stable snake_case names.

The database enforces object shape and event/subject compatibility. The domain
validates required keys and types for each event.

## 8. Required event matrix

### 8.1 AccountingSettings

| Event | Required context |
|---|---|
| `accounting.activated` | `settings_id`, `ledger_currency` |

`settings.changed` is reserved, not emitted in v1 because Settings is immutable.

### 8.2 Account

| Event | Required context |
|---|---|
| `account.created` | `code`, `kind`, `account_type`, `classification`, `parent_id` |
| `account.updated` | `changed_fields`, `before`, `after` for changed fields only |
| `account.archived` | `code`, optional normalized `reason` when UI supplies one |
| `account.restored` | `code` |

An Account description/name change is included in changed fields. No posting
amount is present.

### 8.3 Journal

| Event | Required context |
|---|---|
| `journal.draft_created` | manual origin and `entry_date` |
| `journal.draft_deleted` | manual origin, `entry_date`, normalized deletion reason when supplied |
| `journal.posted` | `journal_number`, `entry_date`, `accounting_period_id`, `origin`, source tuple, `line_count`, `total_debit` |
| `journal.reversed` | target Journal ID/number, reversal Journal ID/number, reversal reason/date |

`journal.posted` is emitted for manual, business, opening, and reversal Journals.
`journal.reversed` is additionally emitted against the target subject.
The Draft events are mandatory for persistent manual Drafts only. Opening uses
its dedicated create/delete events; Business and reversal Drafts are
transaction-local and never commit as Drafts.

### 8.4 AccountingPeriod

| Event | Required context |
|---|---|
| `period.created` | `start_date`, `end_date` |
| `period.boundaries_changed` | old/new boundaries |
| `period.closed` | boundaries, Posted Journal count as informational snapshot |
| `period.reopened` | boundaries, required reason |

The count does not become a close invariant or report total.

### 8.5 Opening Balance

| Event | Required context |
|---|---|
| `opening_balance.created` | root Journal ID, accounting date |
| `opening_balance.draft_deleted` | root Journal ID, optional reason |
| `opening_balance.posted` | root Journal ID/number, Period ID, line count, total debit |
| `opening_balance.reversed` | root/target/reversal IDs and number, reason |
| `opening_balance.reactivated` | root/target/reversal IDs and number, reason |

## 9. Append-only database controls

PostgreSQL triggers reject:

- UPDATE of any AccountingAudit row;
- DELETE of any AccountingAudit row;
- mutation/deletion of an Accounting source-type catalog row;
- an event/subject combination outside the frozen matrix;
- an actor/subject from another Tenant;
- non-object JSON context.

The application role is not granted TRUNCATE. Retention/archival of Accounting
audit is **DEFERRED** and may not be implemented as deletion without a legal and
product policy.

## 10. Transactional recording

Every required audit insert occurs on the same database connection and inside
the same transaction as its mutation.

```text
mutation succeeds + audit fails = full rollback
mutation fails = no success audit
commit succeeds = entity and audit visible together
```

The existing Collection audit recorder establishes this convention. Accounting
uses a dedicated `AccountingAuditRecorderInterface` whose database
implementation never falls back to log-only behavior.

Application logs may mirror events for operations, but are not a substitute for
durable audit and must not claim success before commit.

## 11. Read access

Accounting audit reads require `accounting.view_ledger` in v1. Tenant scoping is
mandatory. Context is not exposed wholesale by default; API resources select
approved fields.

There is no cross-Tenant system-owner audit search endpoint in this package.
Such an administrative capability requires separate authorization and privacy
review.

## 12. Future permission evolution

If Core later introduces persisted tenant-scoped permission records:

- retain the semantic capability names in section 2;
- map roles/permissions outside domain Actions;
- preserve active membership checks;
- do not alter Posted Journal or audit actor evidence;
- migrate authorization independently from Accounting schema truth.

The current role mapping is therefore an explicit v1 adapter, not a claim that
global roles are the final RBAC design.
