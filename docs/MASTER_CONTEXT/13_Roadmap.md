# NexusOS Core
# Roadmap

Version: 2.0

Status: Living Document

---

# Purpose

This document defines the high-level product and engineering roadmap for NexusOS Core.

It describes strategic progression rather than detailed implementation tasks.

---

# Roadmap Principles

The roadmap should remain:

- Product-oriented.
- Architecture-driven.
- Incremental.
- Multi-tenant by default.
- Commercially reusable.
- Flexible where implementation evidence requires refinement.

A roadmap phase does not authorize implementation without the relevant architecture and scope approval.

---

# Foundation Already Established

NexusOS already contains a substantial operational Core, including:

- Platform and repository foundations.
- Backend and database architecture.
- Authentication and tenant isolation.
- Authorization and entitlements.
- Core operational business domains.
- Frontend workflows.
- API integration.
- Static-export-compatible deployment patterns.
- Automated backend and frontend validation.

Earlier roadmap models that treated Application Layer, Presentation Layer, and Frontend Integration as wholly future phases are therefore historical and no longer represent repository reality.

---

# Phase 0 — Core Generalization

Status:

In Progress

Objectives:

- Remove residual Pilot/customer identity from Core.
- Generalize documentation.
- Generalize runtime namespaces and product naming.
- Remove client-specific branding defaults.
- Generalize test fixtures and configuration.
- Safely resolve historical client-specific migration baggage.
- Validate the resulting clean Core.

Exit condition:

NexusOS Core can be treated as a reusable commercial product without hidden customer-specific identity or behavior.

---

# Phase 1 — Accounting Core

Status:

Architecture substantially frozen; DDL readiness pending.

Objectives:

- Chart of Accounts.
- Accounting Periods.
- Journal Entries.
- Journal Lines.
- Posting lifecycle.
- Reversals.
- Entry numbering.
- Concurrency and transactional integrity.
- Accounting settings.
- Opening-balance onboarding.
- Accounting audit/control events.
- Business-source posting contract.

Core principle:

```text
Business Transaction
→ Accounting Rule
→ Journal Entry
→ Journal Lines
→ Ledger / Reporting
```

Accounting implementation begins only after explicit DDL-readiness approval.

---

# Phase 2 — Receivables and Payments

Status:

Planned

Objectives:

- Distinguish scheduled collections from actual payments.
- Record customer receipts.
- Integrate receivables with Accounting Core.
- Preserve idempotent and auditable financial transitions.
- Support contract/customer receivable visibility.

Important invariant:

```text
Collection Schedule ≠ Payment
Scheduled Amount ≠ Collected Amount
```

---

# Phase 3 — Vendors, Expenses and Accounts Payable

Status:

Planned

Objectives:

- Vendor management.
- Expense recording.
- Payables lifecycle.
- Supplier settlements.
- Accounting integration.
- Audit-safe financial transitions.

---

# Phase 4 — Project Costing

Status:

Planned

Objectives:

- Attribute relevant operational costs to projects.
- Support project-level cost visibility.
- Integrate costing with expenses/payables and Accounting Core.
- Avoid premature generic dimensional-accounting complexity.

---

# Phase 5 — Budgeting

Status:

Planned

Objectives:

- Budget definitions.
- Budget versus actual comparison.
- Project and organizational planning where justified.
- Controlled integration with accounting data.

---

# Phase 6 — ERP and Financial Reporting

Status:

Planned

Objectives:

- Ledger-based accounting reports.
- Operational/financial reporting.
- Receivables/payables visibility.
- Project financial views.
- Export and review workflows.

Reporting must derive from authoritative operational and accounting data rather than duplicated cached truth.

---

# Phase 7 — Approval Workflows

Status:

Planned

Objectives:

- Introduce approval controls only where proven business processes require them.
- Preserve explicit authorization and audit history.
- Avoid building a universal workflow engine prematurely.

---

# Phase 8 — Document Management

Status:

Planned

Objectives:

- Business-document attachment and organization.
- Domain-safe document ownership.
- Access control.
- Auditability and lifecycle management.

---

# Phase 9 — ERP Hardening

Status:

Planned

Objectives:

- Cross-domain integration validation.
- Security review.
- Performance validation.
- Concurrency review.
- Migration/release hardening.
- Backup and recovery validation.
- Operational monitoring readiness.

---

# Phase 10 — Commercial ERP Release

Status:

Planned

Objectives:

- Establish approved commercial release baseline.
- Validate upgrade path for downstream customer deployments.
- Finalize operating and deployment documentation.
- Verify licensing/entitlement behavior.
- Confirm production readiness.

---

# Deferred Scope

Unless a structural dependency proves otherwise, the following remain deferred:

- VAT/ZATCA implementation.
- Payroll.
- Inventory.
- Bank reconciliation.
- Fixed assets.
- Generic tax engine.
- Universal cost-center/dimensions framework.
- Enterprise-scale workflow engine.
- SAP-level accounting abstractions.

---

# Prioritization

Priorities are:

1. Correctness and integrity.
2. Clear domain ownership.
3. Tenant safety.
4. Auditability.
5. Operational usefulness.
6. Maintainability.
7. Performance where evidence requires optimization.
8. New scope.

---

# Roadmap Maintenance

Review this roadmap when:

- A strategic phase completes.
- Major product direction changes.
- Architecture evidence materially changes sequencing.
- A deferred capability becomes structurally necessary.

---

# Guiding Principle

NexusOS should grow into a capable ERP through validated layers, not by prematurely reproducing enterprise-suite complexity.

---

End of Document
