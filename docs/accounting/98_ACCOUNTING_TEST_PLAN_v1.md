# Accounting Foundations v1 — Implementation Test Plan

Version: 1.0
Status: **FROZEN implementation acceptance plan**

## 1. Purpose

This plan defines tests required after architecture approval. No implementation
tests are part of the documentation-only assignment.

Accounting cannot be declared complete from happy-path API tests. Database
constraints, direct writes, rollbacks, and real concurrent PostgreSQL sessions
are mandatory.

## 2. Environment contract

All database/integration/concurrency tests run on PostgreSQL.

The suite must:

- assert `DB::getDriverName() === 'pgsql'`;
- use the existing isolated `_testing` database safety contract;
- abort when Laravel config cache resolves another database;
- record PostgreSQL server version in test diagnostics;
- never silently skip Accounting DB tests under SQLite;
- create isolated schemas/databases for multi-process concurrency tests;
- clean only its validated test schema.

Unit tests may run without a database when they test pure value/policy logic.

## 3. Suggested suite organization

```text
tests/Unit/Accounting/
tests/Feature/Accounting/AccountingMigrationTest.php
tests/Feature/Accounting/AccountingDatabaseConstraintTest.php
tests/Feature/Accounting/AccountLifecycleTest.php
tests/Feature/Accounting/AccountingPeriodTest.php
tests/Feature/Accounting/JournalPostingTest.php
tests/Feature/Accounting/JournalReversalTest.php
tests/Feature/Accounting/OpeningBalanceTest.php
tests/Feature/Accounting/AccountingAuditTest.php
tests/Feature/Accounting/AccountingAuthorizationTest.php
tests/Feature/Accounting/AccountingIntegrationTest.php
tests/Feature/Accounting/AccountingConcurrencyTest.php
tests/Support/Accounting/*_worker.php
```

Exact filenames may follow implementation conventions; coverage is normative.

## 4. Migration and catalog tests

### Required cases

- migration fails closed on non-`pgsql` driver;
- clean PostgreSQL migration succeeds;
- every table, column type/nullability/default exists as planned;
- every named FK/CHECK/UNIQUE/partial index exists;
- every trigger/function exists and is enabled;
- source catalog contains exactly the two Accounting internal rows initially;
- source catalog rejects update/delete and invalid key/origin;
- application role cannot TRUNCATE Accounting tables or disable triggers;
- empty-schema down migration removes objects in safe order;
- empty rollback removes only the named sequence CHECK introduced by this
  package and preserves any pre-existing equivalent constraint;
- down migration refuses when any AccountingSettings/data/`JRN` consumption
  exists;
- re-running migration does not duplicate catalog rows;
- an unclassified pre-existing `JRN` counter blocks preflight;
- no customer-specific Chart, identity, or data is seeded.

## 5. AccountingSettings and currency tests

- active SAR Tenant can activate once;
- concurrent activation yields exactly one row and one audit;
- USD Tenant activation is rejected by Domain and direct database attempt;
- Settings currency other than SAR fails CHECK;
- Settings update/delete fails directly;
- changing Tenant currency after activation fails directly;
- activation racing Tenant currency change serializes and cannot leave
  Settings/SAR mismatch;
- changing unrelated SystemSetting currency does not alter ledger policy;
- Accounting aggregate insert before activation fails FK;
- actor from another Tenant fails composite FK;
- inactive/removed membership fails application activation even though its
  historical membership row exists;
- only Tenant administrator authority can activate.

## 6. Account row and vocabulary tests

- all five Account types accepted; any other value rejected;
- every allowed classification/type pair accepted;
- every invalid pair rejected directly;
- group requires null classification;
- posting requires classification;
- valid canonical codes accepted;
- lower-case, padded, blank, malformed, and overlength codes rejected or
  normalized before persistence as specified;
- code unique within Tenant across active/archived rows;
- same code allowed across Tenants;
- blank name/nonblank-null-description rules enforced;
- parent self-reference rejected;
- parent from another Tenant rejected by composite FK;
- parent of different type rejected;
- posting Account used as parent rejected;
- creating/moving an active Account under an archived parent/ancestor rejected;
- direct cycle and multi-level cycle rejected;
- group may be empty;
- posting Account may not have children.

## 7. Account lifecycle/history tests

- create always active with coherent actor/time fields;
- descriptive edit active before/after history succeeds and audits exact changed
  fields;
- structural edit succeeds for history-free subtree;
- code/type/classification/kind/parent edit after Posted descendant history
  fails by Action and direct SQL;
- moving history-free subtree under historical parent succeeds;
- group→posting fails with children and succeeds when empty/history-free with a
  valid classification;
- posting→group fails after history;
- posting Account archive with history succeeds and retains reports;
- group archive fails with any active descendant;
- restore fails under archived ancestor and succeeds after ancestors restore;
- archived descriptive/structural edit fails;
- Account deletion always fails;
- concurrent archive versus ordinary posting has only valid outcomes:
  posting commits before archive, or archive commits and posting fails;
- exact reversal can use archived target Account;
- manual/business/opening posting cannot use archived Account.

## 8. Journal Line constraint tests

For direct inserts/updates test:

- debit positive/credit zero succeeds;
- credit positive/debit zero succeeds;
- both positive rejected;
- both zero rejected;
- negative values rejected;
- null values rejected;
- value beyond precision/range rejected;
- canonical two-decimal strings accepted; signs, exponent notation, grouping,
  leading zeroes, and missing/extra fractional digits rejected at the boundary;
- Amount parsing/addition/comparison uses `BigDecimal` and never casts through
  float/double; dependency metadata proves `brick/math` is direct, not merely
  transitive;
- more than two decimal places is rejected at every supported domain/API
  boundary; a direct-SQL test documents PostgreSQL `NUMERIC(19,2)` coercion
  rounding so no implementation mistakes that engine behavior for validation;
- nonblank memo rule;
- line number must be positive;
- line number unique within Journal/Tenant;
- same line number allowed in different Journals;
- cross-Tenant Journal/Account combinations rejected;
- duplicate Account on distinct line numbers allowed;
- Line insert/update/delete under Posted parent rejected;
- Draft parent allows owned Line mutation after parent lock.

## 9. Posting aggregate tests

### Valid cases

- balanced two-Line manual Journal posts;
- balanced multi-Line Journal with duplicate Accounts posts;
- exact large `NUMERIC(19,2)` values post within range;
- posted result has Period, number, actor/time, immutable source shape;
- number year equals entry-date year even when posting timestamp is another year.

Persistent manual Draft creation/deletion must emit their named audit events.
Opening Drafts emit only their dedicated opening events, and Business/reversal
transaction-local Drafts leave no Draft audit event.

### Invalid cases

- zero Lines, one Line, and noncontiguous numbering fail;
- gap, duplicate, or start-not-one line numbers fail;
- unbalanced totals fail by Domain and direct status UPDATE;
- zero-total Journal fails;
- group Account fails;
- archived ordinary Account fails;
- no containing Period fails;
- closed Period fails;
- cross-Tenant Period/Account fails;
- Journal dates below `2000-01-01` or above `9999-12-31` fail;
- malformed/unregistered source fails;
- direct INSERT as Posted fails;
- Business/reversal Journal inserted as Draft and posted in one transaction
  succeeds, while committing either origin as Draft fails at the deferred guard;
- explicit DELETE of a Business/reversal Draft fails; transaction rollback is
  the only failed-construction cleanup;
- caller-supplied number/Period/posted actor/time ignored/rejected;
- posting a Posted Journal fails;
- any Posted header/Line update/delete fails through Eloquent, query builder, and
  direct SQL.

### Rollback cases

- audit recorder failure rolls back Journal status, number counter, and source
  transition;
- late posting trigger failure rolls back counter increment;
- exception after source transition but before posting commit rolls back source;
- exception after Journal update but before commit rolls back all;
- retry after rollback allocates the same next available counter value, never a
  committed duplicate.

## 10. Numbering tests

- Draft creation/edit/delete does not create/increment `JRN` counter;
- first posting creates/locks correct Tenant/year row;
- format is `JRN-YYYY-NNN` and expands beyond 999;
- different Tenants have independent sequence 1;
- different entry-date years have independent sequence 1;
- posting-time current year does not affect a prior/future entry year;
- committed number is unique by Tenant and by year/sequence;
- manual/business/opening/reversal share one sequence;
- `MAX()+1` is absent from Accounting path;
- explicit counter nonnegative CHECK exists; a negative direct write is rejected;
- allocator obtains the increment through PostgreSQL `UPDATE ... RETURNING`
  without PHP integer/float arithmetic, and bigint exhaustion fails closed;
- concurrent postings for same Tenant/year receive distinct consecutive values;
- rollback does not leave an increment;
- current Project numbering tests remain unchanged/passing after allocator
  transaction refactor.

## 11. Period tests

- start before/equal end accepted; inverted rejected;
- Period boundaries outside `2000-01-01..9999-12-31` rejected;
- adjacent ranges accepted;
- one-day boundary overlap rejected;
- contained/containing/crossing ranges rejected;
- gaps accepted and posting into gap rejected;
- same range across different Tenants accepted;
- create state must be open;
- boundary edit succeeds only when open/history-free;
- boundary edit after any Posted Journal reference fails directly;
- Draft dates do not block boundary edit or close;
- close sets current close fields and clears reopen fields;
- reopen requires administrator and nonblank reason;
- reopen sets current reopen fields and clears close fields;
- repeated close/reopen events all remain in audit;
- Period delete always fails;
- posting versus close race produces no Journal committed after close wins;
- two concurrent overlapping range inserts/edits cannot both commit;
- non-overlapping concurrent range writes can commit in serialized order;
- reversal of closed-Period target into later open Period succeeds;
- reversal into closed Period fails.

## 12. Reversal tests

- exact swapped Lines with same line numbers/Accounts posts;
- target remains unchanged and Posted;
- reversal has its own number/date/Period/actor/reason/source;
- missing/blank/overlength reason rejected;
- reversal date before target rejected;
- target Draft rejected;
- partial line set rejected;
- changed Account, amount, line number, debit/credit, or extra Line rejected;
- second direct reversal rejected by Domain and partial unique index;
- two workers racing same target yield exactly one reversal;
- reversal-of-reversal succeeds and creates a linear third Journal;
- nonterminal target reversal attempt fails;
- cycle/self-target/direct malformed source fails;
- archived target Account permitted only when exact;
- closed target Period permitted; reversal Period must be open;
- audit contains `journal.posted` for reversal and `journal.reversed` for target;
- audit failure rolls back reversal and number.

## 13. Source/idempotency tests

- unregistered business source cannot persist/post;
- registering a future test source via migration fixture enables only that key;
- manual Journal requires null source tuple;
- opening/reversal require exact internal key/ID shapes;
- source partial unique allows many manual rows but one non-manual source event;
- first Business command transitions source and posts once;
- identical committed retry returns same Journal/number with
  `idempotent_replay=true` and creates no new audit;
- retry with different date/description/ordered Lines/Account/amount/memo returns
  source conflict;
- two workers on same source post exactly one Journal;
- source state `posted` without Journal fails closed;
- Journal without matching source transition fails closed in integration
  reconciliation test;
- unknown-commit simulation retry resolves existing result;
- non-SAR source rejected and source transition rolled back;
- Business posting method rejects invocation without active outer transaction;
- Business posting method never commits independently.

## 14. Opening Balance tests

### Draft/post

- creation makes one linked Opening Draft Journal with exact source/date;
- second concurrent/serial Draft rejected;
- Draft cannot coexist with effective Posted Operation;
- Draft edit keeps Operation/Journal date synchronized;
- generic manual endpoint cannot mutate opening Journal;
- Draft delete removes owned Draft aggregate and preserves delete audit;
- deleting only the Draft Operation leaves an orphan attempt and fails deferred
  validation;
- unbalanced/zero/group/archived/cross-Tenant Lines fail posting;
- valid balanced root posts as ordinary numbered Journal and becomes effective;
- direct Operation status change without Posted root fails deferred validation;
- root Journal source/date mismatch fails at commit.

### Effect/replacement

- exact root reversal leaves status Posted and changes effect to neutralized;
- neutralized Operation releases slot for new Draft/effective replacement;
- replacement produces a new root and retains old chain;
- replacement Draft before the greatest prior neutralizing terminal date fails;
- replacement on the floor date and after it succeeds, with the later date
  treated as an explicit effect gap;
- reversing old terminal reversal while replacement Draft/effective exists fails;
- after replacement is neutralized and slot free, old chain reactivation succeeds;
- backdated old-chain reactivation before another operation's later
  neutralization date fails;
- each chain advance updates latest pointer, effect actor/time, and parity;
- corrupt latest pointer, nonterminal pointer, wrong Tenant, or wrong parity fails
  deferred validation;
- multiple neutralized historical Operations allowed;
- two concurrent slot creation/reactivation/post attempts cannot create two slot
  occupants;
- complete Posted Lines, not effect state, determine ledger query results.

## 15. Audit tests

- every frozen event accepts its matching subject and required Domain context;
- invalid event/subject pair rejected;
- non-object JSON context rejected;
- actor from another Tenant rejected;
- subject from another Tenant rejected;
- unknown/missing subject rejected at insertion; after a permitted Draft delete,
  its created/edited/deleted audit rows all retain the former subject ULID;
- every required lifecycle operation writes expected event once;
- direct audit update/delete rejected;
- subject Account/Period/Posted Journal deletion remains blocked;
- audit context never contains full Journal Line arrays in contract tests;
- audit query tenant scoping prevents cross-Tenant reads;
- recorder DB failure rolls back owning mutation;
- log failure/absence does not replace DB audit assertion.

## 16. Actor FK and lifecycle tests

- all required actor fields reject null in the states requiring them;
- conditional lifecycle pairs/triples enforced;
- User from same Tenant with historical membership can remain referenced after
  membership becomes removed;
- removed/inactive membership cannot perform a new action;
- physical membership/User deletion with Accounting evidence fails RESTRICT;
- system-owner role without active Tenant membership cannot act;
- active allowed role with membership can act;
- same User ID cannot be injected from another Tenant context.

## 17. Authorization tests

For every semantic capability, test administrator, system owner with valid
membership, accountant, project manager, sales, employee, suspended User,
removed membership, and cross-Tenant request.

Specific assertions:

- administrator and accountant can manage Chart/manual posting/reversal/close/
  Opening Balance;
- only administrator can activate Settings and reopen Period;
- no role gains Business posting through a public endpoint;
- authorization preflight and locked transactional recheck both exist;
- response affordances do not authorize a mutation by themselves.

## 18. Cross-module atomicity tests

Use a minimal test Business source aggregate and registered test source:

- source transition + Journal + source audit + Accounting audit commit together;
- failure at every injection point rolls all four back;
- source lock is acquired before Accounting locks (instrumented worker/barrier);
- same source race does not deadlock under intended order;
- an intentionally inverted test worker can become a deadlock victim but cannot
  corrupt data;
- outer retry re-executes complete transaction and ends with one source/Journal;
- no external success event fires before commit.

## 19. Concurrency harness requirements

Concurrency tests use two or more independent processes/connections, barriers,
and bounded waits. Sequential calls inside one PHP process are insufficient.

Required races:

1. same Journal post twice;
2. posting versus Line edit/delete;
3. posting versus Period close;
4. reversal versus second reversal;
5. posting versus Account archive;
6. overlapping Period insert versus insert;
7. Period boundary edit versus posting;
8. Account parent A→B versus B→A cycle attempt;
9. Account structural mutation versus first posting history;
10. same source Business posting twice;
11. same Tenant/year number allocation across distinct Journals;
12. Opening Draft create versus effective reactivation;
13. Opening replacement post/backdate versus old-chain reactivation/effect-floor;
14. activation versus Tenant currency mutation.

For each race, assert final database truth, not only HTTP status. Exactly one or
both may succeed only where the architecture permits; invalid combined states
must never commit.

## 20. Deadlock, timeout, and retry tests

- transaction-local lock timeout is set and bounded;
- a held lock beyond timeout maps to `accounting_concurrency_conflict`;
- lock timeout does not leak raw SQL;
- deadlock victim SQLSTATE is retried only by outer owner;
- serialization failure uses same max-three-attempt policy;
- nested number/posting helper performs no independent retry/commit;
- retry exhaustion returns stable retryable error and no partial state;
- semantic constraint/unique failures are not blindly retried;
- jitter/backoff path is testable with injectable strategy/clock, without real
  long sleeps.

## 21. Financial reporting readiness tests

Create a known set of Posted Journals, including reversal and archived Accounts,
then assert reference queries for:

- Trial Balance total debits equal total credits;
- Account Activity deterministic ordering by date/number/line;
- General Ledger includes original and reversal, not a hidden target;
- Balance Sheet signed balances by classification, including the derived
  unclosed cumulative earnings/loss term;
- Revenue posted without a closing entry—for example Cash debit `100.00` and
  Revenue credit `100.00`—produces Asset `100.00`, derived unclosed earnings
  `100.00`, and a mathematically balanced Balance Sheet;
- Expense posted without a closing entry—for example Expense debit `40.00` and
  Cash credit `40.00` after the Revenue case—reduces Asset to `60.00`, adjusts
  derived unclosed earnings to `60.00`, and keeps the Balance Sheet balanced;
- a complete closing Journal that debits Revenue `100.00`, credits Expense
  `40.00`, and credits an Equity posting Account `60.00` leaves Revenue/Expense
  ending balances and the derived term at zero while Equity carries `60.00`, so
  the same earnings are not counted twice;
- Income Statement date-range activity;
- group balance equals descendant posting Accounts;
- Drafts never appear;
- cross-Tenant rows never appear;
- no stored balance column/table is read.

These are data-model acceptance queries, not the full reporting product.

## 22. Regression suite

After focused Accounting tests, run the complete relevant backend suite on the
isolated PostgreSQL test database. At minimum verify no regression in:

- Tenant/User lifecycle;
- authorization;
- Collections/audit;
- Contracts currency;
- Project numbering;
- entitlements/concurrency;
- existing PostgreSQL connection safety.

Frontend tests/build are required only when a later implementation includes
Accounting UI; this architecture package does not authorize UI.

## 23. Acceptance evidence

The implementation PR must report:

- actual PostgreSQL version;
- exact commands and environment safety variables used;
- focused test counts/assertions;
- full backend regression count/assertions;
- concurrency worker cases and outcomes;
- migration up/down safety results;
- final constraint/trigger catalog verification;
- `git diff --check` and documentation/schema consistency review;
- any skipped test with explicit blocker—no silent SQLite skip.

Accounting implementation is not production-ready while any mandatory case in
this plan is absent, flaky, or skipped.
