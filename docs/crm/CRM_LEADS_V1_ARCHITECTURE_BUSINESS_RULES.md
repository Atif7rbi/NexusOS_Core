# CRM / Leads v1 — Architecture & Business Rules

**Project:** NexusOS Pilot  
**Module:** CRM / Leads v1  
**Version:** 1.0  
**Status:** FROZEN CANDIDATE — Engineering Amendments Applied<br>
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

After CRM / Leads v1 is launched, the CRM Lead is the only official path for managing newly created prospective customers. `Customer.status = lead` remains a legacy compatibility state for records and behavior that predate the CRM module.

The compatibility rules are:

- A Customer created directly without a Lead starts with `status = customer`.
- `lead` is not offered or accepted as the status of a newly created Customer after CRM launch.
- A normal Customer update cannot move a non-legacy Customer into the deprecated `lead` state.
- Existing Customers already in `lead` remain unchanged until an explicit business operation changes them.
- Existing legacy Customers linked to Reservations or Contracts are not rewritten automatically during migration or deployment.
- Linking a legacy Customer during Lead conversion atomically promotes it to `customer` as defined above.
- CRM / Leads v1 does not redesign Reservation eligibility. Until an independent Customer / Reservation amendment is approved, new Reservations continue to accept any non-archived Customer under the existing Reservation contract.

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
assigned_to           nullable FK → tenant_users.id
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

`assigned_to` identifies a Tenant membership, not a global User account. General actor fields (`created_by`, `updated_by`, `lost_by`, `converted_by`, `archived_by`, and `restored_by`) continue to reference `users.id` in accordance with the current repository convention. Every command validates the actor's active Tenant membership.

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

Phone identity matching uses one centralized canonicalization contract across Lead and Customer create, update, lookup, duplicate warning, and conversion operations. The existing raw `customers.phone` uniqueness constraint is not sufficient protection for normalized identity matching.

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
from_tenant_user_id nullable
to_tenant_user_id nullable
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

### 5.4 Selective Operational History

`LeadActivity` in v1 is a selective operational timeline. It is not a complete field-level audit log.

It records:

- notes;
- stage changes;
- assignment changes;
- archive operations;
- restore operations;
- conversion context inside the conversion `stage_change` activity.

It does not preserve a historical entry for ordinary changes to:

- name;
- phone;
- email;
- source and source detail;
- Project or Unit interest;
- follow-up rescheduling;
- other general editable fields.

Those ordinary changes update only `updated_by` and `updated_at`. CRM / Leads v1 must not describe this timeline as a full audit history beyond the events explicitly recorded above.

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

Administrator may perform forward movement on any visible non-archived open Lead. Sales / Employee may perform forward movement only on their own assigned non-archived open Lead.

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

For every new assignment, claim, reassignment, initial assignment, or assignment preserved during restore or reopen, the assignee must belong to the same Tenant, have an active account, have an active Tenant membership, and have one of:

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

This is assignment-time eligibility. An existing assignment may remain temporarily attached to an ineligible membership only after the explicitly permitted emergency security suspension described in §9.5. No new Lead may be assigned to that membership while it remains ineligible.

### 9.2 Assignment Authority

Administrator may assign, reassign, unassign, assign to self, or assign to any eligible user.

Sales / Employee may only claim an unassigned open Lead for self:

```text
assigned_to: null → current TenantUser membership
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
assigned_to = current TenantUser membership
```

They cannot create a Lead unassigned or assign it to another user. The backend derives assignment from the authenticated user and rejects unauthorized `assigned_to` input.

### 9.4 Initial Assignment Activity

If a Lead is assigned during creation:

```text
assignment:
from_tenant_user_id = null
to_tenant_user_id = initial assignee membership
```

No assignment activity is created when Administrator creates an unassigned Lead. Creation and initial assignment activity are atomic.

### 9.5 User Deactivation

Lead assignment introduces the following cross-module protection for a membership that has assigned open Leads:

| User or membership change | Required behavior |
|---|---|
| Change role to an ineligible role | Blocked until open Leads are reassigned or unassigned |
| Change membership to `paused` | Blocked until open Leads are reassigned or unassigned |
| Change membership to `removed` | Blocked until open Leads are reassigned or unassigned |
| Administrative User archival | Blocked until open Leads are reassigned or unassigned |
| Emergency security `suspended` | Allowed immediately; existing assignments remain |

After emergency suspension:

- no new Lead may be assigned to the suspended membership;
- existing assignments are shown to Administrator with an inactive-assignee warning;
- Administrator redistributes or unassigns them manually;
- restoring membership eligibility does not recreate any assignment that was previously cleared or reassigned.

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
assigned_to = current TenantUser membership
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

### 10.9 Roles Without CRM Access

The following roles have no CRM module access in v1:

```text
accountant
project_manager
system_owner
```

An authenticated active Tenant member with one of those roles receives:

```text
403 Forbidden
```

This differs from an authorized CRM user requesting a Lead outside their visibility scope, which remains:

```text
404 Not Found
```

`system_owner` receives no implicit Tenant CRM access.

### 10.10 Command Authorization Matrix

| Command or operation | Administrator | Sales / Employee |
|---|---|---|
| Create Lead | Yes | Yes; system assigns current membership |
| Edit basic data on an open Lead | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Edit source | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Edit Project / Unit interest | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Edit follow-up | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Add note to an open Lead | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Move forward in open stage | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Move backward in open stage | Yes | No |
| Claim unassigned Lead | Yes | For self only |
| Move to lost | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Reopen lost Lead | Yes | No |
| Convert Lead | Any visible non-archived open Lead | Own assigned non-archived open Lead only |
| Archive / restore | Yes | No |

An unassigned Lead is read-and-claim only for Sales / Employee. No edit, note, lifecycle command, or conversion is permitted before claim.

A `lost` Lead is readable by its owning Sales / Employee but remains read-only for them. Administrator may read, reopen, or archive it. No new note is accepted on a `lost` Lead in v1.

A `won` Lead is read-only for every actor who retains visibility. It cannot be edited, receive notes, be reassigned, archived, or moved through another lifecycle command.

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

`next_follow_up_at` is stored as a UTC `timestamptz`. Overdue presentation uses the Tenant timezone. Future date filters follow the same Tenant-timezone boundary rule unless amended explicitly.

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

The UI performs an early check, but the command revalidates inside the transaction using the same centralized phone canonicalization contract used by every Lead and Customer write path.

Before production conversion behavior is enabled, existing Customers must be backfilled or otherwise evaluated under that canonicalization. A final normalized Customer identity constraint cannot be enabled until existing normalized collisions are resolved.

If more than one Customer inside the Tenant matches the same normalized phone:

```text
Conversion is blocked
→ integrity conflict
→ administrative data resolution is required
```

The command never selects one matching Customer arbitrarily. The existing unique constraint on raw `customers.phone` is not treated as final normalized-identity protection.

### 13.3 No Matching Customer

The UI shows a prefilled Customer confirmation form.

Transferred fields:

```text
name
phone
email
```

The user completes every required Customer field under existing Customer validation. Customer status is not user-selectable in the conversion form. The command creates:

```text
Customer.status = customer
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

For concurrent attempts on the same Lead, exactly one conversion may succeed. A later attempt observes the completed Lead and fails as already converted.

Multiple different Leads may link to the same Customer because they may represent distinct opportunities:

```text
leads.customer_id is not unique
```

If two different Leads concurrently attempt to create a Customer using the same normalized phone:

1. the first successful transaction may create the Customer;
2. the later transaction must re-resolve the normalized match after locking or after the normalized-identity conflict;
3. if exactly one matching Customer now exists, the command must not silently change the user's confirmed mode from `create` to `link`;
4. it returns `409 Conflict` indicating that the Customer match changed;
5. the UI reloads the match and requires explicit user review and confirmation before a new link attempt.

If the later lookup yields multiple normalized matches, the integrity-conflict behavior in §13.2 applies.

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
| `stage != won` | `customer_id`, `converted_at`, `converted_by`, and `conversion_mode` are all null |
| `stage = lost` | `lost_reason`, `lost_at`, and `lost_by` are non-null |
| `stage != lost` | `lost_reason`, `lost_reason_detail`, `lost_at`, and `lost_by` are all null |
| `stage IN (won, lost)` | `next_follow_up_at` is null |
| `archived_at != null` | `archived_by` and `archive_reason` are non-null |
| `archived_at = null` | `archived_by`, `archive_reason`, and `archive_reason_detail` are all null; latest restore metadata is not cleared |
| `source = other` | `source_detail` is non-empty |
| `source != other` | `source_detail = null` |
| `lost_reason = other` | `lost_reason_detail` is non-empty |
| `archive_reason = other` | `archive_reason_detail` is non-empty |
| `unit_id != null` | `project_id != null` |
| `project_id != null` | Project belongs to Lead Tenant |
| `unit_id != null` | Unit belongs to Lead Tenant and `unit.project_id = lead.project_id` |
| `customer_id != null` | Customer belongs to Lead Tenant; multiple Leads may reference the same Customer |
| `assigned_to != null` at assignment time | TenantUser belongs to Lead Tenant and satisfies assignment eligibility; emergency suspension may later preserve the existing reference under §9.5 |
| LeadActivity exists | `LeadActivity.tenant_id = Lead.tenant_id` and the Activity cannot move to another Lead |
| Actor executes a Lead command | Actor operates through an active membership in Lead Tenant; no system-actor exception exists in v1 |
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

`converted_at` is stored as a UTC `timestamptz`. “Converted this month” uses the start and end of the month in `Tenant.timezone`, converted to UTC boundaries for the database query. Activity timestamps are also stored as UTC and presented in the Tenant timezone.

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
Default sort: updated_at DESC, id DESC
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
2. Centralized normalization implementation location across every Lead and Customer write and lookup path.
3. Existing Customer phone normalization, backfill, collision detection, and administrative resolution strategy.
4. Physical phone display and normalized-identity storage strategy.
5. Non-unique normalized-phone indexing strategy for Leads and final normalized identity protection for Customers.
6. `assigned_to` physical FK to `tenant_users.id` and its API representation with related User identity.
7. Tenant-safe FK or transactional enforcement strategy for Customer, Project, Unit, Assignee, LeadActivity, and actor relationships.
8. LeadActivity payload representation, schema validation, and payload versioning.
9. Append-only LeadActivity enforcement boundary.
10. Exact activity payload requirements for cleared follow-up values.
11. CHECK constraints and cross-table enforcement boundaries.
12. Field lengths and trim, case, empty-string, and null-normalization rules.
13. UTC storage and Tenant-timezone query and presentation boundaries.
14. Foreign-key delete/restrict behavior for every relationship and actor field.
15. Concurrency and fixed lock-order strategy for claim, conversion, lifecycle commands, Customer create, and Customer phone update.
16. Stale-update and optimistic-concurrency strategy for editable Leads.
17. API request and response contracts.
18. Exact domain errors and `403` / `404` / `409` / `422` HTTP mapping.
19. Query indexes, stable pagination, search behavior, and tie-break ordering.
20. Summary interaction with search and filters while preserving role visibility scope.
21. Legacy Customer `lead → customer` promotion implementation.
22. Customer direct-create and update compatibility changes that prevent new legacy `lead` records.
23. Reservation compatibility verification without redesigning its current non-archived Customer eligibility rule.
24. User / TenantUser role and status transition guards introduced by assigned open Leads.
25. Normalized Customer match-changed behavior and explicit re-confirmation after a conversion race.
26. PostgreSQL integration test matrix spanning Leads, Customers, Users, TenantUser, Reservations, tenant isolation, rollback, and real row-lock concurrency.

---

## 22. Freeze Statement

The Product Owner has approved the CRM / Leads v1 business architecture, business rules, and repository-engineering amendments contained in this document.

The document is a frozen candidate pending one final repository engineering verification that the approved amendments have been applied completely and consistently. No aggregate or lifecycle decision is reopened by that verification.

The next permitted phases are:

```text
1. Final repository engineering verification
2. Mark Architecture & Business Rules v1.0 as FROZEN
3. Phone normalization and validation specification
4. Technical specification
5. DDL specification and database constraints
6. API specification
7. Implementation planning
```

After final approval, no implementation, migration, public API, lifecycle, authorization, or aggregate-boundary change may contradict this document without an explicit Product Owner amendment.
