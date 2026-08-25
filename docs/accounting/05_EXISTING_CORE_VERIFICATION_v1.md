# Accounting Foundations v1 — Existing Core Verification

Version: 1.0
Status: **FROZEN evidence baseline**
Inspected branch base: `main` at `1fc7c0a348602364a7bafa1ae0f8b5c2a802157c`

## 1. Method

This document separates observed repository reality from Accounting decisions.
It records only facts verified from the named baseline. Absence claims were
checked across tracked backend files and migrations, not inferred from roadmap
language.

## 2. Verified platform facts

| Area | Status | Evidence | Consequence for Accounting v1 |
|---|---|---|---|
| Product boundary | VERIFIED FROM CORE | `AGENTS.md`, `PROJECT_CONTEXT.md`, `docs/MASTER_CONTEXT/00_Project_Overview.md` | Accounting belongs in reusable Core; no customer identity or exception is permitted |
| Backend | VERIFIED FROM CORE | `backend/composer.json` | Laravel 12 and PHP `^8.2` are the target application stack |
| Official database | VERIFIED FROM CORE | `README.md`, `docs/MASTER_CONTEXT/04_Development_Environment.md`, PostgreSQL-specific migrations/tests | PostgreSQL is mandatory for Accounting |
| Actual server version | NOT VERIFIED | No pinned version, server manifest, or reachable `psql` runtime exists in this checkout | Future implementation preflight must record `SHOW server_version`; no version is guessed |
| Default connection fallback | VERIFIED FROM CORE | `backend/config/database.php`, `backend/.env.example` | Both still default to `sqlite`; Accounting migrations/tests must explicitly fail closed unless driver is `pgsql` |
| PostgreSQL test safety | VERIFIED FROM CORE | `backend/tests/TestCase.php`, `PostgreSqlConnectionTest.php` | Tests require an isolated database ending `_testing` and assert the `pgsql` driver |
| IDs | VERIFIED FROM CORE | Tenant and business migrations | Tenant/domain records use ULIDs; `users.id` and `business_number_sequences.id` are bigint identities |
| Timestamps | VERIFIED FROM CORE | Domain migrations and `DB_TIMEZONE=UTC` | Accounting operational timestamps use `TIMESTAMPTZ`; accounting date remains a separate `DATE` |
| Exact PHP decimal library | VERIFIED FROM CORE | `backend/composer.lock` contains `brick/math` 0.14.8 transitively; `backend/composer.json` does not require it directly | Accounting implementation must promote the chosen exact library to a direct dependency rather than rely on a transitive package |

## 3. Tenant and user reality

| Fact | Classification | Evidence / interpretation |
|---|---|---|
| `tenants.id` is a ULID primary key | VERIFIED FROM CORE | `2026_07_16_070000_create_tenants_and_tenant_users_tables.php` |
| Tenant states are `invited`, `active`, `paused`, `suspended`, `removed` | VERIFIED FROM CORE | Database CHECK and `Tenant` constants |
| `tenant_users` preserves membership rows and uniquely identifies `(tenant_id,user_id)` | VERIFIED FROM CORE | Unique constraint `tenant_users_tenant_user_unique` |
| Membership states are `invited`, `active`, `paused`, `suspended`, `removed` | VERIFIED FROM CORE | Database CHECK and `TenantUser` constants |
| The normal removal flow marks membership `removed` and User `archived`; it does not delete either row | VERIFIED FROM CORE | `TenantUserController::destroy` |
| `users.id` is bigint; User roles and status are stored globally on `users` | VERIFIED FROM CORE | Users migrations and `User` model |
| User roles are `system_owner`, `administrator`, `project_manager`, `sales`, `accountant`, `employee` | VERIFIED FROM CORE | `users_role_check` and `User` constants |
| Active tenant membership is required by normal authenticated module middleware | VERIFIED FROM CORE | `EnsureActiveTenantMembership`, `ResolveActiveMembership` |
| `TenantAdministratorAuthority` treats `system_owner` and `administrator` as administrative identities | VERIFIED FROM CORE | Shared authorization support class |
| A persisted permission/role/role-permission table set does not exist in this baseline | VERIFIED FROM CORE | Full migration/model search; current authorization is role and Support-class based |

### Accounting consequence

Accounting actor references use composite foreign keys from
`(tenant_id, actor_id)` to `tenant_users(tenant_id, user_id)` with
`ON UPDATE RESTRICT ON DELETE RESTRICT`. This is stricter than several older
operational tables and proves that an actor had a membership row in the same
Tenant. Application authorization must additionally lock and require active
membership and active User status at command time.

The database must not require membership to remain active later; removal is a
lifecycle state and must not destroy historical evidence.

## 4. Existing actor-FK conventions

Observed Core behavior is mixed:

- `created_by` and `updated_by` on early operational entities often use
  `SET NULL` on User deletion.
- lifecycle evidence such as Collection actors, Lead terminal actors, and the
  corrected Customer `archived_by` uses `RESTRICT`.
- `collection_schedule_audits.actor_id` is non-null and `RESTRICT`.
- existing actor FKs generally reference `users(id)` only; they do not prove
  same-Tenant membership.

**ARCHITECTURAL DECISION:** Accounting historical/control actor fields all use
same-Tenant composite membership FKs and `RESTRICT`. Accounting does not copy
the weaker early operational convention.

## 5. Currency reality

| Fact | Classification | Evidence |
|---|---|---|
| `tenants.currency` is non-null, defaults to `SAR`, and is constrained to `SAR` or `USD` | VERIFIED FROM CORE | Tenant migration plus `2026_07_28_080000_prepare_contract_currency_for_collections.php` |
| A Contract snapshots current Tenant currency at creation | VERIFIED FROM CORE | `CreateContractAction` and Collections database tests |
| `contracts.currency` is immutable and accepts `SAR` or `USD` | VERIFIED FROM CORE | Currency migration trigger and CHECK |
| Collection amounts inherit Contract context; Collection rows have no independent currency column | VERIFIED FROM CORE | Collections migration, model, and API specification |
| `system_settings.currency` is independently editable presentation/company-profile data | VERIFIED FROM CORE | `SystemSetting`, `UpdateSystemSettingRequest`, and controller |
| Tenant currency has no database guard tied to Accounting because Accounting does not yet exist | VERIFIED FROM CORE | Migration and code search |

### Accounting consequence

- `tenants.currency`, not `system_settings.currency`, is the activation input.
- A Tenant whose currency is not `SAR` cannot activate Accounting v1.
- Once Accounting is activated, a database trigger prevents changing
  `tenants.currency` away from `SAR`.
- A future business source whose immutable source currency is not `SAR` is
  rejected by the Posting API even when its Tenant later uses SAR.
- Existing USD Contracts remain valid operational history; Accounting v1 does
  not auto-post or convert them.

## 6. Numbering infrastructure

### Verified schema

`business_number_sequences` currently has:

- bigint `id`;
- ULID `tenant_id` with restrictive Tenant FK;
- `prefix` up to 10 characters;
- `year` with `2000..9999` CHECK;
- migration-declared `unsignedBigInteger current_value DEFAULT 0`; no explicit
  named PostgreSQL nonnegative CHECK is present in the repository migration;
- unique `(tenant_id,prefix,year)`.

### Verified allocator

`BusinessNumberGenerator`:

1. normalizes and validates prefix;
2. `insertOrIgnore`s the counter row;
3. selects the tenant/prefix/year row `FOR UPDATE`;
4. casts `current_value` to PHP `int`, adds one, and updates it transactionally;
5. formats `<PREFIX>-<YEAR>-<sequence padded to at least 3 digits>`;
6. wraps its own `DB::transaction(..., 3)`.

It does not use `MAX()+1`.

### Accounting consequence

Accounting reuses this table and format with prefix `JRN`. The accounting year
argument is the year component of explicit `entry_date`, not the current clock.
Allocation occurs late inside the outer posting transaction.

The current allocator's independently owned transaction/retry wrapper must be
adapted during implementation so the outer Accounting or Business transaction
owns retries. This is an application refactor, not a new sequence schema and not
a DDL-readiness blocker.

The implementation preflight must inspect the live PostgreSQL column/constraints
rather than assume that the Laravel `unsigned` modifier created a database
CHECK. The DDL Plan adds an explicit nonnegative constraint when no equivalent
exists. The transaction-participating allocator increments with PostgreSQL
`UPDATE ... RETURNING` and treats bigint exhaustion as a named failure, avoiding
a PHP integer-overflow path.

## 7. Tenant-safe relationship conventions

The strongest existing pattern is used by Collections and its audit:

1. the parent table exposes `UNIQUE (tenant_id,id)`;
2. the child stores `tenant_id` and parent ID;
3. a composite FK `(tenant_id,parent_id)` references `(tenant_id,id)`;
4. direct Tenant FKs and restrictive deletion remain explicit.

`collections → contracts` and `collection_schedule_audits → contracts` use this
pattern. Several earlier entities have independent Tenant and parent FKs and do
not database-enforce that both belong to the same Tenant.

**ARCHITECTURAL DECISION:** every Accounting cross-aggregate relationship uses
the stronger composite pattern. Global uniqueness of a ULID is not accepted as
a substitute for tenant consistency.

## 8. Audit reality

`collection_schedule_audits` is the only durable domain-specific audit table in
the inspected Core. It establishes these useful conventions:

- ULID audit ID;
- Tenant ownership and same-Tenant aggregate FK;
- stable event key;
- non-null restrictive actor FK;
- `JSONB` context;
- `recorded_at` as `TIMESTAMPTZ`;
- database triggers reject UPDATE and DELETE;
- audit insertion occurs in the same caller transaction;
- audit failure rolls back the business mutation;
- application logging is secondary to database persistence.

There is no implemented cross-domain audit platform. Accounting therefore owns
a dedicated `accounting_audits` table instead of waiting for or inventing a
universal audit framework.

Accounting audit records control provenance. They do not copy Journal Lines and
cannot reconstruct ledger balances; Posted Journals and Lines remain truth.

## 9. Lifecycle and concurrency conventions

Verified reusable patterns include:

- explicit Actions wrapping mutations in transactions;
- aggregate-root `lockForUpdate()` before child mutation;
- deterministic child ordering in Collection schedule commands;
- partial unique indexes for state-qualified uniqueness;
- PostgreSQL CHECK constraints for lifecycle field coherence;
- PL/pgSQL triggers for immutable currency and append-only audit;
- a transaction-scoped advisory lock plus trigger for concurrent Tenant License
  period overlap;
- PostgreSQL worker-process tests for real row-lock races;
- semantic exception translation rather than returning raw SQL errors.

Accounting uses the same principles but freezes a complete lock order and uses
an `accounting_settings` row as the rare structural/range/initial-opening
coordination parent. Normal Journal posting is not serialized tenant-wide.

## 10. Application and authorization reality

- The master architecture assigns transaction coordination to the Application
  Layer and business rules to Domain services/policies.
- Current module code commonly represents use cases as explicit `Actions` that
  transact, lock, call policies/validators, persist, and return domain records or
  DTOs.
- Authorization is centralized per module in Support classes and reuses
  `TenantAdministratorAuthority` where applicable.
- Collection financial commands admit `accountant` plus administrative roles.

Accounting follows these conventions with an `AccountingAuthorization` support
class and explicit Actions. It does not add permission tables or a generic policy
engine in v1.

## 11. PostgreSQL feature evidence

The repository already relies on:

- `JSONB`;
- case-insensitive `ILIKE` queries;
- regex CHECK constraints;
- partial unique indexes;
- `PL/pgSQL` trigger functions;
- row locks;
- transaction-scoped advisory locks;
- PostgreSQL-specific failure codes and concurrency tests.

The Accounting plan adds deferred constraint triggers for final cross-table
opening-balance consistency. The exact PostgreSQL server version remains
`NOT VERIFIED`; the implementation preflight must verify it before migration.

## 12. Existing Accounting implementation search

No Accounting tables, migrations, models, Actions, controllers, Journal
scaffolding, or Accounting routes exist at the inspected baseline.

The `accountant` role and Collection Schedule financial authorization are not a
ledger implementation. Contracts and Collection Schedules are operational
business records and do not imply Posted Journals.

## 13. Evidence classification summary

| Item | Classification |
|---|---|
| Core stack, IDs, tenant/user lifecycle, role constants | VERIFIED FROM CORE |
| PostgreSQL as official engine and required test engine | VERIFIED FROM CORE |
| Exact deployed PostgreSQL version | NOT VERIFIED |
| Strong composite Tenant FK pattern | VERIFIED FROM CORE |
| Existing sequence table and allocator algorithm | VERIFIED FROM CORE |
| Existing Collection audit behavior | VERIFIED FROM CORE |
| SAR-only ledger, Accounting tables, invariants, source registry, and lock protocol | ARCHITECTURAL DECISION |
| Multi-currency, subledgers, tax, and broader ERP workflows | DEFERRED |
