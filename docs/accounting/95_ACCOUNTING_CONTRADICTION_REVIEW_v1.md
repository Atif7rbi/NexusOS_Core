# Accounting Foundations v1 — Architecture Contradiction Review

Version: 1.0
Review result: **PASS — FROZEN + DDL-READY**
Review scope: the complete package in `docs/accounting/`

## 1. Method

This review was performed after defining aggregate lifecycle, PostgreSQL
constraints, locks, audit, source identity, and integration contracts. It tests
each decision against every other document and the verified Core baseline.

Classification:

- **Resolved** — a concrete invariant/contract removes the contradiction.
- **Deferred safely** — outside scope and cannot invalidate v1 truth.
- **Review point** — implementation evidence is still required but the
  architecture decision is complete.
- **Blocker** — a material unresolved choice; none remain.

## 2. Required contradiction set

| Potential contradiction | Finding | Resolution | Status |
|---|---|---|---|
| Posted immutability vs correction | Updating a Posted Journal would destroy truth | Target remains Posted/immutable; correction is a new exact reversal or separately identified adjustment Journal | Resolved |
| Posted immutability vs `reversed` state | A physical state would require mutating the target | No `reversed` status/fields; indexed reversal relationship supplies current chain | Resolved |
| Archived Account vs exact reversal | Blocking all archived Accounts would make historical correction impossible | Ordinary/opening/business posting requires active; exact reversal alone may use archived same-Tenant posting Accounts and must match target Lines | Resolved |
| Archived Account vs new posting race | Application precheck can go stale | Archive and posting lock the same Account row; posting trigger rechecks state | Resolved |
| Active Account beneath archived group | Checking only the posting leaf would make an archived branch partly usable | hierarchy/lifecycle triggers require every active Account's ancestors active; group archive requires no active descendant | Resolved |
| Closed Period vs reversal | Historical target may be closed | Reversal has its own explicit date and must use an open Period; target Period may remain closed | Resolved |
| Reopen vs immutable Posted Journal | Reopen could appear to alter earlier posting | Reopen changes only Period control state; stored Period ID/header/Lines remain immutable | Resolved |
| Period boundary mutation vs stored Period | Boundary edit could move historical Journals | Boundary mutation forbidden after any Posted Journal references Period | Resolved |
| Period resolution vs Journal date | Storing only date could make historical Period ambiguous | Period is resolved from Tenant/date at posting, locked/open-checked, then stored immutably | Resolved |
| Draft Period link vs range changes | Early binding would become stale | Draft has null Period; resolve only on posting | Resolved |
| Opening uniqueness vs reversal | Keeping Operation `posted` could block a legitimate replacement forever | `status` remains Posted; separate validated `effect_state`; partial unique covers Draft or effective, not neutralized history | Resolved |
| Opening replacement vs reversal-of-reversal | Reactivating old root could coexist with replacement | all opening-slot/effect operations lock Settings; partial unique rejects reactivation while another Draft/effective operation exists | Resolved |
| Opening replacement date vs as-of history | A backdated replacement/reactivation could overlap a previously effective opening in historical reports | new/effective-again dates cannot precede the greatest prior neutralizing terminal date; Settings lock plus immediate/deferred guards enforce the floor | Resolved |
| Opening projection vs ledger truth | Mutable effect metadata could hide or fabricate money | Reports sum all Posted Lines; deferred trigger proves latest terminal/parity; projection is control-only | Resolved |
| Tenant FKs vs actor FKs | `users(id)` alone can persist Tenant A actor on Tenant B record | all Accounting actors use composite `(tenant_id,user_id) → tenant_users` restrictive FK | Resolved |
| Active actor vs historical removal | FK to only active membership would invalidate history | FK proves membership identity; Action requires active state at event time; later status changes are allowed | Resolved |
| System Owner authority vs no membership | Shared authority alone could bypass Tenant evidence | Accounting boundary requires active membership before role mapping; no null/fake actor | Resolved |
| Numbering vs rollback | Allocating before commit could create or reuse gaps | Counter update is late and in same outer transaction; rollback undoes it; committed numbers never decrement/reuse | Resolved |
| Numbering year vs operational timestamp | Posting on a later day could put number in wrong year | number year derives only from explicit `entry_date` | Resolved |
| Numbering year range vs Period/date range | Existing counter rejects years outside 2000..9999 | Journal dates and Period boundaries use the same frozen supported range | Resolved |
| Nested allocator retry vs outer atomicity | Current generator owns a nested transaction/retry | implementation must expose a transaction-participating allocator; outer use case owns retries; existing table/algorithm reused | Resolved contract; implementation review point |
| Existing counter unsigned intent vs PostgreSQL truth | Repository migration has no explicit named nonnegative CHECK and current service increments through PHP int | preflight inspects live schema/data; additive nonnegative CHECK; PostgreSQL `UPDATE ... RETURNING`; exhaustion fails closed | Resolved contract; implementation review point |
| Source idempotency vs concurrent retries | Two workers can pass an application `exists` check | source root lock plus partial unique source tuple; loser reloads in fresh transaction | Resolved |
| Source idempotency vs changed accounting rule | Blindly returning existing Journal could conceal drift | canonical requested header/ordered Lines compared; mismatch is source conflict | Resolved |
| Unknown commit vs duplicate posting | Client may not know whether commit succeeded | retry same stable source tuple; never generate retry-only source ID | Resolved |
| Generic source vs arbitrary strings | A free text `source_type` is not authoritative | immutable migration-owned source catalog and composite FK; no business key exists until its module migration | Resolved |
| Generic source vs referential FK | One generic ID cannot FK to many source tables | owning module locks/validates its source; source tuple unique; same-transaction contract; inconsistency fails closed | Resolved with explicit boundary |
| Audit vs transaction rollback | Log/after-commit audit could disagree with mutation | durable AccountingAudit inserts in same transaction; failure rolls back mutation; logs secondary | Resolved |
| Audit vs ledger truth | Duplicated Line payload could diverge | audit never stores complete Lines and is never summed; Journal/Lines authoritative | Resolved |
| Audit subject identity vs Draft deletion | A strict polymorphic FK would prevent retained create/delete evidence | trigger validates subject/Tenant at insertion; the named Draft deletion paths retain every prior audit row and the deletion event under the former ULID | Resolved |
| Account name edits vs historical reports | Changing a label changes historical presentation | Account ID/code/amount remain; name is explicitly current master label and change is audited; code/structure freeze after history | Resolved |
| Account hierarchy edit vs descendant history | Moving a group can rewrite old aggregates even if group has no Lines | structural history check covers the entire moved/changed subtree | Resolved |
| Account creation under historical parent | Overly broad freeze could prohibit useful new Accounts | new history-free child is allowed; no existing Line moves | Resolved |
| Group classification vs root hierarchy | A root Asset group cannot truthfully be current/non-current | group classification is null; only posting Accounts carry report classification | Resolved |
| Duplicate Accounts on Lines vs totals | Merging could change memos/semantic components | duplicates allowed; line order explicit; balance validated on exact rows | Resolved |
| SAR Accounting vs Tenant USD support | Existing Core accepts USD | activation requires Tenant SAR; USD sources cannot post; pre-existing USD business history remains operational | Resolved |
| Tenant currency mutation after history | Changing to USD would invalidate ledger policy | Tenant currency trigger freezes it once Settings exists, even before first Posted Journal | Resolved |
| Tenant currency vs SystemSetting currency | Two fields can differ | Tenant is activation authority; SystemSetting is presentation and cannot affect ledger | Resolved |
| Settings immutability vs future control Accounts | Future modules need explicit Account references | forward reviewed migrations add named FKs and narrowly controlled updates; v1 fields stay immutable | Deferred safely |
| Business transition vs Accounting failure | Source could commit without Journal | same database/connection/outer transaction required; no queue fallback | Resolved |
| Accounting commit vs source failure | Journal could commit independently | internal Business posting cannot start or commit transaction; caller owns commit | Resolved |
| Draft-first system posting vs stale source reservation | Business/reversal must be inserted Draft, but that Draft must not survive a commit | deferred Journal final-state guard permits in-transaction construction and rejects committed Business/reversal Drafts | Resolved |
| Source reversal vs Business lifecycle | Reversing ledger does not necessarily cancel business record | Accounting reversal is separate; combined future workflow requires owning module transaction contract | Resolved boundary |
| Partial reversal vs exact history | Partial reversal labeled as reversal would be ambiguous | exact reversal only; adjustment is a separately sourced Journal | Resolved |
| Reversal-of-reversal vs branching/cycle | Flexible reversal graph could become ambiguous | one direct reversal per Posted node; target must be terminal; immutable chronological linear chain | Resolved |
| Deferred trigger vs locking | Deferred validation alone cannot stop two valid-looking writers | shared Settings/row locks and partial UNIQUEs provide concurrency; deferred trigger only validates final opening state | Resolved |
| SQLite fallback vs PostgreSQL-specific integrity | Local default could silently omit constraints | Accounting migration/test fail closed unless `pgsql`; fallback is documented existing risk, not a supported Accounting runtime | Resolved contract; implementation review point |
| Actual PostgreSQL version unknown vs DDL readiness | Feature support might be guessed | no optional extension/version-edge feature; version recorded in implementation preflight; current Core already uses required feature families | Review point, non-blocking |
| Current global role vs Tenant permissions | Role is not stored per membership | active membership plus current Core role adapter is explicit v1; future RBAC can preserve semantic capabilities | Deferred safely |
| Financial reporting vs no stored balances | Reports might be slow later | authoritative Posted Lines, dates, classifications, hierarchy, and indexes support reports; optimize only after measurement | Resolved |

## 3. Lifecycle cross-check

### Account

- Archive does not change type, classification, hierarchy, code, or Lines.
- Restore does not create posting history.
- Structural mutation and posting share Account locks.
- Delete is absent, so all Journal FKs remain valid.

Result: no lifecycle path can orphan or reinterpret a Posted Line.

### Journal

- Every insert begins Draft.
- Posting validates the complete final aggregate.
- Header and children become immutable together.
- Reversal creates another complete Posted aggregate.

Result: no status transition leaves partially posted data.

### AccountingPeriod

- Posting and close/reopen share Period row lock.
- Reopen does not edit boundaries with history.
- Draft has no stale Period assignment.

Result: open-state and date assignment cannot race into contradiction.

### OpeningBalanceOperation

- Status truth and current effect truth are separate.
- Partial unique slot and chain parity cover replacement/reactivation.
- Root and reversal Journals remain ordinary immutable ledger entries.

Result: initial-opening uniqueness does not erase correction history or prevent
a valid replacement.

## 4. Tenant isolation cross-check

Every Accounting root references both Tenant and activated Settings. Every
child/cross-root relation includes Tenant in its FK. Every actor includes Tenant
in its membership FK. Reversal target, Period, Account, Opening root/latest
Journal, and audit subject are all same-Tenant validated.

No relationship relies solely on request validation or globally unique ULIDs.

Result: cross-Tenant ledger composition is structurally blocked.

## 5. Lock-order cross-check

The reviewed protocols contain no operation that acquires a lower-level lock and
then returns to a higher-level required lock:

- Business source precedes Accounting;
- Settings precedes structural/opening rows;
- Journal precedes Lines;
- Period precedes Accounts in posting;
- Accounts precede number row;
- audit inserts occur last.

Period close uses only Period and audit. Account lifecycle uses Settings then
Accounts and never Journal/Period. Normal posting does not use Settings. These
paths can wait but do not form an intended lock cycle.

Direct unsupported DML can violate order and become a deadlock victim, but
database rollback preserves integrity.

Result: deterministic application lock order is coherent.

## 6. DDL versus application cross-check

Every cross-row trigger has an application precheck and semantic failure. Every
critical application precheck has an authoritative DB constraint, trigger,
shared row lock, or explicit reason why it is contextual authorization only.

Required audit-event emission is a transactional Application/Domain contract,
matching the verified Core recorder convention. PostgreSQL enforces subject and
actor Tenant integrity, JSON object shape, append-only behavior, and atomic
rollback, but it does not attempt to synthesize use-case reason/context or prove
generic event completeness through ledger triggers.

The only generic relationship without a physical source-table FK is Business
source identity. Its owner lock, same transaction, immutable source catalog,
unique tuple, and fail-closed reconciliation contract are explicit.

Result: no critical invariant is application-precheck-only.

## 7. Deferred scope safety

Deferred features do not require a v1 field to hold invented placeholder data:

- multi-currency adds explicit foreign/base amounts and FX semantics later;
- AR/AP adds subledger documents and Settings control FKs later;
- project costing adds only justified attribution later;
- approvals wrap commands without changing Posted Journal immutability;
- reporting derives from ledger before any optional cache;
- service principals require a real identity model before null actor support.

Result: deferred scope has extension boundaries and does not weaken current
truth.

## 8. Remaining known review points

These are not architecture blockers, but external review and implementation must
pay specific attention to them:

1. **Opening chain deferred trigger complexity** — tests must prove terminal,
   parity, slot uniqueness, and reversal/replacement races through direct SQL and
   concurrent workers.
2. **Existing number allocator API** — refactor transaction ownership without
   changing Project numbering behavior.
3. **PostgreSQL environment** — record actual server version and enforce `pgsql`
   despite existing SQLite fallback/example defaults.
4. **Global current role model** — do not mistake semantic capability names for
   an already implemented permissions database.
5. **Trigger exception translation** — every named constraint/race must map to a
   stable public/domain error without raw SQL leakage.
6. **Migration function security** — trigger functions require controlled search
   path and application role privileges.

## 9. Blockers

```text
None.
```

No material posting, reversal, tenant, Period, opening-balance, numbering,
currency, audit, or concurrency decision remains unresolved.

## 10. Conclusion

Accounting Foundations v1 passes the internal contradiction review and is:

```text
FROZEN + DDL-READY
```

This is a package conclusion pending independent PR review. It does not authorize
migrations or implementation before explicit approval.
