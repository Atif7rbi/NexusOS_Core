# CRM / Leads v1 — Architecture & Business Rules v1.1

**Status:** FROZEN
**Project:** NexusOS Core
**Date:** 2026-08-02
**Decisions:** #1–#12 + A, B, C, D — CLOSED

---

## 1. Aggregate Boundaries

```text
CRM Aggregate
└── Lead (Aggregate Root)
    └── LeadActivity (Child Entity — Append-only)

Customer Aggregate
└── Customer
```

`Lead ≠ Customer`.

The only post-conversion link is:

```text
leads.customer_id → customers.id
```

The existing path remains unchanged:

```text
Customer → Reservation → Contract → Collections
```

An optional path is added:

```text
Lead → Customer → Reservation → Contract → Collections
```

A Customer is not required to originate from a Lead.

`Customer.status = lead` is a legacy Customer state and is not the CRM Lead entity. Any legacy cleanup requires a separate Customer Domain amendment.

---

## 2. Lead Fields

```text
id                    ULID, PK
tenant_id             FK → tenants, NOT NULL
name                  text, NOT NULL
phone                 text, NOT NULL, canonical 05XXXXXXXX
email                 text, nullable
source                enum, NOT NULL
source_detail         text, nullable
project_id            FK → projects, nullable
unit_id               FK → units, nullable
stage                 enum, NOT NULL, default = new
assigned_to           FK → users, nullable
next_follow_up_at     timestamptz, nullable
lost_reason           enum, nullable
lost_reason_detail    text, nullable
lost_at               timestamptz, nullable
lost_by               FK → users, nullable
customer_id           FK → customers, nullable
converted_at          timestamptz, nullable
converted_by          FK → users, nullable
conversion_mode       enum, nullable
archived_at           timestamptz, nullable
archived_by           FK → users, nullable
archive_reason        enum, nullable
archive_reason_detail text, nullable
restored_by           FK → users, nullable
restored_at           timestamptz, nullable
created_by            FK → users, NOT NULL
updated_by            FK → users, nullable
created_at            timestamptz, NOT NULL
updated_at            timestamptz, NOT NULL
```

`phone` has no UNIQUE constraint. Duplicate detection is application-level only.

---

## 3. Enums

### stage

```text
new
qualified
viewing
negotiation
won
lost
```

### source

```text
whatsapp
phone_call
website
social_media
referral
walk_in
advertising
exhibition
other
```

### lost_reason

```text
price_too_high
no_suitable_unit
financing_unavailable
competitor
not_ready
unresponsive
duplicate
not_qualified
other
```

### archive_reason

```text
duplicate
invalid_record
created_by_mistake
other
```

### conversion_mode

```text
created
linked
linked_and_promoted
```

---

## 4. LeadActivity

```text
id            ULID, PK
tenant_id     FK → tenants, NOT NULL
lead_id       FK → leads, NOT NULL
type          enum, NOT NULL
body          text, nullable
payload       jsonb, nullable by type contract
occurred_at   timestamptz, NOT NULL
created_by    FK → users, NOT NULL
created_at    timestamptz, NOT NULL
```

### Activity types

```text
note
stage_change
assignment
archive
restore
```

### Rules

- Append-only.
- No update endpoint.
- No delete endpoint.
- `archive` and `restore` are generated only by their originating commands.
- `occurred_at` is system-generated.
- Every automatic Activity is written in the same transaction as the originating Lead mutation.

---

## 5. Lifecycle

### Open stages and ranks

```text
new          rank 10
qualified    rank 20
viewing      rank 30
negotiation  rank 40
```

### Closed states

```text
lost  — closed, reopenable by administrator
won   — terminal and irreversible in v1
```

### Forward transitions

Any open stage may move to any higher-ranked open stage.

### Backward correction

Administrator only. Any open stage may move to any lower-ranked open stage.

### Special transitions

```text
open → lost  via MoveLeadToLost only
open → won   via ConvertLeadToCustomer only
lost → new   via ReopenLead only, administrator only
won          no return path in v1
```

Rank comparison is authoritative. String comparison is prohibited.

---

## 6. Core Invariants

| Condition | Invariant |
|---|---|
| `stage = won` | `customer_id`, `converted_at`, `converted_by`, `conversion_mode` are all NOT NULL |
| `stage ≠ won` | all conversion fields are NULL |
| `stage = lost` | `lost_reason`, `lost_at`, `lost_by` are NOT NULL |
| `stage ≠ lost` | all loss fields are NULL |
| `archived_at IS NOT NULL` | `archived_by`, `archive_reason` are NOT NULL |
| `archived_at IS NULL` | archive metadata is NULL |
| `source = other` | `source_detail` is non-empty |
| `source ≠ other` | `source_detail` is NULL |
| `lost_reason = other` | `lost_reason_detail` is non-empty |
| `lost_reason ≠ other` | `lost_reason_detail` is NULL |
| `archive_reason = other` | `archive_reason_detail` is non-empty |
| `archive_reason ≠ other` | `archive_reason_detail` is NULL |
| `unit_id IS NOT NULL` | `project_id IS NOT NULL` |
| project and unit selected | unit belongs to project and both belong to the same tenant |

---

## 7. Lead Creation

Required:

```text
name
phone
source
source_detail when source = other
```

Initial stage:

```text
stage = new
```

The create API does not accept `stage`.

Assignment:

```text
sales / employee:
  assigned_to = current authenticated user
  assigned_to input is prohibited

administrator:
  assigned_to = null by default
  may select any eligible user
```

Eligible creators:

```text
administrator
sales
employee
```

---

## 8. next_follow_up_at

```text
nullable
past values allowed
```

Overdue means:

```text
next_follow_up_at < now
AND stage is open
AND archived_at IS NULL
```

It is cleared atomically on:

```text
won
lost
```

It remains unchanged on:

```text
archive
restore
```

Who may modify:

```text
administrator
currently assigned user
```

Unassigned Lead:

```text
administrator only
```

---

## 9. Phone and Duplicate Policy

Storage:

```text
leads.phone = canonical 05XXXXXXXX
```

There is no `normalized_phone` column.

Duplicate identity:

```text
tenant_id + canonical phone
```

Duplicates are allowed. There is no UNIQUE constraint.

Duplicate warning visibility:

```text
administrator:
  visible matches across the tenant

sales / employee:
  visible matches only under the Visibility contract below
```

No hidden match may be disclosed or hinted.

Explicit acknowledgment is required before creating a Lead when visible duplicates exist.

No Lead merge or cross-Lead link exists in v1.

---

## 10. Assignment

Eligible assignee:

```text
active user account
active membership in the same tenant
role ∈ administrator | sales | employee
```

Permissions:

| Action | administrator | sales / employee |
|---|---:|---:|
| Assign any eligible user | yes | no |
| Claim unassigned open Lead | yes | yes |
| Reassign | yes | no |
| Unassign | yes | no |

Assignment changes are prohibited for:

```text
won
lost
archived
```

Claim must be concurrency-safe.

Pausing a membership is prohibited while that membership has active, unarchived, open Leads assigned in the same tenant.

`suspended` remains available as the emergency suspension path; assigned Leads remain assigned and are visible to administrators with warning.

---

## 11. Visibility — Final Frozen Rule

### Administrator

Within the active tenant:

```text
all active Leads
archived Leads only through explicit archived=true mode
```

### Sales / Employee

May read:

```text
assigned_to = current user
```

for any non-archived stage, including `won` and `lost`.

May also read an unassigned Lead only when it is open:

```text
assigned_to IS NULL
AND stage IN (new, qualified, viewing, negotiation)
AND archived_at IS NULL
```

Therefore, unassigned closed Leads are not visible to sales/employee:

```text
assigned_to IS NULL
AND stage IN (won, lost)
→ 404
```

A Lead assigned to another user is not visible to sales/employee and returns 404.

A Lead assigned to an inactive user is visible to administrators with warning and returns 404 to sales/employee.

Archived Leads are never visible to sales/employee.

Before claiming an unassigned open Lead:

```text
read-only
no edit
no stage transition
no loss command
no conversion
no note
```

Summary cards obey the same visibility scope.

Filters never widen visibility.

Canonical query order:

```text
Tenant scope
→ Visibility scope
→ Archive scope
→ Search and filters
→ Pagination
```

---

## 12. Project and Unit Interest

```text
project_id nullable
unit_id nullable
unit_id requires project_id
```

Rules:

- Project and unit must belong to the current tenant.
- Project and unit must be non-archived at selection time.
- Unit must belong to selected project.
- Unit availability is not required.
- Selecting unit auto-selects project.
- Changing or removing project clears unit with visible UI feedback.
- Editable only while Lead is open and non-archived.

---

## 13. Commands

### MoveLeadToLost

- Open, non-archived Lead only.
- Administrator: any visible tenant Lead.
- Sales/employee: assigned own Lead only.
- Requires `lost_reason`.
- Requires detail only for `other`.
- Clears `next_follow_up_at`.
- Writes `stage_change` Activity atomically.

### ReopenLead

- Administrator only.
- `lost`, non-archived only.
- Result stage is always `new`.
- Clears loss metadata.
- Re-evaluates assignee eligibility.
- Clears assignment if no longer eligible.
- Writes Activities atomically.

### ArchiveLead

- Administrator only.
- Allowed from open stages and `lost`.
- Prohibited from `won`.
- Does not change stage.
- Does not clear assignment or follow-up.
- Writes archive Activity atomically.

### RestoreLead

- Administrator only.
- Restores the same stage.
- Clears current archive metadata.
- Updates `restored_by` and `restored_at`.
- Re-evaluates assignee eligibility.
- Writes restore and conditional assignment Activities atomically.

### ConvertLeadToCustomer

Milestone B only.

- Open, non-archived Lead only.
- Creates, links, or links-and-promotes Customer according to the Customer match state.
- Sets Lead to `won`.
- Clears follow-up.
- Writes conversion Activity atomically.
- `won` is terminal.

---

## 14. Notes

Notes are allowed only on:

```text
open
non-archived
assigned-to-current-user or administrator
```

Notes are prohibited on:

```text
lost
won
archived
unassigned before claim for sales/employee
```

---

## 15. Audit

Every Lead-modifying command sets:

```text
updated_by = actor
updated_at = now
```

---

## 16. v1 Scope Exclusions

```text
WhatsApp / telephony integration
Email campaigns
Kanban board
AI lead scoring
Calendar integration
Bulk operations
Export
Lead merge
Notifications
Advanced activity types
Multiple project/unit interests
Advanced analytics
Correction command for won
```

---

**Freeze statement:** This document is complete and frozen. No open architecture decision remains for CRM Leads v1 Core.
