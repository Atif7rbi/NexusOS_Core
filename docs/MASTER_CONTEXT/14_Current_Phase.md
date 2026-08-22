# NexusOS Core
# Current Phase

Version: 2.0

Status: Living Document

---

# Purpose

This document defines the immediate engineering focus of NexusOS Core.

It is intentionally narrower than the Roadmap and Current Project Status documents.

---

# Current Phase

Phase:

```text
Gate 0 — Final Core Review & Release Readiness
```

Status:

```text
REVIEW READY
```

---

# Why This Phase Exists

NexusOS Core evolved from an earlier operational Pilot/customer implementation.

The repository now serves as the official generic product Core, but historical customer and Pilot identifiers still exist in documentation, runtime names, tests, defaults, migrations, and frontend namespaces.

Major ERP and Accounting expansion must not proceed on top of those unresolved product-identity remnants.

---

# Gate 0 Structure

```text
Gate 0A — Read-only repository audit
Gate 0B — Repository-wide client/Pilot leakage audit
Gate 0C — Documentation cleanup/generalization
Gate 0D — Code/config/seed/demo-data generalization
Gate 0E — Tests + build + Demo validation
Gate 0F — Final clean-Core review
```

Gate 0A–0D source work is complete. Gate 0E has passed isolated backend and
frontend validation. Gate 0F source review is complete and the branch is ready
for final review; merge, deployment, and Demo smoke verification remain
explicitly pending approval.

---

# Current Work

Completed work includes:

- Generalized entitlement Plan provisioning and the persisted Core plan key.
- Generic, environment-configured test-database safety checks while retaining
  the existing isolated test infrastructure.
- Migration-compatible historical cleanup and tenant-neutral fresh bootstrap.
- Full isolated backend validation plus frontend tests, lint, and static build.

---

# Explicit Non-Scope

During this phase do not introduce:

- Accounting migrations.
- New ERP domains.
- New business rules unrelated to generalization.
- Customer-specific features.
- Broad refactors unrelated to identified Core cleanup.
- Hidden deployment changes.

---

# Exit Criteria

Gate 0 is complete only when:

1. Core documentation reflects NexusOS Core rather than the former Pilot.
2. Runtime namespaces and generic product identity are cleaned.
3. Customer-specific defaults and branding are removed or properly externalized.
4. Tests use generic product fixtures where appropriate.
5. Historical migration handling has been deliberately resolved.
6. Backend tests pass.
7. Frontend tests, lint, and build pass as applicable.
8. Approved Demo deployment and runtime smoke verification succeeds.
9. Final repository-wide leakage scan shows no unintended customer-specific coupling.
10. The final branch review is approved.

---

# Next Phase

After Gate 0:

```text
Accounting Core Architecture Verification
→ Remaining Freeze Decisions
→ DDL Readiness Review
→ DDL
→ Accounting Implementation
```

Accounting implementation must not begin merely because Gate 0 documentation is complete.

The Accounting design must first be explicitly declared:

```text
FROZEN + DDL-READY
```

---

# Engineering Priority

The current priority is product correctness and source-of-truth cleanup, not feature velocity.

The goal is to ensure that all subsequent ERP work is built on a genuinely generic NexusOS Core foundation.

---

# Update Policy

Update this document when:

- Gate 0 changes subphase materially.
- Gate 0 completes.
- Accounting architecture verification becomes the active phase.
- A new major engineering phase begins.

---

# Guiding Principle

One clearly defined active phase should govern major engineering work at a time.

---

End of Document
