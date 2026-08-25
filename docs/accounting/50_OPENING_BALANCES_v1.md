# Accounting Foundations v1 — Opening Balances

Version: 1.0
Status: **FROZEN + DDL-READY**

## 1. Purpose

OpeningBalanceOperation is the auditable initial general-ledger onboarding
workflow for one Tenant. It is not an `opening_balance` column on Account and it
does not bypass Journal posting.

Every effective opening balance is represented by:

```text
OpeningBalanceOperation
        +
one real Posted JournalEntry
        +
real immutable JournalLines
```

The operation controls onboarding and replacement. The Journal and Lines are
ledger truth.

## 2. Scope

v1 covers general-ledger opening balances only. It may post to any active
posting Account needed to represent the Tenant's balanced trial balance at the
cutover date.

It does not create:

- customer-by-customer Accounts Receivable opening documents;
- vendor-by-vendor Accounts Payable opening documents;
- invoice, payment, bank, inventory, fixed-asset, or tax subledger history;
- automatic retained-earnings or balancing Lines;
- an opening-balance field on Account.

Future AR/AP subledger onboarding must reconcile its documents to already
posted GL control balances under a separately reviewed migration/workflow. It is
not a prerequisite for the v1 ledger.

## 3. Operation model

An Operation contains:

| Field | Rule |
|---|---|
| `id` | ULID |
| `tenant_id` | Required Tenant owner |
| `status` | `draft` or `posted` |
| `effect_state` | Null in Draft; `effective` or `neutralized` after posting |
| `accounting_date` | Required `DATE` in `2000-01-01..9999-12-31`; must equal root Journal `entry_date` |
| `journal_entry_id` | Required same-Tenant owned Journal; Draft or Posted in lockstep |
| `latest_effect_journal_entry_id` | Null in Draft; root/latest terminal Journal after posting |
| `created_by`, `created_at` | Required same-Tenant actor/time |
| `updated_by`, `updated_at` | Required Draft last editor/time; frozen on post |
| `posted_by`, `posted_at` | Null in Draft; required after posting |
| `effect_updated_by`, `effect_updated_at` | Null in Draft; set at root posting and every reversal-chain advance |

The Operation has no stored amount. Its root Journal Lines determine all totals.

## 4. Draft construction

Creation occurs in one transaction:

1. lock AccountingSettings as the Tenant opening-slot coordination row;
2. require no other Draft or effective opening operation;
3. generate the Operation ULID and Journal ULID in memory;
4. insert a Journal as `draft`, `origin='opening_balance'`, source type
   `opening_balance_operation`, and `source_id=operation.id`;
5. insert the Operation pointing to that Journal;
6. add zero or more valid Draft Journal Lines;
7. record `opening_balance.created`;
8. commit.

The Operation and Journal are addressable immediately, but neither affects the
ledger.

### 4.1 Draft edits

- AccountingSettings, the root Journal, and then the Operation are locked in
  the global deterministic order before an edit.
- Changing accounting date updates both records in the same transaction.
- Lines are edited through the Opening Balance use case, never the manual
  Journal endpoint.
- Individual Lines obey debit-XOR-credit and precision rules.
- The aggregate may remain incomplete/unbalanced until posting.

### 4.2 Draft delete

Only a Draft Operation may be deleted. The Action locks the coordination row,
root Journal, Operation, and Lines in that order, records
`opening_balance.draft_deleted`, then deletes the Operation followed by its
Draft Journal/Lines in the same transaction. A deferred delete validator rejects
an orphan opening-origin Draft Journal if the second half does not occur.

The audit subject contract permits this event to retain the deleted Operation
ID. No Posted row can be deleted.

## 5. Posting

Posting uses the common Journal engine and additionally requires:

- Operation status is Draft;
- root Journal status is Draft and origin/source point to the Operation;
- Operation and Journal dates match;
- no other Draft or effective Operation occupies the Tenant slot;
- accounting date satisfies the historical effect-date floor when prior
  neutralized Operations exist;
- all Accounts are active posting Accounts;
- the complete Journal is exactly balanced and has a positive debit total;
- an open Period contains the accounting date;
- source tuple uniqueness has not been consumed;
- authorized actor and transactional audit.

The transaction posts the root Journal, then changes Operation to:

```text
status                          = posted
effect_state                    = effective
latest_effect_journal_entry_id  = root journal ID
posted_by / posted_at           = current actor/time
effect_updated_by / at          = current actor/time
```

The root Journal receives a normal `JRN` number. There is no separate opening
balance numbering mechanism.

A deferred constraint trigger validates the final Operation/Journal state at
commit because both rows transition inside one transaction. The trigger does
not replace locks or application validation.

## 6. Strong initial-operation uniqueness

The business rule is:

> A Tenant may have at most one operation that is currently being prepared or
> has an effective Posted accounting impact.

A partial unique index enforces one Tenant slot over rows satisfying:

```text
status = 'draft'
OR (status = 'posted' AND effect_state = 'effective')
```

Consequences:

- multiple historical neutralized Operations are allowed;
- two Draft Operations are impossible;
- a Draft cannot coexist with an effective Posted Operation;
- two effective Posted Operations are impossible;
- application pre-checks are not authoritative; the partial unique index is.

This is a v1 initial-onboarding rule, not a promise that future subledger
onboarding will use the same aggregate.

### 6.1 Historical effect-date floor

Current-state uniqueness alone is insufficient. A replacement or reactivation
backdated before a prior operation's neutralizing terminal Journal would make
two opening effects overlap in an as-of ledger report.

Therefore, when any prior OpeningBalanceOperation is neutralized, a new Draft
Operation's `accounting_date`, and the Journal that makes any operation effective
again, must be greater than or equal to the greatest `entry_date` of every other
neutralized operation's `latest_effect_journal_entry_id`.

The Settings coordination lock serializes the comparison. An immediate
operation guard protects Draft create/date edit, and the deferred final-state
validator protects posting/reactivation. Equality is allowed so a reversal and
replacement can take effect on the same accounting date. A later date is an
explicit gap; an earlier date that would create overlapping opening effects is
forbidden.

## 7. Reversal and effect projection

### 7.1 Journal status does not change

The Operation remains `status='posted'` after its root Journal is reversed. The
root Journal also remains Posted. Reversal creates a new exact Journal.

The separate `effect_state` answers whether the entire linear chain currently
leaves the root opening impact active:

- `effective` — an even number of reversal edges from root to terminal
  (`0, 2, 4, ...`);
- `neutralized` — an odd number (`1, 3, 5, ...`).

### 7.2 Latest-effect pointer

`latest_effect_journal_entry_id` points to the terminal Journal in the root's
linear reversal chain.

A deferred PostgreSQL validation walks from that Journal back through
`reverses_journal_entry_id` and proves:

1. the chain reaches the Operation's root Journal;
2. every relationship is same-Tenant and exact;
3. the latest pointer has no direct reversal child;
4. chain parity matches `effect_state`;
5. effect actor/time fields are coherent;
6. an effective terminal date is not earlier than another operation's later
   neutralizing terminal date.

Generic Journal uniqueness already prevents branching. Posted immutability and
chronological target rules prevent a cycle.

### 7.3 Projection advance

When the Reversal Action targets the terminal Journal of an opening chain, it:

1. identifies the root Operation;
2. locks AccountingSettings first, then every existing target/root Journal in
   deterministic role/ULID order, then the Operation;
3. revalidates terminal identity;
4. posts the exact reversal through the common engine;
5. toggles `effect_state`;
6. advances `latest_effect_journal_entry_id`;
7. sets effect actor/time;
8. records the appropriate opening audit event;
9. commits only if partial uniqueness and deferred validation succeed.

The effect projection is control metadata, not a mechanism for excluding Lines
from ledger reports.

## 8. Correction and replacement semantics

Posted opening values are never edited. A full replacement is:

1. exact-reverse the terminal Journal of the currently effective Operation;
2. observe that Operation becomes `posted/neutralized` and record its terminal
   accounting date as the effect-date floor;
3. create a new Draft Operation on or after that floor;
4. enter the corrected full balanced opening trial balance;
5. post the replacement as a new effective Journal.

The old root, its reversal, audit, and the replacement all remain visible.

### 8.1 Reversal-of-reversal

Reversing the current terminal reversal reactivates the old Operation. It is
allowed only if the Tenant slot is free—there is no Draft and no other effective
Operation.

If a replacement is currently Draft or effective, the coordination lock and
partial unique index reject reactivation. If all other operations are
neutralized, reactivation must still satisfy the historical effect-date floor.
The user must explicitly resolve the replacement first. This prevents current
or backdated overlapping initial effects while retaining the general linear
reversal model.

### 8.2 No partial reversal

Opening Balance uses the same exact-only reversal rule. A correction to one
opening Account still requires exact reversal of the complete root/replacement
Journal followed by a complete corrected Operation. This is intentionally strict
for initial onboarding integrity.

## 9. Accounting date and Period

- The Operation date is the cutover accounting date.
- It must equal the root Journal date.
- Root posting requires an open Period containing it.
- Exact reversal uses its own explicit date, not the root date automatically.
- If the root Period is closed, correction posts in another open Period unless
  an administrator separately reopens the original Period.
- A replacement may use the same date as the latest prior neutralization or a
  later date; the historical effect-date floor forbids an earlier date.
- Every reversal independently remains no earlier than the Journal it directly
  reverses.

The system does not infer the cutover date from `created_at` or `posted_at`.

## 10. Balancing behavior

Accounting does not create a plug Line or search for a magic opening-equity
Account. The supplied Lines must balance exactly.

If an opening equity/retained-earnings Account is required, it is an explicitly
selected active posting Account in the Chart. The UI may display the remaining
imbalance while drafting; it cannot post a nonzero difference.

Duplicate Accounts on separate Lines remain allowed, although the UI may choose
to consolidate them before posting.

## 11. Source and idempotency

The root Journal uses:

```text
origin      = opening_balance
source_type = opening_balance_operation
source_id   = Operation ULID
```

The non-manual source unique index prevents two root Journals for one Operation.
The Operation's same-Tenant unique `journal_entry_id` relationship prevents one
Journal from being owned by two Operations.

Posting a request again after commit returns the existing Posted root only when
the requested Operation and Journal already correspond. A mismatched retry is a
source conflict, not a second posting.

## 12. Audit events

| Event | When | Required context |
|---|---|---|
| `opening_balance.created` | Draft aggregate created | root Journal ID, accounting date |
| `opening_balance.draft_deleted` | Draft aggregate removed | root Journal ID, reason when supplied |
| `opening_balance.posted` | Root Journal posts | root Journal ID/number, Period ID, total debit, line count |
| `opening_balance.reversed` | chain parity becomes neutralized | root ID, new reversal ID/number, target ID, reason |
| `opening_balance.reactivated` | chain parity becomes effective | root ID, new reversal ID/number, target ID, reason |

Amounts in context are summary strings only. Complete Lines are not copied.
Audit insertion is inside the same transaction.

## 13. Concurrency and lock order

All Opening slot mutations start with the same AccountingSettings row:

```text
actor membership and User
→ AccountingSettings
→ all existing terminal/target/root Journals in deterministic role/ULID order
→ OpeningBalanceOperation
→ Lines of the locked Journals when needed
→ AccountingPeriod
→ Accounts sorted by ULID
→ JRN number row
→ audit inserts
```

Creation/deletion/posting/reactivation all use this parent lock. The partial
unique index remains the final authoritative current-slot guard; the immediate
and deferred date-floor checks separately protect as-of historical overlap.

The Reversal Action may perform a non-locking pre-read to discover whether the
target belongs to an opening chain. It must then acquire the order above and
re-read/revalidate every fact under locks; the pre-read cannot authorize a
write.

## 14. Database enforcement summary

| Invariant | Enforcement |
|---|---|
| valid status/effect pairs | CHECK |
| at most one Draft-or-effective slot | partial UNIQUE |
| same-Tenant Operation/Journal links | composite FKs |
| one Operation per root Journal | UNIQUE on same-Tenant journal link |
| source points back to Operation | deferred constraint trigger |
| Operation/root dates and statuses match | deferred constraint trigger |
| replacement/reactivation not backdated across prior neutralization | Settings lock + immediate date guard + deferred final-state trigger |
| latest pointer is terminal and parity matches effect | deferred constraint trigger |
| posted core fields immutable | mutation trigger |
| effect fields advance only to a newly Posted exact reversal terminal | mutation + deferred validation triggers |
| actor belongs to Tenant | composite membership FKs |
| actual debit/credit truth | common Journal/Line posting constraints |

## 15. Explicitly forbidden shortcuts

- Account-level opening amount fields;
- an unbalanced opening import followed by later repair;
- direct insert of a Posted opening Journal;
- editing a Posted opening Line;
- changing Operation status to `reversed`;
- clearing or overwriting the original Journal link;
- marking neutralized without a real exact reversal;
- creating a second initial Operation while another is Draft/effective;
- automatic plug Account by code;
- representing AR/AP opening documents in this aggregate.
