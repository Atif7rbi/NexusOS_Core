# NexusOS Core
# MASTER_CONTEXT

> Official Product Knowledge Base

---

# Purpose

This directory contains the primary high-level engineering and product context for NexusOS Core.

It documents product identity, engineering philosophy, methodology, architecture, frozen decisions, implementation status, roadmap, current phase, and major project decisions.

Engineers and AI agents should review the relevant documents before making material architectural or implementation changes.

---

# Scope

MASTER_CONTEXT covers:

- Product overview.
- Engineering philosophy.
- Development methodology.
- Team and decision responsibilities.
- Development environment.
- Git workflow.
- Deployment workflow.
- Backend architecture.
- Database architecture.
- Domain status.
- Frozen decisions.
- Documentation structure.
- Current project status.
- Product roadmap.
- Current engineering phase.
- Project decision log.

Detailed domain specifications live elsewhere under `docs/` and remain authoritative within their specific scopes.

---

# Documentation Authority

Documentation is an engineering asset, not informal commentary.

Material architectural decisions should not exist only in chat history.

After approval, they should be recorded in the appropriate repository document.

When sources conflict, use this priority:

1. Explicit current Product Owner decision.
2. Newer approved/frozen domain or architecture specification.
3. Enforced database constraints and automated tests.
4. Current implementation.
5. High-level orientation documents.

Material conflicts should be surfaced and resolved explicitly.

---

# Reading Order

Recommended reading order:

1. `00_Project_Overview.md`
2. `01_Project_Philosophy.md`
3. `02_Development_Methodology.md`
4. `03_Team_Roles.md`
5. `04_Development_Environment.md`
6. `05_Git_Workflow.md`
7. `06_Deployment_Workflow.md`
8. `07_Backend_Architecture.md`
9. `08_Database_Architecture.md`
10. `09_Domain_Status.md`
11. `10_Frozen_Decisions.md`
12. `11_Project_Documents.md`
13. `12_Current_Project_Status.md`
14. `13_Roadmap.md`
15. `14_Current_Phase.md`
16. `99_Project_Decision_Log.md`

---

# Repository

Official Core repository:

```text
Atif7rbi/NexusOS_Core
```

Default approved baseline:

```text
main
```

---

# Core / Customer Separation

MASTER_CONTEXT describes NexusOS Core.

Customer-specific deployments are downstream consumers of approved Core releases.

Customer identity, branding, domains, credentials, migration data, and one-off business exceptions must not redefine Core architecture.

---

# Living Documents

The following are expected to change as the project progresses:

- `09_Domain_Status.md`
- `12_Current_Project_Status.md`
- `13_Roadmap.md`
- `14_Current_Phase.md`
- `99_Project_Decision_Log.md`

Architecture and frozen-decision documents should change only through deliberate review.

---

# Current Transition

NexusOS Core is currently undergoing systematic generalization from its historical Pilot/customer-specific origins before major Accounting and ERP expansion proceeds.

---

End of Document
