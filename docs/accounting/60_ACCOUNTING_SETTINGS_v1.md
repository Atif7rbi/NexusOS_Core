# Accounting Foundations v1 — AccountingSettings

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Decision

AccountingSettings is required in v1, but its schema is intentionally minimal.

It provides three real capabilities:

1. an explicit, auditable Accounting activation boundary;
2. an immutable SAR ledger-currency snapshot and database parent for activated
   Accounting data;
3. a Tenant-scoped coordination row for rare hierarchy, Period-range, and
   Opening Balance slot mutations.

It is not a bag of common ERP settings.

## 2. Minimal schema

One row contains:

| Field | Rule |
|---|---|
| `id` | ULID primary key |
| `tenant_id` | Required Tenant FK and unique |
| `ledger_currency` | `CHAR(3)`, required, exactly `SAR` |
| `activated_by` | Required same-Tenant actor membership, restrictive FK |
| `activated_at` | Required `TIMESTAMPTZ` |

There is no `status`, `disabled_at`, `updated_at`, generic JSON, numbering
prefix, fiscal-year flag, rounding mode, Account code, or default Account in v1.

Presence of the row means Accounting is active. Absence means inactive.

## 3. Why presence rather than `inactive|active`

An inactive row would contain no configurable v1 state and would add a lifecycle
without a business action. Creating the row is the activation transition.

There is no deactivation action because deactivation would create ambiguity over
existing Drafts, Posted History, Period controls, and future source integrations.
Access can be denied through Tenant/module entitlement controls without deleting
or changing Accounting truth.

## 4. Activation workflow

`ActivateAccountingAction` performs one transaction:

1. require active authenticated User and active Tenant membership;
2. require Tenant administrative authority;
3. lock the Tenant row `FOR UPDATE`;
4. recheck Tenant status is `active`;
5. require `tenants.currency = 'SAR'`;
6. require no AccountingSettings row;
7. insert the immutable row with `ledger_currency='SAR'`;
8. write `accounting.activated` audit in the same transaction;
9. commit.

A unique constraint on `tenant_id` is authoritative against concurrent
activation. The Tenant row lock also serializes activation with Tenant currency
mutation.

Activation does not automatically create:

- a Chart of Accounts;
- an Accounting Period;
- an Opening Balance;
- default control Accounts;
- Journals;
- source integrations.

Those are separate explicit actions.

## 5. SAR eligibility

### 5.1 Existing Core reality

The inspected Core permits `tenants.currency IN ('SAR','USD')`. Contracts
snapshot Tenant currency and preserve it immutably. `system_settings.currency`
is independently editable company-profile/presentation data.

### 5.2 Frozen v1 policy

- Tenant currency is the activation authority.
- If `tenants.currency != 'SAR'`, activation fails.
- `system_settings.currency` cannot activate or disable Accounting and does not
  define ledger currency.
- Accounting places no currency freeze before activation. Any USD→SAR change
  must use a separately authorized Tenant-currency workflow available at that
  time; Accounting v1 does not create or assume that workflow.
- Once AccountingSettings exists, `tenants.currency` cannot change.
- A source record whose immutable transaction currency is not SAR cannot post to
  Accounting v1, even when its Tenant currency is SAR at call time.

### 5.3 Database protection

A trigger on `tenants.currency` rejects a change when an AccountingSettings row
exists for the Tenant. Activation locks the same Tenant row before inserting
Settings. This closes the activation-versus-currency-change race.

The Settings row also has `CHECK (ledger_currency = 'SAR')`; application
constants are not the final guard.

### 5.4 Existing USD business records

Existing USD Contracts remain valid operational records. Accounting Foundations
does not migrate, convert, or post them. A future multi-currency architecture is
required before such a source can create a Journal.

## 6. Settings as activation parent

Each tenant-owned Accounting aggregate root has:

- its direct restrictive Tenant FK; and
- a restrictive FK from `tenant_id` to the unique
  `accounting_settings(tenant_id)`.

This makes it impossible to create Accounts, Periods, Journals,
OpeningBalanceOperations, or AccountingAudits before activation, including by
direct persistence. Child rows additionally inherit tenant integrity through
their composite parent FKs.

## 7. Settings as coordination row

The row is locked `FOR UPDATE` for rare operations that need one Tenant-wide
serialization point:

- Account hierarchy create/move/type/kind mutations;
- group archive/restore checks involving descendants/ancestors;
- AccountingPeriod create/boundary overlap checks;
- Opening Balance create/delete/post/effect-reactivation slot changes.

Normal Journal posting does not lock AccountingSettings. It reads activation
and uses Journal, Period, Account, and number-row locks. This avoids serializing
all postings for a Tenant.

Settings immutability means an unlocked activation read cannot become false
during posting.

## 8. Control and default Accounts

No control/default Account field is justified by the v1 ledger alone.

The following therefore do not exist yet:

- receivables control Account;
- cash/bank Account;
- payables control Account;
- tax payable/input Account;
- opening-balance equity Account;
- retained-earnings Account;
- expense default Account;
- revenue default Account.

Manual and Opening Balance use cases explicitly select Accounts. Accounting does
not search for codes such as `110101`.

When an approved future module genuinely requires a control/default Account, its
architecture must add a named nullable-or-required Account ID through a forward
migration with:

- same-Tenant composite FK;
- required posting kind and allowed type/classification validation;
- explicit lifecycle/archive behavior;
- transactional settings-change audit;
- migration/backfill and activation compatibility rules.

This extension does not require changing Journal/Line truth.

## 9. Mutation and deletion

The complete v1 row is immutable after insertion:

- no UPDATE;
- no DELETE;
- no deactivation;
- no actor/time replacement;
- no currency replacement.

Database triggers enforce update/delete rejection. Restrictive child FKs provide
additional delete protection once Accounting data exists.

Future schema extensions may introduce a controlled Settings update Action, but
must explicitly supersede the v1 whole-row immutability trigger for only the new
fields. It may not rewrite activation or currency evidence.

## 10. Authorization and audit

Only `TenantAdministratorAuthority` may activate or manage future Accounting
Settings. The existing `accountant` role does not activate Accounting.

`accounting.activated` records:

- Settings ID;
- Tenant ID through row ownership;
- actor;
- recorded timestamp;
- ledger currency;
- prior Tenant currency value (`SAR`) as control context.

The audit insert and Settings insert commit or roll back together.

There is no `settings.changed` event in active v1 behavior because no setting can
change. That event key is reserved only for a future migration that adds a real
mutable setting and its approved semantics.

## 11. Activation result and failures

Success returns Settings ID, Tenant ID, `SAR`, actor, and activation timestamp.

Named failures include:

- Tenant/membership inactive;
- actor unauthorized;
- Tenant currency unsupported;
- Accounting already active;
- concurrent activation conflict;
- transactional audit failure;
- PostgreSQL integrity failure.

Activation is idempotent only when the caller explicitly requests idempotent
behavior and the existing row is the same Tenant's immutable SAR activation. It
never creates a second row.

## 12. Explicit non-goals

AccountingSettings v1 is not:

- SystemSetting reuse;
- a Tenant currency editor;
- a multi-currency policy;
- a generic key/value table;
- a rules DSL;
- a report configuration store;
- a fiscal calendar template;
- a module entitlement replacement;
- a place to encode magic Account codes.
