# CRM Leads v1 — Static Export Routing Amendment v1.0

**Status:** APPROVED
**Scope:** CRM Leads v1 Milestone A frontend routing
**Precedence:** This amendment supersedes only the dynamic CRM routing clauses in Implementation Plan v1.2.

---

## Final Routing Contract

The only approved frontend routes for CRM Leads v1 Milestone A are:

```text
/crm/
/crm/?lead={leadId}
```

Lead details are rendered through query state on the static CRM page. No dynamic Lead details route is part of Milestone A.

## Explicitly Prohibited

```text
frontend/src/app/crm/[leadId]/page.tsx
/crm/{leadId}
```

## Required Behavior

- Opening Lead details preserves all active filters and the current page query parameter.
- Closing Lead details removes only the `lead` query parameter.
- The routing contract is compatible with `output: "export"`.
- The routing contract is compatible with `trailingSlash: true`.

## Unchanged Approved Contracts

All other approved rules in Architecture & Business Rules v1.1, Implementation Plan v1.2, and Implementation Plan v1.3 Amendment remain unchanged.
