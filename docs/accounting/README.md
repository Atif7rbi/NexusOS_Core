# NexusOS Core — Accounting Foundations v1

Package version: 1.0
Package conclusion: **FROZEN + DDL-READY**
Approval state: **Proposed for external architecture review**
Repository baseline inspected: `1fc7c0a348602364a7bafa1ae0f8b5c2a802157c`

This directory is the implementation contract for the first NexusOS Accounting
Core. It freezes the domain boundary, data model, PostgreSQL integrity model,
locking protocol, application boundary, audit controls, DDL plan, and test plan.
It does not contain migrations or production code.

No Accounting implementation is authorized merely because this package is
DDL-ready. Implementation begins only after this package passes the independent
architecture review and receives explicit approval.

## Document map

| Document | Purpose |
|---|---|
| [00_ACCOUNTING_FOUNDATIONS_v1.md](00_ACCOUNTING_FOUNDATIONS_v1.md) | Scope, ownership, governing principles, reporting readiness, and package status |
| [05_EXISTING_CORE_VERIFICATION_v1.md](05_EXISTING_CORE_VERIFICATION_v1.md) | Evidence from the actual Core baseline and verified limitations |
| [10_ACCOUNTING_DOMAIN_MODEL_v1.md](10_ACCOUNTING_DOMAIN_MODEL_v1.md) | Aggregates, relationships, lifecycle tables, ERD, and immutability matrix |
| [20_CHART_OF_ACCOUNTS_v1.md](20_CHART_OF_ACCOUNTS_v1.md) | Account vocabulary, hierarchy, lifecycle, and historical-change rules |
| [30_JOURNAL_AND_POSTING_v1.md](30_JOURNAL_AND_POSTING_v1.md) | Journal, lines, posting, numbering, money, reversals, and posting sequences |
| [40_ACCOUNTING_PERIODS_v1.md](40_ACCOUNTING_PERIODS_v1.md) | Period ranges, close/reopen semantics, date resolution, and concurrency |
| [50_OPENING_BALANCES_v1.md](50_OPENING_BALANCES_v1.md) | Initial GL opening-balance operation and correction/replacement semantics |
| [60_ACCOUNTING_SETTINGS_v1.md](60_ACCOUNTING_SETTINGS_v1.md) | Accounting activation, SAR policy, and the intentionally minimal settings row |
| [70_ACCOUNTING_INTEGRITY_AND_CONCURRENCY_v1.md](70_ACCOUNTING_INTEGRITY_AND_CONCURRENCY_v1.md) | Invariant ownership, PostgreSQL triggers, locks, ordering, retries, and failure translation |
| [80_ACCOUNTING_AUTHORIZATION_AND_AUDIT_v1.md](80_ACCOUNTING_AUTHORIZATION_AND_AUDIT_v1.md) | Role capabilities, actor evidence, append-only control audit, and event matrix |
| [90_ACCOUNTING_INTEGRATION_CONTRACT_v1.md](90_ACCOUNTING_INTEGRATION_CONTRACT_v1.md) | Posting API, source registry, idempotency, transaction ownership, and cross-module atomicity |
| [95_ACCOUNTING_CONTRADICTION_REVIEW_v1.md](95_ACCOUNTING_CONTRADICTION_REVIEW_v1.md) | Independent contradiction review and residual review points |
| [96_ACCOUNTING_DECISION_REGISTER_v1.md](96_ACCOUNTING_DECISION_REGISTER_v1.md) | Decision register with evidence, alternatives, impacts, and status |
| [97_ACCOUNTING_DDL_PLAN_v1.md](97_ACCOUNTING_DDL_PLAN_v1.md) | Tables, columns, constraints, indexes, triggers, and migration order; no executable DDL |
| [98_ACCOUNTING_TEST_PLAN_v1.md](98_ACCOUNTING_TEST_PLAN_v1.md) | Required database, domain, API, rollback, and concurrency coverage |

## Assignment coverage

| Requirement | Primary document(s) |
|---|---|
| 1. Domain boundary and aggregates | 00, 10 |
| 2. Chart of Accounts | 20, 97 |
| 3. Journal Entry model | 30, 97 |
| 4. Journal Lines | 30, 97 |
| 5. Draft/Posted lifecycle | 10, 30 |
| 6. Reversal architecture | 30, 50 |
| 7. Source reference and idempotency | 30, 90 |
| 8. Accounting Periods | 40, 97 |
| 9. Accounting Date | 00, 30, 40 |
| 10. Journal numbering / existing allocator | 05, 30, 97 |
| 11. Money representation | 30, 97 |
| 12. Currency policy | 05, 60 |
| 13. Opening Balances | 50, 97 |
| 14. AccountingSettings | 60, 97 |
| 15. Tenant isolation | 10, 70, 97 |
| 16. Actor FK semantics | 05, 80, 97 |
| 17. Accounting Audit | 05, 80, 97 |
| 18. PostgreSQL integrity strategy | 70, 97 |
| 19. Concurrency and locking | 70 |
| 20. Permissions/authorization | 05, 80 |
| 21. Application boundary | 90 |
| 22. Cross-module atomicity | 90 |
| 23. Immutability matrix | 10 |
| 24. State-transition tables | 10 plus subject documents |
| 25. ERD | 10 |
| 26. Three posting sequence diagrams | 30 |
| 27. Contradiction review | 95 |
| 28. Existing Core verification | 05 |
| 29. Deferred scope | 00, 96 |
| 30. Financial reporting readiness | 00, 98 |
| 31. Organized package | this index |
| 32. Decision register | 96 |
| 33. DDL Plan | 97 |
| 34. Architecture-stage Test Plan | 98 |
| 35. Evidence-first decision style | 05, 96 |
| 36. No over-engineering | 00, 60, 90, 96 |
| 37–41. Git/validation/PR/stop workflow | engineering execution outside the architecture contract |
| 42. Production-grade implementation clarity | complete package, especially 70/90/97/98 |
| 43. Final report fields | PR handoff report, outside the architecture contract |

## Status vocabulary

- **VERIFIED FROM CORE** — observed in the baseline named above.
- **ARCHITECTURAL DECISION** — frozen by this package for Accounting v1.
- **DEFERRED** — intentionally outside v1; no implementation permission.
- **NOT VERIFIED** — evidence was unavailable; the statement is not guessed.

`FROZEN + DDL-READY` means all Accounting v1 decisions needed to write reviewed
migrations are resolved. It does not mean that the existing runtime already
implements the design.

## Normative order

If two statements appear to conflict, apply this order:

1. The explicit invariant and DDL contract in this package.
2. Approved NexusOS Core architecture under `docs/MASTER_CONTEXT/`.
3. Existing database constraints and behavior at the inspected baseline.
4. Existing application patterns.

Do not silently resolve a remaining contradiction during implementation. Return
it to architecture review.
