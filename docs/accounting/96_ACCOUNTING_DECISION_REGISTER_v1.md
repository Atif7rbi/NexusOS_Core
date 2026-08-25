# Accounting Foundations v1 — Architecture Decision Register

Version: 1.0
Status: **FROZEN register**

This register records material decisions needed for implementation. Detailed
invariants remain normative in the subject documents and DDL Plan.

## AFD-001 — Accounting ownership boundary

| Field | Record |
|---|---|
| Problem | Prevent business workflows from becoming an inconsistent second ledger writer |
| Existing Core reality | Contracts, Reservations, and Collections are operational domains; no Accounting tables exist |
| Options considered | Business modules write Journal tables; Accounting consumes events asynchronously; one internal Posting boundary |
| Trade-offs | Central boundary adds an explicit integration call but preserves one invariant owner; async posting would break v1 atomicity |
| Decision | Business Modules own event timing/rules/source; Accounting alone owns Journal persistence/posting/integrity |
| Why | One ledger owner prevents duplicated validation and source-specific corruption |
| DB impact | Journal tables have no business-module write contract |
| Application impact | All integrations call Accounting API; direct repositories are forbidden |
| Future impact | New modules add registered sources/rules without redesigning Journal truth |
| Status | **FROZEN** |

## AFD-002 — PostgreSQL-only Accounting runtime

| Field | Record |
|---|---|
| Problem | Accounting depends on native concurrency and cross-row integrity |
| Existing Core reality | PostgreSQL is official and required by tests, but config/example fallback remains SQLite |
| Options considered | Lowest-common-denominator SQL; emulate in application; fail closed on PostgreSQL |
| Trade-offs | PostgreSQL-only reduces portability but materially improves correctness |
| Decision | Accounting migrations/runtime/concurrency tests require `pgsql` and fail closed otherwise |
| Why | Partial unique indexes, row locks, PL/pgSQL, and constraint triggers are foundational |
| DB impact | PostgreSQL-specific DDL is permitted and required |
| Application impact | Explicit connection guard and PostgreSQL test database |
| Future impact | Another engine requires a new reviewed integrity design |
| Status | **FROZEN** |

## AFD-003 — Account type and classification

| Field | Record |
|---|---|
| Problem | Separate fundamental accounting behavior from report placement |
| Existing Core reality | No Account model exists |
| Options considered | Many specialized types; five types only; five types plus closed classifications |
| Trade-offs | Closed classification requires migrations to extend but prevents uncontrolled vocabulary |
| Decision | Types: asset/liability/equity/revenue/expense; posting-only type-compatible classification vocabulary |
| Why | Supports ledger signs and financial statements without treating cash/AR/AP as types |
| DB impact | CHECK vocabulary and mapping |
| Application impact | Enums and explicit validation |
| Future impact | Control Account roles use Settings FKs, not new types/magic classification |
| Status | **FROZEN** |

## AFD-004 — Account hierarchy representation

| Field | Record |
|---|---|
| Problem | Represent reporting groups safely without denormalized tree machinery |
| Existing Core reality | Core uses explicit FKs/composite tenant constraints; no generic hierarchy platform |
| Options considered | Code-derived hierarchy; nested sets/materialized path; adjacency list |
| Trade-offs | Recursive queries are required, but writes and integrity remain explicit |
| Decision | Nullable `parent_id` adjacency list; same Tenant/type; group parent; posting leaves; active child has active ancestors; no cycles |
| Why | Smallest correct relational model |
| DB impact | Composite self-FK, CHECK, serialized recursive trigger |
| Application impact | Structural Actions acquire Settings coordination and deterministic Account locks |
| Future impact | Recursive reporting can be optimized later without changing identity |
| Status | **FROZEN** |

## AFD-005 — Account history and lifecycle

| Field | Record |
|---|---|
| Problem | Permit operational maintenance without rewriting Posted reporting meaning |
| Existing Core reality | Archive/restore and restrictive history patterns exist; early actor semantics are mixed |
| Options considered | Freeze every field after first use; snapshot Account on every Line; audited current labels plus structural freeze |
| Trade-offs | Historical reports display current audited Account name, but code/classification/hierarchy stay stable |
| Decision | Active/archived; no delete; name/description audited edits; structural fields freeze for any affected subtree with Posted History |
| Why | Preserves amounts/report classification while allowing label corrections |
| DB impact | lifecycle/history triggers and restrictive Journal-Line FK |
| Application impact | descriptive versus structural Actions/policies |
| Future impact | Account aliases/name history may be added without altering Lines |
| Status | **FROZEN** |

## AFD-006 — Journal lifecycle

| Field | Record |
|---|---|
| Problem | Separate incomplete preparation from immutable ledger truth |
| Existing Core reality | Explicit lifecycle Actions and CHECKs are established conventions |
| Options considered | draft/posted/reversed/void; mutable posted; draft→posted terminal |
| Trade-offs | Corrections require new records but history remains clear |
| Decision | `draft → posted`; direct Posted insert forbidden; Business/reversal Draft cannot survive commit; Posted header/Lines terminal and immutable |
| Why | One validated transition makes ledger truth auditable |
| DB impact | state CHECK, posting/immutability triggers, deferred system-Draft final-state guard |
| Application impact | common posting engine; Draft delete only |
| Future impact | Approval can precede posting without changing Posted semantics |
| Status | **FROZEN** |

## AFD-007 — Journal Line and money representation

| Field | Record |
|---|---|
| Problem | Store exact money and guarantee double entry |
| Existing Core reality | Business amounts use decimal(12,2)/(15,2); no ledger precision exists; `brick/math` 0.14.8 is transitively locked but not a direct dependency |
| Options considered | floats; numeric(15,2); numeric(19,2); numeric with four decimals |
| Trade-offs | The supported boundary rejects sub-halalah inputs and aligns with SAR; PostgreSQL typmod rounds before row CHECK evaluation, so boundary validation is mandatory and direct-SQL coercion is explicitly tested |
| Decision | `NUMERIC(19,2)`, decimal-string API, debit XOR credit per Line, exact positive balanced aggregate |
| Why | Exactness and sufficient range for Core |
| DB impact | numeric columns, CHECKs, posting aggregate trigger |
| Application impact | direct `brick/math` production dependency, BigDecimal Amount value object, no binary floating arithmetic, reject excessive precision |
| Future impact | Multi-currency scale changes require explicit architecture/migration |
| Status | **FROZEN** |

## AFD-008 — Line ordering and duplicate Accounts

| Field | Record |
|---|---|
| Problem | Preserve deterministic presentation and legitimate repeated components |
| Existing Core reality | Collections use explicit positive sequence/order patterns |
| Options considered | unique Account per Journal; unordered Lines; contiguous line numbers with duplicates allowed |
| Trade-offs | Repeated Accounts require more rows but preserve semantic memos |
| Decision | At least two Lines; contiguous 1..N; duplicate Accounts allowed; reversal preserves line mapping |
| Why | Determinism without lossy auto-merge |
| DB impact | unique line number plus posting min/max/count validation |
| Application impact | canonical ordered result/comparison |
| Future impact | UI may consolidate before save but persistence never guesses |
| Status | **FROZEN** |

## AFD-009 — Accounting date and stored Period

| Field | Record |
|---|---|
| Problem | Operational timestamps cannot identify accounting period truth |
| Existing Core reality | UTC/TenantClock conventions exist; Period model does not; existing sequence year is constrained to 2000..9999 |
| Options considered | use `posted_at`; resolve Period dynamically forever; store Period at Draft; resolve/store at posting |
| Trade-offs | Stored FK adds a column but prevents later ambiguity |
| Decision | Explicit `entry_date` within 2000-01-01..9999-12-31; Draft Period null; resolve by Tenant/date and store immutable Period ID at posting |
| Why | Preserves business date and historical control authorization |
| DB impact | DATE, composite Period FK, posting date/range trigger |
| Application impact | caller supplies date; timestamps remain operational |
| Future impact | Fiscal reporting can group stored Periods and dates safely |
| Status | **FROZEN** |

## AFD-010 — Accounting Period lifecycle and ranges

| Field | Record |
|---|---|
| Problem | Prevent posting into controlled dates and concurrent overlap |
| Existing Core reality | Tenant License uses a PostgreSQL overlap trigger/advisory lock; optional extensions unverified |
| Options considered | open/locked/closed; open/closed; exclusion extension; serialized trigger |
| Trade-offs | Settings-row serialization affects only rare range writes and avoids extension dependency |
| Decision | Inclusive non-overlapping ranges, gaps allowed, `open↔closed`; reasoned administrator reopen; stored current metadata plus append-only audit |
| Why | One closed meaning is sufficient for v1 |
| DB impact | CHECK, overlap/lifecycle/history triggers |
| Application impact | create/edit/close/reopen Actions and shared Period lock with posting |
| Future impact | Extra lock states require proven distinct semantics |
| Status | **FROZEN** |

## AFD-011 — Journal numbering

| Field | Record |
|---|---|
| Problem | Allocate concurrent Tenant/year Journal numbers without a second mechanism |
| Existing Core reality | `business_number_sequences` and row-lock allocator exist; no `MAX()+1`; migration has unsigned intent but no explicit named nonnegative CHECK, and service increments after PHP int cast |
| Options considered | PostgreSQL sequence; dedicated accounting table; reuse shared allocator |
| Trade-offs | Existing service needs outer-transaction adaptation, but schema stays unified |
| Decision | Reuse Tenant/prefix/year row with `JRN`, entry-date year, posting-time allocation inside outer transaction; add explicit nonnegative CHECK if absent and increment through PostgreSQL `UPDATE ... RETURNING` |
| Why | Existing mechanism already supplies scoped transactional row locking |
| DB impact | additive shared-counter nonnegative CHECK plus Journal number/year/sequence UNIQUE/CHECK; no new counter table |
| Application impact | transaction-participating allocator; database-returned bigint string; named exhaustion failure; no Draft numbers |
| Future impact | Prefix/year policy remains explicit; committed numbers never reused |
| Status | **FROZEN** |

## AFD-012 — Source vocabulary and provenance

| Field | Record |
|---|---|
| Problem | Generic integration without PHP names or arbitrary strings |
| Existing Core reality | No generic accounting source contract exists |
| Options considered | free text; hardcoded Journal CHECK revised for every module; immutable source catalog |
| Trade-offs | One small global catalog table, but future modules register without weakening DB vocabulary |
| Decision | Migration-owned immutable `(origin,key)` catalog; Journal composite FK; stable ULID source ID |
| Why | Authoritative extensibility without a rules DSL |
| DB impact | `accounting_source_types` plus source-shape CHECK/FK |
| Application impact | internal enums/registration gate |
| Future impact | Each approved module adds one or more stable event keys |
| Status | **FROZEN** |

## AFD-013 — Source idempotency

| Field | Record |
|---|---|
| Problem | Prevent duplicate ledger impact from retries/concurrency |
| Existing Core reality | Core relies on DB uniqueness and row locks for critical races |
| Options considered | application precheck; arbitrary idempotency token; stable source tuple partial UNIQUE |
| Trade-offs | Source rules must be deterministic and conflicts cannot be silently replaced |
| Decision | Unique non-manual `(tenant,origin,type,id)`; source lock; exact existing-Journal comparison on retry |
| Why | Survives concurrent and unknown-commit retries |
| DB impact | partial UNIQUE |
| Application impact | fresh-transaction conflict resolution and `idempotent_replay` result |
| Future impact | Multi-event sources need distinct durable event identities |
| Status | **FROZEN** |

## AFD-014 — Reversal graph and exactness

| Field | Record |
|---|---|
| Problem | Correct errors without mutable/ambiguous history |
| Existing Core reality | Historical preservation is frozen; no Accounting behavior exists |
| Options considered | mutate/void target; partial reversals; exact reversal; allow/disallow reversal-of-reversal |
| Trade-offs | Exact correction may require a replacement/adjustment, but reversal semantics stay provable |
| Decision | Exact swapped Lines, one direct reversal, terminal target, reversal-of-reversal allowed as linear chain, target remains Posted |
| Why | Immutable and mathematically transparent |
| DB impact | composite target FK, partial UNIQUE, posting exactness trigger |
| Application impact | Reversal Action builds Lines from locked target |
| Future impact | Partial adjustments remain separate Journal events |
| Status | **FROZEN** |

## AFD-015 — Archived Accounts and closed Period reversal

| Field | Record |
|---|---|
| Problem | Historical corrections must remain possible after controls change |
| Existing Core reality | Archive and lifecycle restrictions are common patterns |
| Options considered | reopen everything; permit all posting; narrow reversal exception |
| Trade-offs | Posting trigger needs origin-specific validation |
| Decision | Exact reversal may use archived target Accounts; reversal date must resolve to an open Period; target Period may stay closed |
| Why | Corrects history without reopening master data or weakening ordinary posting |
| DB impact | origin-aware posting trigger |
| Application impact | explicit reversal date and locked Account checks |
| Future impact | Same rule applies to future source Journals |
| Status | **FROZEN** |

## AFD-016 — Currency policy

| Field | Record |
|---|---|
| Problem | Existing Core supports SAR/USD, but v1 has no FX model |
| Existing Core reality | Tenant and Contract permit SAR/USD; Contract currency immutable; SystemSetting currency separate |
| Options considered | multi-currency now; trust source; SAR-only activation/posting |
| Trade-offs | USD sources cannot post until future design, but no false conversions occur |
| Decision | SAR-only ledger; Tenant must be SAR at activation; freeze Tenant currency after activation; reject non-SAR sources |
| Why | Correct single-currency foundation without speculative FX |
| DB impact | Settings SAR CHECK; Tenant currency trigger |
| Application impact | activation/source currency errors |
| Future impact | Multi-currency requires explicit base/transaction amounts and rates |
| Status | **FROZEN** |

## AFD-017 — AccountingSettings minimum

| Field | Record |
|---|---|
| Problem | Need activation/currency/coordination without generic settings sprawl |
| Existing Core reality | SystemSetting is mutable company profile and unsuitable as ledger control |
| Options considered | no Settings; generic key/value; full ERP defaults; immutable minimal row |
| Trade-offs | Future modules add fields by migrations, not configuration today |
| Decision | One immutable row: ID, Tenant, SAR currency, activation actor/time; presence means active |
| Why | Every field serves a current invariant |
| DB impact | one-per-Tenant table and activation parent FKs |
| Application impact | explicit administrator activation; no deactivation |
| Future impact | reviewed named control Account FKs can be added later |
| Status | **FROZEN** |

## AFD-018 — Opening Balance operation

| Field | Record |
|---|---|
| Problem | Initial GL balances need real ledger truth, audit, uniqueness, and correction |
| Existing Core reality | No opening balance model; historical preservation pattern exists |
| Options considered | Account field; manual Journal only; one ever-posted Operation; status plus effect projection |
| Trade-offs | Effect-chain validation is more complex but resolves replacement/reactivation correctly |
| Decision | Dedicated Draft→Posted Operation owning real Journal; status stays Posted; validated effective/neutralized projection; one Draft-or-effective Tenant slot; no effective-again date may precede the greatest prior neutralization date |
| Why | Strong onboarding uniqueness without blocking corrected replacement |
| DB impact | operation table, partial UNIQUE, immediate date-floor guard, deferred constraints |
| Application impact | dedicated lifecycle and reversal-aware effect advance |
| Future impact | AR/AP subledger openings remain separate |
| Status | **FROZEN** |

## AFD-019 — Tenant isolation

| Field | Record |
|---|---|
| Problem | Global ULIDs and Laravel scoping do not prove relationship Tenant equality |
| Existing Core reality | Collections/audit use composite Tenant FKs; older modules are inconsistent |
| Options considered | application validation; direct independent FKs; composite FKs |
| Trade-offs | Redundant `tenant_id` and unique pairs increase schema verbosity |
| Decision | Every Accounting root/child/cross-root link uses direct Tenant ownership plus composite Tenant FK |
| Why | Database prevents Tenant A Journal → Tenant B Account/Period/source target |
| DB impact | `UNIQUE(tenant_id,id)` targets and composite FKs |
| Application impact | all queries still Tenant-scoped |
| Future impact | Remains required even if RLS is later adopted |
| Status | **FROZEN** |

## AFD-020 — Actor evidence

| Field | Record |
|---|---|
| Problem | Preserve who acted without allowing cross-Tenant actors or deletion loss |
| Existing Core reality | User removal archives rows; actor FKs are mixed SET NULL/RESTRICT; TenantUser pair is unique |
| Options considered | User FK SET NULL; User FK RESTRICT; composite membership RESTRICT |
| Trade-offs | Physical User deletion is blocked after Accounting evidence, intentionally |
| Decision | All Accounting actor FKs are same-Tenant composite and RESTRICT; active state checked at event time |
| Why | Strongest historical control evidence |
| DB impact | composite FKs to TenantUser |
| Application impact | lock/recheck membership/User |
| Future impact | Membership may become inactive without invalidating history |
| Status | **FROZEN** |

## AFD-021 — Accounting audit

| Field | Record |
|---|---|
| Problem | Need durable control provenance without duplicating ledger |
| Existing Core reality | Collection audit is append-only, same transaction, JSONB context; no global audit platform |
| Options considered | logs only; universal audit platform now; dedicated Accounting audit |
| Trade-offs | Dedicated table precedes future convergence but avoids delaying/overgeneralizing Accounting |
| Decision | Append-only AccountingAudit with closed events/subjects, same-Tenant actor/subject validation, small JSON context |
| Why | Auditable control transitions and atomic rollback |
| DB impact | audit table, event/subject/context checks, immutability triggers |
| Application impact | mandatory recorder within caller transaction |
| Future impact | Can feed a future audit read platform without replacing ledger |
| Status | **FROZEN** |

## AFD-022 — Cross-module transaction ownership

| Field | Record |
|---|---|
| Problem | Source transition and Journal cannot diverge |
| Existing Core reality | Modules share one Laravel/PostgreSQL database and use transactional Actions |
| Options considered | Accounting commits independently; async queue/outbox; caller-owned same transaction |
| Trade-offs | Modules must obey a cross-domain lock contract; atomic correctness is preserved |
| Decision | Business Action owns outer transaction, locks source first, calls Accounting on same connection; Accounting cannot commit/retry independently |
| Why | Source + Journal + both audits become one commit |
| DB impact | unique/FKs participate in same transaction |
| Application impact | internal transaction-required Posting method |
| Future impact | Separate databases require a new architecture |
| Status | **FROZEN** |

## AFD-023 — Concurrency, waits, and retries

| Field | Record |
|---|---|
| Problem | Critical races and indefinite waits can corrupt or exhaust production |
| Existing Core reality | Row locks, deterministic flows, 3-attempt transactions, and worker concurrency tests exist |
| Options considered | optimistic checks; tenant-wide serialization; targeted lock order with bounded waits |
| Trade-offs | More explicit code/tests; normal postings retain concurrency |
| Decision | Published lock hierarchy, sorted rows, local 5s lock timeout, outer max-3 retry for deadlock/serialization only |
| Why | Prevents races and limits operational blocking |
| DB impact | row/coordination locks in triggers/queries |
| Application impact | one retry owner and semantic concurrency errors |
| Future impact | Timeout may be lowered after measurement; lock order is compatibility contract |
| Status | **FROZEN** |

## AFD-024 — Authorization approach

| Field | Record |
|---|---|
| Problem | Define granular accounting actions without inventing a platform |
| Existing Core reality | Role constants and Support authorization classes; no permission tables |
| Options considered | new RBAC tables; ad-hoc checks; semantic capabilities mapped to roles |
| Trade-offs | Current mapping is coarse, but domain API stays stable for future RBAC |
| Decision | AccountingAuthorization maps named capabilities to administrators/accountant; reopen/settings admin-only |
| Why | Reuses verified convention and avoids speculative framework |
| DB impact | none beyond actor evidence |
| Application impact | centralized capability methods |
| Future impact | persisted permissions may replace adapter, not Actions |
| Status | **FROZEN** |

## AFD-025 — Financial reporting readiness

| Field | Record |
|---|---|
| Problem | Support reports later without cached truth now |
| Existing Core reality | Reporting is planned; no Accounting balances exist |
| Options considered | stored Account balances; assume P&L is already closed; derive all presentation values from immutable ledger |
| Trade-offs | Initial reports query Lines and Balance Sheet presentation must derive the unclosed P&L remainder explicitly; optimization waits for evidence |
| Decision | Posted Lines plus Account type/classification/hierarchy/date/number are authoritative; Balance Sheet uses Posted Asset/Liability/Equity ending balances plus derived unclosed cumulative earnings/loss from the remaining Posted Revenue/Expense ending balances through the as-of date; closing Journals participate in those same balances, so amounts transferred into Equity are not also retained in the derived term; no stored balances |
| Why | Prevents synchronization contradictions |
| DB impact | reporting indexes only |
| Application impact | future queries derive exact signed balances and test unclosed, partially closed, and fully closed earnings without source-label inference |
| Future impact | A closing engine may post ordinary Journals; materialized projections may be rebuildable, never authoritative |
| Status | **FROZEN** |

## AFD-026 — Trigger timing

| Field | Record |
|---|---|
| Problem | Cross-row invariants need DB enforcement without confusing locks and validation |
| Existing Core reality | Immediate PL/pgSQL triggers are already used; deferred Accounting constraints do not exist |
| Options considered | all application; all deferred; immediate posting/lifecycle plus narrowly deferred opening consistency |
| Trade-offs | Opening validator is complex but transactional multi-row state can settle before commit |
| Decision | Immediate triggers for posting/lifecycle/immutability; deferred constraint triggers only for final Opening cross-table consistency |
| Why | Correct timing and clear shared-lock ownership |
| DB impact | named trigger/function set |
| Application impact | status updated last; effect transaction validated at commit |
| Future impact | No trigger may be treated as a substitute for lock protocol |
| Status | **FROZEN** |

## AFD-027 — Deletion policy

| Field | Record |
|---|---|
| Problem | Cleanup convenience can erase master/control/accounting evidence |
| Existing Core reality | Historical preservation is frozen; archive patterns exist |
| Options considered | delete unused entities broadly; soft deletes; narrow Draft deletion only |
| Trade-offs | Mistaken Account/Period remains visible/audited, but history is unambiguous |
| Decision | Only manual Journal Draft and Opening Balance Draft aggregate can delete; Accounts/Periods/Settings/Posted data cannot |
| Why | Small explicit cleanup surface with durable truth |
| DB impact | restrictive FKs and deletion triggers |
| Application impact | archive Account/correct Period instead |
| Future impact | Retention needs separate legal/product policy |
| Status | **FROZEN** |

## AFD-028 — Actual PostgreSQL server version

| Field | Record |
|---|---|
| Problem | Record environment capability without guessing |
| Existing Core reality | No pinned/reachable server version was available in the inspected checkout |
| Options considered | infer from Laravel; guess current version; mark unverified and preflight |
| Trade-offs | Implementation must capture one additional fact |
| Decision | Record `SHOW server_version` before migration; use no optional/version-edge extension in the plan |
| Why | Evidence must replace assumption |
| DB impact | none until preflight |
| Application impact | deployment/migration check |
| Future impact | Supported-version policy can be frozen with environment evidence |
| Status | **NOT VERIFIED** (non-blocking to architecture) |

## AFD-029 — Multi-currency and FX

| Field | Record |
|---|---|
| Problem | USD exists operationally but FX requires rates/base/transaction truth |
| Existing Core reality | Tenant/Contract allow SAR/USD; no FX engine |
| Options considered | add placeholder currency/rate fields; implicit conversion; defer |
| Trade-offs | USD cannot post in v1; avoids false accounting |
| Decision | Defer transaction currency, base currency, rates, realized/unrealized FX, and revaluation |
| Why | Not a prerequisite for a correct SAR ledger |
| DB impact | no FX columns/tables |
| Application impact | reject non-SAR source |
| Future impact | Requires dedicated architecture and migrations |
| Status | **DEFERRED** |

## AFD-030 — Subledgers and broader ERP

| Field | Record |
|---|---|
| Problem | Future AR/AP/tax/costing must not inflate foundation scope |
| Existing Core reality | Payments, expenses, payables, revenue recognition, and financial reports are planned |
| Options considered | generic subledger/dimension/workflow framework now; explicit future integrations |
| Trade-offs | Future modules require their own review; foundation stays understandable |
| Decision | Defer invoices, payments UI, vendors/AP, VAT/ZATCA, bank reconciliation, payroll, fixed assets, inventory, costing dimensions, budgets, approvals, full reports |
| Why | None is required to guarantee v1 ledger truth |
| DB impact | no speculative tables/fields |
| Application impact | only stable extension contracts documented |
| Future impact | Each module registers source type and named Settings Account FKs |
| Status | **DEFERRED** |

## AFD-031 — Persisted permission framework

| Field | Record |
|---|---|
| Problem | Future granular tenant permissions may supersede global roles |
| Existing Core reality | Documentation mentions permissions, but baseline has no permission records/tables |
| Options considered | build now; pretend it exists; preserve semantic capabilities and defer storage |
| Trade-offs | Current role mapping is coarse but truthful |
| Decision | Defer new RBAC schema; retain capability names as adapter boundary |
| Why | Authorization storage is a Core-wide concern, not an Accounting prerequisite |
| DB impact | none |
| Application impact | current role Support class |
| Future impact | swap mapping without changing domain use cases/evidence |
| Status | **DEFERRED** |
