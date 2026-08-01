# CRM / Leads v1 — Architecture & Business Rules

**Project:** NexusOS Pilot  
**Module:** CRM / Leads v1  
**Version:** 1.0  
**Status:** FROZEN — Approved for Specification and DDL Design  
**Repository:** `Atif7rbi/ufq-pilot`

---

## 1. Purpose

CRM / Leads v1 provides a controlled pre-customer sales workflow for recording, assigning, progressing, closing, reopening, archiving, and converting real-estate sales opportunities.

Existing flow:

```text
Customer → Reservation → Contract → Collections
```

Optional CRM-enabled flow:

```text
Lead → Customer → Reservation → Contract → Collections
```

A Customer may still be created directly without a preceding Lead.

CRM / Leads v1 is not a marketing automation platform, campaign manager, communications platform, or advanced sales analytics product.

---

## 2. Aggregate Boundaries

```text
CRM Aggregate
└── Lead (Aggregate Root)
    └── LeadActivity (Child Entity, append-only)

Customer Aggregate
└── Customer (Independent Aggregate Root)
```

### 2.1 Lead Identity

`Lead` is an independent CRM aggregate root. It is not an early Customer, a partial Reservation, an extension of `customers`, or represented by `Customer.status = lead`.

### 2.2 Customer Identity

`Customer` remains an independent business identity aggregate. The existing Customer domain is not redesigned by CRM / Leads v1.

### 2.3 Conversion Boundary

Conversion is an explicit cross-aggregate command:

```text
ConvertLeadToCustomer
```

It creates or links a Customer, records the conversion result on the Lead, and moves the Lead to `won`. The Lead remains preserved after conversion and does not become a child of Customer.

### 2.4 Legacy Semantic Overlap

The existing value:

```text
Customer.status = lead
```

is not the CRM Lead entity.

When conversion finds a matching Customer in this legacy state, the approved behavior is explicit atomic link-and-promote:

```text
Customer.status: lead → customer
```

No other Customer identity or contact fields are overwritten automatically.

---

## 3. Lead Data Contract

The final DDL may refine physical types and indexes, but the business fields are frozen.

```text
id                    ULID
tenant_id             FK, required
name                  required
phone                 required, normalized
email                 nullable
source                required enum
source_detail         nullable
project_id            nullable FK
unit_id               nullable FK
stage                 required enum, system-controlled
assigned_to           nullable FK
next_follow_up_at     nullable datetime
lost_reason           nullable enum
lost_reason_detail    nullable
lost_at               nullable datetime
lost_by               nullable FK
customer_id           nullable FK
converted_at          nullable datetime
converted_by          nullable FK
conversion_mode       nullable enum
archived_at           nullable datetime
archived_by           nullable FK
archive_reason        nullable enum
archive_reason_detail nullable
restored_by           nullable FK
restored_at           nullable datetime
created_by            required FK
updated_by            nullable FK
created_at
updated_at
```

### 3.1 Minimum Creation Data

A Lead requires:

```text
name
phone
source
```

The system sets:

```text
stage = new
```

The Create API does not accept an arbitrary initial stage.

### 3.2 Phone Duplicate Policy

Lead phone represents an opportunity contact point, not a unique identity.

```text
No UNIQUE constraint on leads.phone
No partial UNIQUE constraint on open Leads
```

Multiple Leads may share the same normalized phone inside one Tenant.

A non-unique lookup index must support:

```text
tenant_id + normalized_phone
```

Duplicate handling is advisory and application-level.

---

## 4. Enumerations

### 4.1 Lead Stage

```text
new
qualified
viewing
negotiation
won
lost
```

Semantic classification:

```text
Open pipeline states:
- new
- qualified
- viewing
- negotiation

Closed, reopenable state:
- lost

Irreversible terminal state in v1:
- won
```

Official open-stage ordering:

```text
new         = 10
qualified   = 20
viewing     = 30
negotiation = 40
```

The numeric ranks define domain ordering only. They do not mandate database storage values.

### 4.2 Lead Source

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

### 4.3 Lost Reason

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

### 4.4 Archive Reason

```text
duplicate
invalid_record
created_by_mistake
other
```

### 4.5 Conversion Mode

```text
created
linked
linked_and_promoted
```

### 4.6 Activity Type

```text
note
stage_change
assignment
archive
restore
```

---

## 5. LeadActivity Contract

`LeadActivity` is a child entity inside the Lead aggregate.

```text
id            ULID
tenant_id     required FK
lead_id       required FK
type          required enum
body          nullable text
payload       nullable structured data
occurred_at   required datetime
created_by    required FK
created_at    datetime
```

### 5.1 General Rules

Lead activities are append-only, immutable, non-deletable, tenant-scoped, always created in the Lead context, and transactionally created with the originating command for automatic events.

A LeadActivity cannot exist without a Lead, move between Leads, be managed outside the Lead aggregate, or be edited or deleted.

### 5.2 Activity Contracts

#### `note`

```text
body: required, trimmed, non-empty
payload: absent or empty
occurred_at: system execution time
```

The user cannot backdate a note in v1.

#### `stage_change`

Required payload:

```text
from_stage
to_stage
```

Optional contextual metadata may include:

```text
lost_reason
lost_reason_detail
reopen_reason
customer_id
conversion_mode
previous_next_follow_up_at
```

#### `assignment`

Required payload:

```text
from_user_id nullable
to_user_id nullable
```

At least one value must differ.

#### `archive`

Required payload:

```text
archive_reason
archive_reason_detail conditional
archived_from_stage
```

#### `restore`

Required payload:

```text
restored_stage
restore_reason nullable
```

### 5.3 Activity Time

```text
occurred_at = business execution time set by the system
created_at  = persistence timestamp
```

Users cannot provide or modify `occurred_at`.

---

## 6. Lead Lifecycle

### 6.1 Forward Movement

A Lead may move from an open state to any higher-ranked open state.

```text
new → qualified | viewing | negotiation
qualified → viewing | negotiation
viewing → negotiation
```

Skipping open stages is allowed when it reflects operational reality. Every change creates a transactional `stage_change` activity.

### 6.2 Backward Correction

Only `administrator` may move an open Lead to any lower-ranked open state.

```text
negotiation → viewing | qualified | new
viewing → qualified | new
qualified → new
```

The correction creates one activity from the actual previous state to the selected target state. Intermediate artificial transitions are not created.

### 6.3 Move to Lost

Only the explicit command may close a Lead:

```text
MoveLeadToLost
```

Allowed source states:

```text
new
qualified
viewing
negotiation
```

Forbidden for:

```text
won
lost
archived Lead
```

Required current-state metadata:

```text
stage = lost
lost_reason
lost_at
lost_by
```

`lost_reason_detail` is required when `lost_reason = other`.

The command clears `next_follow_up_at`; the previous value may be retained in the activity payload. The Lead update and activity are atomic.

### 6.4 Reopen Lost Lead

Only the explicit command may reopen a Lead:

```text
ReopenLead
```

Authorization:

```text
administrator only
```

Eligibility:

```text
stage = lost
archived_at = null
```

Result:

```text
lost → new
```

Current loss metadata is cleared:

```text
lost_reason = null
lost_reason_detail = null
lost_at = null
lost_by = null
```

Historical loss metadata remains in the closing activity. An optional trimmed `reopen_reason` may be stored in the reopening activity payload.

### 6.5 Won

`won` is produced only by:

```text
ConvertLeadToCustomer
```

It cannot be selected through ordinary stage editing and is irreversible in CRM / Leads v1.

---

## 7. Lead Source Rules

`source` is required at creation.

```text
source = other
→ source_detail required
```

When source changes away from `other`:

```text
source_detail = null
```

CRM / Leads v1 uses a frozen enum. There is no configurable source table or free-text source model.

---

## 8. Project and Unit Interest

```text
project_id nullable
unit_id nullable
```

Supported states:

```text
No project, no unit
Project only
Project and a unit belonging to that project
```

Rules:

- `unit_id` requires `project_id`.
- Project and Unit must belong to the Lead Tenant.
- Project and Unit must exist and be non-archived when selected.
- If both are selected, `unit.project_id = lead.project_id`.
- Unit availability is not required; CRM records interest, not reservation.
- Selecting a Unit automatically selects its Project.
- Changing or clearing Project automatically clears an incompatible Unit.
- The UI warns that changing Project will remove the selected Unit.
- Interest may be edited only while the Lead is open and not archived.
- Interest cannot be edited when the Lead is `won`, `lost`, or archived.

Multiple project or unit interests are out of scope.

---

## 9. Assignment

### 9.1 Assignment Eligibility

A Lead may be unassigned:

```text
assigned_to = null
```

An assignee must belong to the same Tenant, have an active account, have an active Tenant membership, and have one of:

```text
administrator
sales
employee
```

The following roles are not eligible by default:

```text
accountant
project_manager
system_owner
```

### 9.2 Assignment Authority

Administrator may assign, reassign, unassign, assign to self, or assign to any eligible user.

Sales / Employee may only claim an unassigned open Lead for self:

```text
assigned_to: null → current_user
```

They may not assign to another user, take a Lead from another assignee, unassign, or redistribute Leads.

Claiming must be concurrency-safe. Only one concurrent claim may succeed.

### 9.3 Initial Assignment During Creation

Administrator may create an unassigned Lead or select any eligible Tenant user. Default:

```text
assigned_to = null
```

For Sales / Employee, the system sets:

```text
assigned_to = current_user
```

They cannot create a Lead unassigned or assign it to another user. The backend derives assignment from the authenticated user and rejects unauthorized `assigned_to` input.

### 9.4 Initial Assignment Activity

If a Lead is assigned during creation:

```text
assignment:
from_user_id = null
to_user_id = initial assignee
```

No assignment activity is created when Administrator creates an unassigned Lead. Creation and initial assignment activity are atomic.

### 9.5 User Deactivation

Normal deactivation or membership removal is blocked while the user has assigned open Leads. Leads must first be reassigned or unassigned.

Emergency security suspension may proceed without reassignment. Those Leads remain assigned historically and are surfaced to Administrator as assigned to an inactive user.

### 9.6 Assignment During Reopen or Restore

If the existing assignee remains eligible, assignment is preserved.

If the assignee is no longer eligible:

```text
assigned_to → null
```

The UI warns Administrator before confirmation. An assignment activity is created only when assignment changes.

---

## 10. Visibility and Authorization Scope

### 10.1 Administrator

Administrator sees all Leads inside the Tenant, including all assignees, unassigned Leads, open Leads, won and lost Leads, Leads assigned to inactive users, and archived Leads through an explicit archive filter.

### 10.2 Sales / Employee

They see:

```text
assigned_to = current_user
OR
assigned_to IS NULL
```

They do not see Leads assigned to another user.

A hidden Lead is treated as:

```text
404 Not Found
```

Backend visibility applies to list, single retrieval, activities, updates, lifecycle commands, assignment, conversion, archive actions, and every future endpoint.

### 10.3 Unassigned Lead Access

Sales / Employee may read an unassigned Lead and claim it. Before claiming, they may not edit it, change stage, add notes, convert it, or move it to lost.

### 10.4 Closed Leads

Sales / Employee may read their own assigned `won` and `lost` Leads, subject to lifecycle restrictions.

### 10.5 Archived Leads

Archived Leads are hidden by default. Only Administrator may access them through an explicit archive filter. Sales / Employee cannot access archived Leads even if previously assigned.

### 10.6 Query Ordering

```text
Tenant scope
→ Visibility scope
→ Archive scope
→ Search and filters
→ Pagination
```

Filters never widen visibility.

### 10.7 Summary Scope

Administrator summaries are Tenant-wide.

Sales / Employee summaries cover only:

```text
own Leads + unassigned Leads
```

### 10.8 No Sharing

CRM / Leads v1 has no multi-owner model, Lead sharing, watchers, delegated access, secondary assignees, or team ownership. Each Lead has zero or one assignee.

---

## 11. Duplicate Warning During Lead Creation

Duplicate detection uses:

```text
tenant_id + normalized_phone
```

It does not block creation.

Administrator may see all matching Leads in the Tenant, including archived matches with their archive state.

Sales / Employee may see only matching Leads already within their visibility scope:

```text
own
or
unassigned
```

Matches assigned to other users are not disclosed or hinted at.

For a visible duplicate, the UI provides:

```text
Open existing Lead
Continue creating a new Lead
```

Continuing requires explicit acknowledgement. No merge, automatic closure, cross-Lead relationship, or activity transfer is created.

---

## 12. Follow-Up Rules

```text
next_follow_up_at nullable
```

Rules:

- Editable by Administrator on a non-archived open Lead.
- Editable by Sales / Employee only on their own assigned non-archived open Lead.
- Not editable on an unassigned Lead before claim.
- Past values are allowed so overdue follow-ups remain representable.
- Overdue is defined as:

```text
next_follow_up_at < now
AND stage IN (new, qualified, viewing, negotiation)
AND archived_at IS NULL
```

- Automatically cleared when the Lead moves to `won`.
- Automatically cleared when the Lead moves to `lost`.
- Preserved during archive because archive does not rewrite lifecycle state or operational context.
- After `lost → new`, a new follow-up may be set.
- After restoring an archived open Lead, the preserved follow-up becomes operational again.
- No automated reminder or notification is sent in v1.

When conversion or loss clears a follow-up, the previous value may be included in the command’s activity payload for historical context.

---

## 13. Lead-to-Customer Conversion

### 13.1 Eligibility

`ConvertLeadToCustomer` is allowed from:

```text
new
qualified
viewing
negotiation
```

It is forbidden from `won`, `lost`, or an archived Lead. Conversion from `new` is allowed. Authorization does not vary by open stage.

### 13.2 Duplicate Key

Customer matching uses:

```text
tenant_id + normalized phone
```

No matching by name or email exists in v1.

The UI performs an early check, but the command revalidates inside the transaction. The Customer unique constraint remains the final protection.

### 13.3 No Matching Customer

The UI shows a prefilled Customer confirmation form.

Transferred fields:

```text
name
phone
email
```

The user completes every required Customer field under existing Customer validation. The conversion creates a Customer and records:

```text
conversion_mode = created
```

### 13.4 Matching Customer in `customer`

Link without modifying Customer fields:

```text
conversion_mode = linked
```

### 13.5 Matching Customer in `inactive`

Require explicit confirmation. Link without automatically changing Customer status:

```text
conversion_mode = linked
```

### 13.6 Matching Customer in Legacy `lead`

The UI explains that the existing legacy Customer will be promoted. The command atomically performs:

```text
Customer.status: lead → customer
conversion_mode = linked_and_promoted
```

Only Customer status and actor/update metadata change. Identity and contact data are not overwritten.

### 13.7 Matching Archived Customer

Conversion is blocked. Administrator must restore the Customer through the independent Customer workflow, then retry conversion. No Customer restore occurs implicitly inside Lead conversion.

### 13.8 Atomic Conversion

```text
Lock Lead
+ Validate Tenant, visibility, authorization, stage, archive state
+ Validate no previous customer_id
+ Recheck normalized phone match
+ Lock matching Customer when applicable
+ Create or link Customer
+ Promote legacy Customer when applicable
+ Set Lead.customer_id
+ Set Lead.stage = won
+ Set converted_at
+ Set converted_by
+ Set conversion_mode
+ Clear next_follow_up_at
+ Create stage_change Activity
+ Commit
```

The conversion activity payload includes at least:

```text
from_stage
to_stage = won
customer_id
conversion_mode
```

Only one concurrent conversion attempt may succeed.

---

## 14. Lost Lead Closure

`MoveLeadToLost` records:

```text
stage = lost
lost_reason
lost_reason_detail
lost_at
lost_by
```

`lost_reason` is mandatory. `lost_reason_detail` is mandatory when `lost_reason = other` and optional otherwise.

Authorization:

```text
Administrator:
any non-archived open Lead in the Tenant

Sales / Employee:
own assigned non-archived open Lead only
```

An unassigned Lead must be claimed before closure.

The command clears `next_follow_up_at`.

The closing activity retains:

```text
from_stage
to_stage = lost
lost_reason
lost_reason_detail
previous next_follow_up_at when present
```

---

## 15. Explicit Archiving

CRM / Leads v1 does not use SoftDeletes. Archiving is separate from lifecycle stage.

### 15.1 Eligibility

Archivable:

```text
new
qualified
viewing
negotiation
lost
```

Not archivable:

```text
won
```

Only Administrator may archive or restore.

### 15.2 Archive Command

```text
ArchiveLead
```

Archiving:

- does not change `stage`;
- does not change `assigned_to`;
- does not re-evaluate assignee eligibility;
- preserves loss and follow-up metadata;
- sets current archive metadata;
- creates an `archive` activity.

Required archive metadata:

```text
archived_at
archived_by
archive_reason
```

`archive_reason_detail` is required when `archive_reason = other`.

### 15.3 Restore Command

```text
RestoreLead
```

Restoration:

- preserves the same lifecycle stage;
- does not reopen a lost Lead;
- re-evaluates assignee eligibility;
- clears current archive metadata;
- records latest restore metadata;
- creates a `restore` activity;
- creates an assignment activity only if the assignee is cleared.

Examples:

```text
qualified + archived → restore → qualified
lost + archived → restore → lost
```

To reopen an archived lost Lead:

```text
RestoreLead
→ ReopenLead
→ new
```

### 15.4 Latest Restore Metadata

```text
restored_by
restored_at
```

represent only the latest restore operation. Complete archive and restore history is preserved in LeadActivity.

On a later `ArchiveLead`, `restored_by` and `restored_at` remain unchanged. On the next restore, they are replaced by the latest restore actor and time.

### 15.5 Archived Lead Behavior

An archived Lead is fully read-only until restored. No field update, stage change, assignment change, note creation, conversion, or lost/reopen command is permitted.

---

## 16. Actor Metadata

Every command that changes Lead state or data sets:

```text
updated_by = actor
updated_at = now
```

This includes field update, follow-up update, stage transition, assignment, loss closure, reopening, conversion, archive, and restore.

Specialized actor metadata such as `lost_by`, `converted_by`, `archived_by`, and `restored_by` does not replace `updated_by`.

---

## 17. Business Invariants

| Condition | Required invariant |
|---|---|
| `stage = won` | `customer_id`, `converted_at`, `converted_by`, and `conversion_mode` are non-null |
| `stage != won` | Current conversion metadata is null |
| `stage = lost` | `lost_reason`, `lost_at`, and `lost_by` are non-null |
| `stage != lost` | Current loss metadata is null |
| `archived_at != null` | `archived_by` and `archive_reason` are non-null |
| `archived_at = null` | Current archive reason metadata is null |
| `source = other` | `source_detail` is non-empty |
| `source != other` | `source_detail = null` |
| `lost_reason = other` | `lost_reason_detail` is non-empty |
| `archive_reason = other` | `archive_reason_detail` is non-empty |
| `unit_id != null` | `project_id != null` |
| Project and Unit selected | Both belong to Lead Tenant and Unit belongs to Project |
| Lead is archived | No operational command except Restore is permitted |
| Lead is `won` | No lifecycle reversal or archive is permitted in v1 |

Cross-table invariants that cannot be safely represented as simple CHECK constraints must be enforced transactionally in the command layer and covered by PostgreSQL integration tests.

---

## 18. List and Summary Requirements

### 18.1 Summary Cards

Administrator:

- total active Leads;
- unassigned Leads;
- overdue follow-ups;
- converted this month.

Sales / Employee receive the same metrics within their visibility scope.

Definition:

```text
active Lead = stage IN (new, qualified, viewing, negotiation)
              AND archived_at IS NULL
```

### 18.2 Search

Search by:

```text
name
phone
email
```

Partial and case-insensitive behavior follows established repository patterns where applicable.

### 18.3 Filters

```text
stage
assigned_to
source
project_id
overdue_only
archived
```

`assigned_to` filters never widen visibility.

### 18.4 Sorting and Pagination

```text
Default sort: updated_at descending
Default page size: 20
```

Archived Leads are excluded unless Administrator explicitly requests the archive view.

---

## 19. UI Scope

The existing route remains:

```text
/crm/
```

The frontend is static-export compatible and client-side.

CRM / Leads v1 should separate page orchestration from module components rather than placing all behavior in one large `page.tsx`.

Expected UI components:

- summary cards;
- search and filters;
- desktop list;
- mobile cards;
- create/edit form;
- Lead details modal or drawer;
- activity timeline;
- add-note form;
- assignment actions;
- lifecycle dialogs;
- conversion confirmation;
- archive and restore dialogs.

Dynamic detail routes are not required for v1.

---

## 20. Explicitly Out of Scope

```text
WhatsApp integration
Telephony integration and automatic call logging
Email campaigns
Marketing automation
Campaign attribution model
Automated reminders and notifications
Calendar integration
Kanban pipeline board
Advanced sales analytics
AI lead scoring
Multiple project interests
Multiple unit interests
Lead sharing or multi-owner assignment
Bulk operations
PDF or Excel export
Lead merge or deduplication workflow
Attachments
Activity types:
  call
  whatsapp
  meeting
  viewing
  email
Correction command for won Leads
Configurable Lead source administration
```

---

## 21. Technical Design Items Before DDL Freeze

The following do not reopen approved business rules. They require technical specification:

1. Canonical phone normalization format.
2. Centralized normalization implementation location.
3. Existing Customer phone normalization and backfill strategy.
4. Physical phone storage and non-unique indexing strategy for Leads.
5. LeadActivity payload representation and validation.
6. CHECK constraints and cross-table enforcement boundaries.
7. Foreign-key delete/restrict behavior.
8. Concurrency strategy for claim, conversion, and lifecycle commands.
9. API request and response contracts.
10. Domain error and HTTP conflict mapping.
11. Query indexes and pagination strategy.
12. Legacy Customer `lead → customer` promotion implementation.
13. Exact activity payload requirements for cleared follow-up values.
14. PostgreSQL integration test matrix.

---

## 22. Freeze Statement

The business architecture and business rules in this document are approved and frozen for CRM / Leads v1.

The next permitted phases are:

```text
1. Repository engineering review
2. Technical specification
3. Validation and phone normalization specification
4. DDL specification and database constraints
5. API specification
6. Implementation planning
```

No implementation, migration, public API, lifecycle, authorization, or aggregate-boundary change may contradict this document without an explicit Product Owner amendment.
