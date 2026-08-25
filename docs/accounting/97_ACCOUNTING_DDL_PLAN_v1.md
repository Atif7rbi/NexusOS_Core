# Accounting Foundations v1 — DDL Plan

Version: 1.0
Status: **FROZEN + DDL-READY**
Deliverable type: specification only; **no executable migrations in this package**

## 1. PostgreSQL and naming rules

- Active driver must be `pgsql`; otherwise migration aborts before mutation.
- Actual `SHOW server_version` output must be recorded during implementation
  preflight; it is currently **NOT VERIFIED**.
- ULIDs use the Core's `CHAR(26)` representation.
- Actor IDs use the existing PostgreSQL `BIGINT`/`users.id` type.
- Operational time uses `TIMESTAMPTZ`.
- Accounting date uses `DATE`.
- Money uses `NUMERIC(19,2)`.
- All named objects must remain within PostgreSQL's identifier limit.
- Trigger functions must use a controlled/schema-qualified search path.
- No optional PostgreSQL extension is required.

## 2. Existing infrastructure reused

`business_number_sequences` is reused and not recreated. One additive hardening
constraint is planned after inspecting live metadata/data:

```text
business_number_sequences_current_value_nonnegative_check
CHECK (current_value >= 0)
```

If an equivalent validated CHECK already exists in the target database, the
migration records that fact and does not create a duplicate. A negative existing
value or an unexpected live column type blocks migration; it is not auto-repaired.

Accounting uses rows where:

```text
prefix = 'JRN'
year = year(entry_date)
tenant_id = Journal tenant_id
```

The existing unique constraint
`business_number_sequences_tenant_prefix_year_unique`, Tenant FK, year CHECK,
and `current_value` remain authoritative.

The Accounting allocator locks the row, then uses PostgreSQL
`UPDATE ... SET current_value = current_value + 1 ... RETURNING current_value`.
PostgreSQL bigint overflow is translated to number exhaustion; no PHP arithmetic,
wrap, reset, or reuse is permitted.

Implementation preflight must report any existing `JRN` row. Because no
Accounting implementation exists at this baseline, a pre-existing row is
unclassified data and blocks migration/activation until explicitly resolved.

## 3. `accounting_source_types`

Global immutable reference catalog; not a tenant aggregate.

### Columns

| Column | PostgreSQL type | Null | Rule |
|---|---|---:|---|
| `origin` | `VARCHAR(24)` | No | `business`, `opening_balance`, `reversal` |
| `key` | `VARCHAR(64)` | No | canonical snake_case |
| `owner_module` | `VARCHAR(64)` | No | canonical snake_case module key |
| `description` | `VARCHAR(255)` | No | trimmed/nonblank |

### Constraints

- PK `accounting_source_types_pkey (origin,key)`.
- CHECK `accounting_source_types_origin_check`.
- CHECK `accounting_source_types_key_check` using
  `^[a-z][a-z0-9_]{0,63}$`.
- CHECK `accounting_source_types_owner_check` with the same canonical shape.
- CHECK `accounting_source_types_description_check (btrim(description) <> '')`.

### Seed rows

```text
(opening_balance, opening_balance_operation, accounting,
 'Root Journal owned by an OpeningBalanceOperation')
(reversal,        journal_entry,             accounting,
 'Exact reversal of a Posted JournalEntry')
```

No business source is seeded.

The runtime application role receives SELECT only on this catalog. INSERT is a
migration/release operation; runtime registration of arbitrary keys is forbidden.

### Triggers

- `accounting_source_types_immutable_update` BEFORE UPDATE.
- `accounting_source_types_immutable_delete` BEFORE DELETE.
- Function `prevent_accounting_source_type_mutation()` raises immutable-control
  error.

## 4. `accounting_settings`

### Columns

| Column | PostgreSQL type | Null | Rule |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK ULID |
| `tenant_id` | `CHAR(26)` | No | Tenant owner |
| `ledger_currency` | `CHAR(3)` | No | exactly `SAR` |
| `activated_by` | `BIGINT` | No | same-Tenant membership actor |
| `activated_at` | `TIMESTAMPTZ` | No | DB/application transaction time |

### Constraints

- PK `accounting_settings_pkey`.
- UNIQUE `accounting_settings_tenant_unique (tenant_id)`.
- UNIQUE `accounting_settings_tenant_id_id_unique (tenant_id,id)`.
- FK `accounting_settings_tenant_foreign` to `tenants(id)`, RESTRICT/RESTRICT.
- composite FK `accounting_settings_actor_foreign`
  `(tenant_id,activated_by) → tenant_users(tenant_id,user_id)`, RESTRICT/RESTRICT.
- CHECK `accounting_settings_currency_check (ledger_currency = 'SAR')`.

### Triggers

- immutable UPDATE/DELETE triggers using
  `prevent_accounting_settings_mutation()`.
- Tenant table trigger `tenants_accounting_currency_immutable` BEFORE UPDATE OF
  `currency`; when Settings exists, currency must remain `SAR` and unchanged.

Activation itself locks the Tenant row. The Tenant trigger is the direct-write
guard.

## 5. Root activation foreign-key pattern

Each tenant-owned Accounting aggregate root (`accounts`, `accounting_periods`,
`journal_entries`, `opening_balance_operations`, `accounting_audits`) has both:

1. direct FK `tenant_id → tenants(id)` RESTRICT; and
2. FK `tenant_id → accounting_settings(tenant_id)` RESTRICT.

The second FK prevents pre-activation persistence. It is not a substitute for
the first explicit Tenant ownership relationship.

## 6. `accounts`

### Columns

| Column | PostgreSQL type | Null | Default / meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner |
| `code` | `VARCHAR(32)` | No | canonical Tenant code |
| `name` | `VARCHAR(160)` | No | Unicode display name |
| `description` | `TEXT` | Yes | optional |
| `kind` | `VARCHAR(16)` | No | `group` or `posting` |
| `account_type` | `VARCHAR(16)` | No | five-type vocabulary |
| `classification` | `VARCHAR(32)` | Yes | null group; required posting |
| `parent_id` | `CHAR(26)` | Yes | same-Tenant Account |
| `status` | `VARCHAR(16)` | No | `active` or `archived`; default `active` |
| `created_by` | `BIGINT` | No | actor |
| `updated_by` | `BIGINT` | No | last actor |
| `archived_at` | `TIMESTAMPTZ` | Yes | lifecycle pair |
| `archived_by` | `BIGINT` | Yes | lifecycle pair |
| `restored_at` | `TIMESTAMPTZ` | Yes | current restored-active pair |
| `restored_by` | `BIGINT` | Yes | current restored-active pair |
| `created_at` | `TIMESTAMPTZ` | No | creation time |
| `updated_at` | `TIMESTAMPTZ` | No | last allowed mutation |

### Constraints

- PK `accounts_pkey`.
- UNIQUE `accounts_tenant_id_id_unique (tenant_id,id)`.
- UNIQUE `accounts_tenant_code_unique (tenant_id,code)` across all statuses.
- direct Tenant and Settings FKs.
- composite parent FK `accounts_tenant_parent_foreign`
  `(tenant_id,parent_id) → accounts(tenant_id,id)`, RESTRICT/RESTRICT.
- composite membership FKs for `created_by`, `updated_by`, `archived_by`, and
  `restored_by`, all RESTRICT.
- CHECK `accounts_code_check`: trimmed uppercase canonical regex
  `^[A-Z0-9]+([._-][A-Z0-9]+)*$`.
- CHECK `accounts_name_check (btrim(name) <> '')`.
- CHECK `accounts_description_check` requiring nonblank when non-null.
- CHECK `accounts_kind_check`.
- CHECK `accounts_type_check`.
- CHECK `accounts_kind_classification_check`:
  group → null; posting → non-null.
- CHECK `accounts_type_classification_check` containing the frozen mapping.
- CHECK `accounts_parent_not_self_check (parent_id IS NULL OR parent_id <> id)`.
- CHECK `accounts_status_check`.
- CHECK `accounts_lifecycle_fields_check` covering active/new,
  active/restored, and archived field pairs.

### Indexes

- `accounts_tenant_status_kind_index (tenant_id,status,kind)`.
- `accounts_tenant_parent_index (tenant_id,parent_id)`.
- `accounts_tenant_type_class_index (tenant_id,account_type,classification)`.

### Triggers/functions

- `accounts_hierarchy_guard` BEFORE INSERT OR UPDATE OF
  `tenant_id,parent_id,kind,account_type,classification` using
  `enforce_account_hierarchy()`:
  - locks Settings coordination row;
  - validates parent group/type and the active-child/active-ancestor rule;
  - recursively rejects cycles;
  - rejects a posting Account with children.
- `accounts_mutation_guard` BEFORE UPDATE using
  `enforce_account_lifecycle_and_history()`:
  - validates allowed state transition;
  - checks structural-history subtree;
  - validates group archive/ancestor restore rules;
  - restricts archived edits.
- `accounts_delete_guard` BEFORE DELETE using
  `prevent_account_delete()`.

Triggers raise named constraint semantics for exception translation.

## 7. `accounting_periods`

### Columns

| Column | PostgreSQL type | Null | Meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner |
| `start_date` | `DATE` | No | inclusive |
| `end_date` | `DATE` | No | inclusive |
| `status` | `VARCHAR(16)` | No | `open` default or `closed` |
| `created_by` | `BIGINT` | No | actor |
| `updated_by` | `BIGINT` | No | last actor |
| `closed_at` | `TIMESTAMPTZ` | Yes | current close pair |
| `closed_by` | `BIGINT` | Yes | current close pair |
| `reopened_at` | `TIMESTAMPTZ` | Yes | current reopen triple |
| `reopened_by` | `BIGINT` | Yes | current reopen triple |
| `reopen_reason` | `VARCHAR(500)` | Yes | current reopen triple |
| `created_at` | `TIMESTAMPTZ` | No | time |
| `updated_at` | `TIMESTAMPTZ` | No | time |

### Constraints

- PK and UNIQUE `(tenant_id,id)`.
- direct Tenant and Settings FKs.
- composite membership FKs for all actor columns, RESTRICT.
- CHECK `accounting_periods_dates_check` requiring
  `DATE '2000-01-01' <= start_date AND start_date <= end_date AND
  end_date <= DATE '9999-12-31'`.
- CHECK `accounting_periods_status_check`.
- CHECK `accounting_periods_state_fields_check` for new-open, reopened-open,
  and closed shapes.
- CHECK `accounting_periods_reopen_reason_check` requiring trimmed nonblank when
  present.

### Indexes

- `accounting_periods_tenant_dates_index (tenant_id,start_date,end_date)`.
- `accounting_periods_tenant_status_dates_index
  (tenant_id,status,start_date,end_date)`.

### Triggers/functions

- `accounting_periods_overlap_guard` BEFORE INSERT OR UPDATE OF Tenant/dates,
  function `enforce_accounting_period_nonoverlap()` locks Settings and rejects
  inclusive overlap.
- `accounting_periods_mutation_guard` BEFORE UPDATE, function
  `enforce_accounting_period_mutation()` validates transition and prevents
  boundary mutation when a Posted Journal references the Period.
- `accounting_periods_delete_guard` BEFORE DELETE rejects all deletion.

## 8. `journal_entries`

### Columns

| Column | PostgreSQL type | Null | Meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner |
| `accounting_period_id` | `CHAR(26)` | Yes | null Draft; required Posted |
| `entry_date` | `DATE` | No | official accounting date |
| `description` | `VARCHAR(500)` | No | nonblank |
| `status` | `VARCHAR(16)` | No | `draft` default or `posted` |
| `origin` | `VARCHAR(24)` | No | four-origin vocabulary |
| `source_type` | `VARCHAR(64)` | Yes | registered non-manual key |
| `source_id` | `CHAR(26)` | Yes | stable non-manual source ULID |
| `journal_number` | `VARCHAR(50)` | Yes | null Draft |
| `journal_number_year` | `SMALLINT` | Yes | null Draft |
| `journal_sequence_number` | `BIGINT` | Yes | null Draft |
| `created_by` | `BIGINT` | No | actor |
| `updated_by` | `BIGINT` | No | last Draft/post actor |
| `posted_by` | `BIGINT` | Yes | required Posted |
| `created_at` | `TIMESTAMPTZ` | No | time |
| `updated_at` | `TIMESTAMPTZ` | No | time |
| `posted_at` | `TIMESTAMPTZ` | Yes | required Posted |
| `reverses_journal_entry_id` | `CHAR(26)` | Yes | reversal target |
| `reversal_reason` | `VARCHAR(500)` | Yes | reversal only |

### Constraints

- PK and UNIQUE `journal_entries_tenant_id_id_unique`.
- direct Tenant and Settings FKs.
- composite Period FK
  `(tenant_id,accounting_period_id) → accounting_periods(tenant_id,id)`
  RESTRICT.
- composite reversal target FK
  `(tenant_id,reverses_journal_entry_id) → journal_entries(tenant_id,id)`
  RESTRICT.
- composite membership FKs for created/updated/posted actors, RESTRICT.
- composite source FK `(origin,source_type) →
  accounting_source_types(origin,key)`; manual null bypasses FK.
- UNIQUE `journal_entries_tenant_number_unique (tenant_id,journal_number)`.
- UNIQUE `journal_entries_tenant_year_sequence_unique
  (tenant_id,journal_number_year,journal_sequence_number)`.
- CHECK `journal_entries_description_check`.
- CHECK `journal_entries_entry_date_check` requiring
  `DATE '2000-01-01' <= entry_date AND entry_date <= DATE '9999-12-31'`.
- CHECK `journal_entries_status_check`.
- CHECK `journal_entries_origin_check`.
- CHECK `journal_entries_source_shape_check` for all four origin shapes.
- CHECK `journal_entries_reversal_reason_check`.
- CHECK `journal_entries_reversal_not_self_check
  (reverses_journal_entry_id IS NULL OR reverses_journal_entry_id <> id)`.
- CHECK `journal_entries_state_fields_check`:
  Draft has null Period/number/posted pair; Posted has all required.
- CHECK `journal_entries_number_year_check` for 2000..9999 and entry-date year.
- CHECK `journal_entries_sequence_check` positive when non-null.
- CHECK `journal_entries_number_format_check`, when number fields are non-null,
  requiring:

  ```text
  journal_number = 'JRN-' || journal_number_year::text || '-' ||
      lpad(
          journal_sequence_number::text,
          greatest(3, length(journal_sequence_number::text)),
          '0'
      )
  ```

  The dynamic output length is required so values above 999 expand rather than
  being truncated by a fixed-length `lpad`.

### Partial unique indexes

- `journal_entries_source_unique` on
  `(tenant_id,origin,source_type,source_id)` WHERE `origin <> 'manual'`.
- `journal_entries_direct_reversal_unique` on
  `(tenant_id,reverses_journal_entry_id)` WHERE target IS NOT NULL.

### Query indexes

- `journal_entries_tenant_status_date_number_index
  (tenant_id,status,entry_date,journal_sequence_number)`.
- `journal_entries_tenant_period_index
  (tenant_id,accounting_period_id)`.
- `journal_entries_tenant_source_index
  (tenant_id,origin,source_type,source_id)` (the partial unique supplies this for
  non-manual; no duplicate extra index if planner coverage is adequate).
- `journal_entries_tenant_reversal_target_index` is supplied by direct-reversal
  partial unique.

### Triggers/functions

- `journal_entries_insert_guard` BEFORE INSERT rejects status other than Draft
  and validates immutable creation/source shape.
- `journal_entries_update_guard` BEFORE UPDATE uses
  `enforce_journal_entry_mutation()`:
  - Draft edits limited to allowed fields;
  - source/origin/Tenant/ID immutable;
  - on draft→posted, performs complete posting validation;
  - Posted updates rejected.
- `journal_entries_delete_guard` BEFORE DELETE allows only a manual Draft or an
  Opening Draft removed through its aggregate-final-state contract; it rejects
  Posted and every explicit Business/reversal Draft delete.
- `journal_entries_system_draft_final_state` CONSTRAINT TRIGGER AFTER
  INSERT/UPDATE, DEFERRABLE INITIALLY DEFERRED, rejects a surviving Draft whose
  origin is `business` or `reversal`.
- Opening deferred consistency trigger also fires AFTER relevant Journal
  source/status/reversal changes, initially deferred.

`enforce_journal_entry_mutation()` validates at posting:

- open same-Tenant Period containing entry date;
- number fields and source registry;
- line count/contiguity/exact balance/positive total;
- same-Tenant posting Accounts;
- active Account for ordinary/business/opening origins;
- exact archived-allowed Account set for reversal;
- reversal target Posted/terminal, date not earlier, and exact swapped Lines;
- opening root relation when origin opening.

The application already owns locks; the trigger locks/rechecks any row required
for direct-write safety.

## 9. `journal_lines`

### Columns

| Column | PostgreSQL type | Null | Default / meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner copied from Journal |
| `journal_entry_id` | `CHAR(26)` | No | aggregate parent |
| `line_number` | `INTEGER` | No | positive sequence |
| `account_id` | `CHAR(26)` | No | posting Account |
| `debit` | `NUMERIC(19,2)` | No | `0.00` |
| `credit` | `NUMERIC(19,2)` | No | `0.00` |
| `memo` | `VARCHAR(500)` | Yes | optional nonblank |
| `created_at` | `TIMESTAMPTZ` | No | Draft time |
| `updated_at` | `TIMESTAMPTZ` | No | Draft edit time; frozen Posted |

### Constraints

- PK and UNIQUE `(tenant_id,id)`.
- direct Tenant FK.
- composite Journal FK `(tenant_id,journal_entry_id) →
  journal_entries(tenant_id,id)`, ON DELETE CASCADE for owned Draft aggregate,
  ON UPDATE RESTRICT.
- composite Account FK `(tenant_id,account_id) → accounts(tenant_id,id)`,
  RESTRICT/RESTRICT.
- UNIQUE `journal_lines_journal_line_number_unique
  (tenant_id,journal_entry_id,line_number)`.
- CHECK `journal_lines_line_number_check (line_number > 0)`.
- CHECK `journal_lines_debit_credit_xor_check` with exact positive/zero forms.
- CHECK `journal_lines_memo_check` nonblank when non-null.

### Indexes

- `journal_lines_tenant_account_journal_index
  (tenant_id,account_id,journal_entry_id)` for ledger/account activity joins.
- the unique line-number index supports ordered Journal loading.

### Triggers/functions

- BEFORE INSERT/UPDATE/DELETE `journal_lines_parent_state_guard` using
  `enforce_journal_line_parent_state()`:
  - lock/check same-Tenant parent;
  - reject any child mutation under Posted parent;
  - reject source-owner bypass where identifiable.

Application paths lock the Journal before touching Lines to obey deadlock order.

## 10. `opening_balance_operations`

### Columns

| Column | PostgreSQL type | Null | Meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner |
| `status` | `VARCHAR(16)` | No | `draft` or `posted`; default `draft` |
| `effect_state` | `VARCHAR(16)` | Yes | null Draft; effective/neutralized Posted |
| `accounting_date` | `DATE` | No | root date |
| `journal_entry_id` | `CHAR(26)` | No | root Journal |
| `latest_effect_journal_entry_id` | `CHAR(26)` | Yes | current terminal |
| `created_by` | `BIGINT` | No | actor |
| `updated_by` | `BIGINT` | No | Draft/post actor |
| `posted_by` | `BIGINT` | Yes | root posting actor |
| `effect_updated_by` | `BIGINT` | Yes | latest chain actor |
| `created_at` | `TIMESTAMPTZ` | No | time |
| `updated_at` | `TIMESTAMPTZ` | No | Draft/final root time |
| `posted_at` | `TIMESTAMPTZ` | Yes | root posted time |
| `effect_updated_at` | `TIMESTAMPTZ` | Yes | latest chain time |

### Constraints

- PK and UNIQUE `(tenant_id,id)`.
- direct Tenant and Settings FKs.
- composite root Journal FK and latest Journal FK to
  `journal_entries(tenant_id,id)`, RESTRICT.
- UNIQUE `opening_balance_root_journal_unique
  (tenant_id,journal_entry_id)`.
- partial UNIQUE `opening_balance_latest_journal_unique
  (tenant_id,latest_effect_journal_entry_id)` WHERE latest IS NOT NULL.
- composite membership FKs for every actor, RESTRICT.
- CHECK status vocabulary.
- CHECK effect vocabulary/nullability.
- CHECK `opening_balance_date_check` requiring
  `DATE '2000-01-01' <= accounting_date AND accounting_date <= DATE '9999-12-31'`.
- CHECK `opening_balance_state_fields_check` for Draft and Posted shapes.

### Authoritative slot index

`opening_balance_tenant_slot_unique` on `(tenant_id)` WHERE:

```text
status = 'draft'
OR (status = 'posted' AND effect_state = 'effective')
```

### Indexes

- `opening_balance_tenant_status_index (tenant_id,status,effect_state)`.
- root/latest unique indexes support chain lookup.

### Triggers/functions

- `opening_balance_mutation_guard` BEFORE INSERT/UPDATE/DELETE:
  - locks the Settings coordination row for direct-write serialization;
  - rejects a Draft accounting date earlier than the greatest prior neutralized
    terminal date;
  - Draft core edit/delete only through valid shapes;
  - Posted core fields immutable;
  - only validated effect fields may advance.
- `opening_balance_final_consistency` CONSTRAINT TRIGGER AFTER
  INSERT/UPDATE/DELETE,
  DEFERRABLE INITIALLY DEFERRED, function
  `validate_opening_balance_final_state()`.
- matching Journal constraint trigger schedules the same final validator after
  relevant Journal changes.

The validator proves root source/date/status, terminal chain, exact parity,
latest pointer, actor/time coherence, and that an effective terminal date is not
earlier than any other neutralized terminal date. It raises a constraint error
at commit.

## 11. `accounting_audits`

### Columns

| Column | PostgreSQL type | Null | Default / meaning |
|---|---|---:|---|
| `id` | `CHAR(26)` | No | PK |
| `tenant_id` | `CHAR(26)` | No | owner |
| `event` | `VARCHAR(80)` | No | closed event vocabulary |
| `subject_type` | `VARCHAR(40)` | No | closed subject vocabulary |
| `subject_id` | `CHAR(26)` | No | subject ULID |
| `actor_id` | `BIGINT` | No | same-Tenant membership |
| `context` | `JSONB` | No | `'{}'::jsonb` |
| `recorded_at` | `TIMESTAMPTZ` | No | `CURRENT_TIMESTAMP` |

### Constraints

- PK and UNIQUE `(tenant_id,id)`.
- direct Tenant and Settings FKs.
- composite actor membership FK, RESTRICT.
- CHECK `accounting_audits_event_check` containing the event matrix from
  `80_ACCOUNTING_AUTHORIZATION_AND_AUDIT_v1.md`.
- CHECK `accounting_audits_subject_type_check`.
- CHECK `accounting_audits_context_object_check
  (jsonb_typeof(context) = 'object')`.

### Indexes

- `accounting_audits_subject_time_index
  (tenant_id,subject_type,subject_id,recorded_at,id)`.
- `accounting_audits_event_time_index
  (tenant_id,event,recorded_at,id)`.
- `accounting_audits_actor_time_index
  (tenant_id,actor_id,recorded_at,id)`.

### Triggers/functions

- BEFORE INSERT `accounting_audits_subject_guard` using
  `validate_accounting_audit_subject()`:
  - validate event/subject compatibility;
  - validate same-Tenant subject existence at insert;
  - validate a named Draft-deletion event while its subject still exists; the
    deletion Action orders this insert before subject removal.
- BEFORE UPDATE/DELETE immutable triggers using
  `prevent_accounting_audit_mutation()`.

The database validates structural context only. Event-specific required JSON
keys/types are Domain validation and test requirements. When a named Draft
aggregate is later deleted, all of its prior audit rows intentionally retain the
former subject ULID; no polymorphic FK is fabricated or emulated after deletion.

## 12. Trigger/function catalog

| Function | Tables | Timing | Responsibility |
|---|---|---|---|
| `prevent_accounting_source_type_mutation` | source types | BEFORE U/D | immutable catalog |
| `prevent_accounting_settings_mutation` | settings | BEFORE U/D | immutable activation |
| `prevent_activated_tenant_currency_change` | tenants | BEFORE UPDATE currency | SAR freeze |
| `enforce_account_hierarchy` | accounts | BEFORE I/U | parent/type/kind/status/cycle and coordination lock |
| `enforce_account_lifecycle_and_history` | accounts | BEFORE UPDATE | allowed edit/archive/restore/history |
| `prevent_account_delete` | accounts | BEFORE DELETE | no delete |
| `enforce_accounting_period_nonoverlap` | periods | BEFORE I/U dates | settings lock and no overlap |
| `enforce_accounting_period_mutation` | periods | BEFORE UPDATE | lifecycle/history freeze |
| `prevent_accounting_period_delete` | periods | BEFORE DELETE | no delete |
| `enforce_journal_entry_insert` | journals | BEFORE INSERT | Draft-only/source creation shape |
| `enforce_journal_entry_mutation` | journals | BEFORE UPDATE | posting and Posted immutability |
| `enforce_journal_entry_delete` | journals | BEFORE DELETE | Draft-only delete |
| `validate_system_journal_final_state` | journals | deferred AFTER I/U row | no committed Business/reversal Draft |
| `enforce_journal_line_parent_state` | lines | BEFORE I/U/D | parent lock/Draft-only mutation |
| `enforce_opening_balance_mutation` | opening operations | BEFORE I/U/D | slot/date-floor guard, core immutability, effect advance shape |
| `validate_opening_balance_final_state` | opening + journals | deferred AFTER I/U/D row | root/chain/projection final truth, including no orphan opening Draft Journal |
| `validate_accounting_audit_subject` | audits | BEFORE INSERT | same-Tenant typed subject/event |
| `prevent_accounting_audit_mutation` | audits | BEFORE U/D | append-only |

Function names may be prefixed/suffixed mechanically to avoid a collision, but
responsibilities cannot be combined into a generic universal trigger framework.

## 13. Migration order

Recommended logical migrations:

1. **Preflight only**
   - assert `pgsql`;
   - record server version/settings;
   - verify `tenant_users(tenant_id,user_id)` unique target;
   - verify tenant currencies meet existing Core constraint;
   - detect unclassified `JRN` counter rows;
   - inspect the counter column type/nonnegative constraint and reject negative
     data;
   - verify no conflicting Accounting objects already exist.
2. **Shared sequence hardening, source catalog, and AccountingSettings**
   - add/verify the explicit counter nonnegative CHECK;
   - create source table/seed/immutability;
   - create Settings and Tenant currency trigger.
3. **Chart of Accounts**
   - table, constraints, indexes, hierarchy/lifecycle functions/triggers.
4. **Accounting Periods**
   - table, constraints, indexes, overlap/lifecycle functions/triggers.
5. **Journal header**
   - table, source/Period/self FKs, constraints, indexes, header guards that do
     not yet reference Lines until the next migration or installed after Lines.
6. **Journal Lines and posting validation**
   - Lines table/constraints/indexes/parent guard;
   - install final Journal posting function/trigger after Lines exists.
7. **Opening Balance Operation**
   - table, slot indexes, mutation and deferred cross-table triggers.
8. **Accounting Audit**
   - table, event/subject constraints, indexes, subject/immutability triggers.
9. **Privileges and catalog verification**
   - application role SELECT-only access to source catalog and DML on
     tenant-owned Accounting tables only as required by Actions;
   - no trigger disable/TRUNCATE/function replacement privilege;
   - query `pg_constraint`, `pg_trigger`, `pg_proc`, `pg_indexes` to assert every
     expected object and trigger enabled state.

Migration 5/6 may be one atomic migration if Laravel/PostgreSQL transaction and
deployment lock budget permit. No application code is enabled between partially
installed integrity layers.

## 14. Cutover and compatibility

All Accounting tables are new, so there is no ledger backfill. Existing
Contracts/Collections/Projects are not converted to Journals.

Activation is opt-in per Tenant after migration and only when Tenant currency is
SAR. Existing USD records remain unchanged.

The Tenant currency trigger affects only Tenants with an AccountingSettings row.
It does not narrow the existing global `SAR|USD` Tenant CHECK.

Existing Project number allocation continues unchanged. Allocator application
refactoring must retain its public behavior/tests while adding an
outer-transaction Accounting path.

## 15. Rollback policy

A down migration may drop the Accounting schema only when all Accounting tables
and all `JRN` usage are proven empty and no environment has activated a Tenant.

If any Settings, Account, Period, Journal, Line, Opening Operation, Audit, or
consumed `JRN` counter exists, rollback must throw before destructive change.
Production correction uses forward-fix migrations.

Trigger/function teardown order is reverse dependency order:

1. deferred/row triggers;
2. functions;
3. child tables;
4. parent tables/source catalog;
5. Tenant currency trigger/function.

On a proven-empty development rollback, the sequence-hardening migration drops
only the named nonnegative CHECK that it created. It must not remove or rename a
pre-existing equivalent constraint. Once Accounting data or `JRN` consumption
exists, the general rollback refusal applies and production uses a forward fix.

Do not rewrite historical applied migrations.

## 16. DDL validation gates

Before application implementation begins, migration tests must prove:

- clean migrate and safe empty rollback on PostgreSQL;
- non-PostgreSQL fail-closed behavior;
- every constraint/index/trigger exists and is enabled;
- direct invalid SQL is rejected for every matrix invariant;
- cross-Tenant relationships/actors fail;
- Posted immutability survives direct Eloquent/query-builder/SQL mutation;
- concurrency tests pass with independent database sessions;
- migration rollback refuses nonempty Accounting data;
- no migration/seed inserts customer-specific chart or identity.

## 17. DDL readiness declaration

Table identities, columns, types, relationships, vocabularies, constraints,
indexes, triggers, constraint-trigger timing, and migration order are resolved.

```text
Architecture status: FROZEN + DDL-READY
Executable DDL status: NOT CREATED by this assignment
Implementation authorization: NOT GRANTED pending external review
```
