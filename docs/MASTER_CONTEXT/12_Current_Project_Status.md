# NexusOS Core
# Current Project Status

Version: 2.0

Status: Living Document

---

# Purpose

This document provides the current operational snapshot of NexusOS Core.

It records the active engineering focus, completed product foundations, major known transitions, and the next approved architectural direction.

It must be updated when a major phase, repository baseline, or strategic priority changes.

---

# Project Identity

Project:

NexusOS Core

Repository:

```text
Atif7rbi/NexusOS_Core
```

Default branch:

```text
main
```

Product type:

Multi-tenant Business Operating System / ERP platform.

---

# Current Engineering Phase

Current phase:

```text
Gate 0 — Final Core Review & Release Readiness
```

Status:

Review Ready

Gate 0A–0D source cleanup is complete: active product runtime defaults,
identifiers, fixtures, and bootstrap behavior have been generalized. Gate 0E
has passed the isolated backend and frontend validation suites. Gate 0F
tracked-file review found no unintended customer-specific runtime coupling.

The branch is awaiting final review approval. No merge, Demo deployment, or
runtime smoke test has been performed as part of this status.

---

# Current Core Foundation

The repository already contains mature operational functionality across the following areas:

- Authentication.
- Multi-tenant membership.
- Users and authorization.
- Roles and permissions.
- Entitlements and commercial seat policy.
- Projects.
- Customers.
- Units.
- CRM Leads.
- Lead follow-ups and conversion workflows.
- Reservations.
- Contracts.
- Collection schedules.
- Dashboard operational views.
- Shared form-validation UX.
- Static-export-compatible frontend routing where required.

Several of these domains have gone through multiple production-oriented architecture, implementation, test, review, and remediation cycles.

---

# Core Generalization Status

The active cleanup is organized as:

```text
Gate 0A — Read-only repository audit
Gate 0B — Repository-wide client/Pilot leakage audit
Gate 0C — Documentation cleanup/generalization
Gate 0D — Code/config/seed/demo-data generalization
Gate 0E — Tests + build + Demo validation
Gate 0F — Final clean-Core review
```

| Gate | Status | Result |
|---|---|---|
| 0A — Repository audit | Completed | Repository and migration history inventoried. |
| 0B — Leakage audit | Completed | Tracked-file audit classified every remaining historical reference. |
| 0C — Documentation | Completed | Core documentation and product identity generalized. |
| 0D — Code/config cleanup | Completed | Runtime names, fixtures, bootstrap defaults, and plan identity generalized safely. |
| 0E — Automated validation | Completed | Backend: 450 tests / 2768 assertions. Frontend: 64 files / 284 tests; lint and static build passed. |
| 0F — Final source review | Completed | No unintended customer-specific runtime identity remains. |

## Historical Migration Compatibility

Applied migrations are never rewritten through a deployment. Fresh Core
installations now begin without a seeded customer company identity and use a
neutral bootstrap Tenant. The historical migration named
`assign_historical_projects_to_ufq_tenant` remains intentionally unchanged:
it is a migration-sensitive compatibility record for legacy Pilot databases,
does not participate in normal fresh Core data, and must be retired only by a
separate, reviewed migration-history policy.

---

# Current Development Environment

Current server validation checkout:

```text
/home/sewaellf/nexusos-core-demo
```

Current Demo deployment:

```text
https://demo.sewarsky.online
```

These are environment-specific operational details and are not NexusOS product identity.

---

# Accounting Architecture Status

Accounting architecture work has progressed substantially, including frozen direction for:

- Chart of Accounts.
- Journal Entry lifecycle.
- Journal Lines.
- Posting invariants.
- Reversals.
- Accounting Periods.
- Entry numbering.
- Concurrency and locking.
- Business-source integration.
- Opening-balance principles.
- Audit boundaries.

However, Accounting remains:

```text
FROZEN — NOT YET DDL-READY
```

No Accounting migrations should be implemented until Core generalization and the remaining architecture verification are complete.

---

# Remaining Pre-DDL Accounting Verification

After Gate 0, read-only Core verification must cover:

- Database engine and test configuration.
- Tenant identifiers and lifecycle.
- User identifiers and deletion semantics.
- Actor foreign-key conventions.
- Audit infrastructure and write path.
- Numbering and sequence infrastructure.
- Permission/action conventions.
- Tenant-safe FK and constraint patterns.
- Existing Accounting-related scaffolding, if any.

Remaining decisions then include:

- AccountingSettings exact model.
- OpeningBalanceOperation exact model.
- Accounting Period reopen metadata behavior.
- Account classification vocabulary and storage.
- Actor/Tenant FK deletion behavior.
- Accounting audit event matrix.
- SAR eligibility enforcement.
- Shared versus dedicated numbering infrastructure.

---

# Immediate Priorities

1. Perform final branch review and approve or request remediation.
2. After explicit approval, merge and deploy to the validation environment,
   then perform the Demo runtime smoke test.
3. Declare Gate 0 complete only after that approved runtime verification.
4. Resume Accounting Core DDL-readiness verification.

---

# Core / Customer Release Policy

The governing product direction is:

```text
NexusOS_Core
→ develop
→ test
→ review
→ approve

approved Core release
→ downstream customer upgrade
```

Customer-specific repositories are not sources for generic product development.

---

# Project Health

Current project health is structurally strong:

- Mature operational foundation exists.
- Multi-tenant architecture is established.
- Core workflows are implemented and exercised.
- Automated backend and frontend test coverage exists across critical areas.
- The remaining Gate 0 risk is release review and approved runtime smoke
  verification; source cleanup and automated validation are complete.

---

# Update Policy

Update this document when:

- Gate 0 completes.
- Accounting becomes DDL-ready.
- A major ERP domain is completed.
- The default Core baseline materially changes.
- Deployment or release policy changes.

---

# Guiding Principle

Project status must reflect verified repository reality, not historical plans.

---

End of Document
