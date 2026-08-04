# CRM Leads v1 — Milestone C Architecture & Scope Freeze

**Project:** NexusOS Pilot  
**Module:** CRM / Leads v1  
**Milestone:** C — Sales Follow-up & Pipeline Completion  
**Version:** 1.0  
**Status:** FROZEN  
**Repository:** `Atif7rbi/ufq-pilot`

---

## 1. Purpose

Milestone C completes CRM Leads v1 as a daily operational sales tool by adding explicit follow-up operations, a daily work queue, a pipeline view, final operational filters, and CRM-local summaries.

This milestone extends the existing Lead aggregate and does not redesign CRM, Customer, Reservation, Contract, or Collection domains.

---

## 2. Frozen Lead Stages

The official CRM Leads v1 stages remain:

```text
new
qualified
viewing
negotiation
won
lost
```

`contacted` is not introduced in Milestone C.

Contact attempts and completed communications are represented through Lead activities and follow-up completion records, not through a new lifecycle stage.

Open stages:

```text
new
qualified
viewing
negotiation
```

Closed stages:

```text
won
lost
```

The existing stage transition contract remains authoritative.

---

## 3. Follow-up Operations

Milestone C introduces four explicit commands:

```text
ScheduleFollowUp
RescheduleFollowUp
CompleteFollowUp
CancelFollowUp
```

These commands are the only approved write paths for follow-up state.

### 3.1 Schedule Follow-up

Allowed only when the Lead has no current scheduled follow-up.

Required input:

```text
next_follow_up_at
next_action_type
```

Optional input:

```text
next_action_note
```

### 3.2 Reschedule Follow-up

Allowed only when a current follow-up exists.

It replaces the current schedule and records both previous and new values in LeadActivity.

### 3.3 Complete Follow-up

Allowed only when a current follow-up exists.

Completion clears the current follow-up fields and appends an immutable activity record.

An optional completion note may be supplied.

### 3.4 Cancel Follow-up

Allowed only when a current follow-up exists.

Cancellation clears the current follow-up fields and appends an immutable activity record.

An optional cancellation note may be supplied.

---

## 4. Next Action Types

The frozen values are:

```text
call
whatsapp
meeting
site_visit
send_offer
waiting_response
other
```

When `next_action_type = other`, `next_action_note` is required and must contain non-whitespace text.

For all other values, `next_action_note` is optional.

---

## 5. Current State and History

The Lead aggregate stores only the current scheduled follow-up state:

```text
next_follow_up_at
next_action_type
next_action_note
```

Historical follow-up events are stored in append-only `lead_activities`.

Milestone C does not introduce a separate `lead_follow_ups` table.

Milestone C does not store derived values such as:

```text
follow_up_state
last_follow_up_completed_at
```

These values are computed from current Lead state and LeadActivity history.

---

## 6. Follow-up Activity Types

The frozen activity types are:

```text
follow_up_scheduled
follow_up_rescheduled
follow_up_completed
follow_up_cancelled
```

Activity payloads include the values relevant to the operation:

```text
previous_follow_up_at
new_follow_up_at
previous_action_type
new_action_type
previous_action_note
new_action_note
note
actor_id
occurred_at
```

The activity record is immutable after creation.

---

## 7. Daily Work Queue

The CRM daily queue exposes these mutually defined operational buckets:

```text
overdue
today
tomorrow
this_week
unscheduled
```

Each Lead row may expose:

```text
name
phone
stage
assignee
project or property interest
next_follow_up_at
next_action_type
next_action_note
last_activity
```

The queue is a read model over the existing Lead aggregate and activities. It does not create a separate aggregate.

---

## 8. Timezone Contract

The operational timezone is:

```text
Asia/Riyadh
```

Database timestamps remain timezone-aware and are stored consistently with the existing PostgreSQL contract.

Bucket boundaries are calculated using Asia/Riyadh calendar boundaries, then converted safely for database comparison.

Definitions:

```text
overdue:
next_follow_up_at is before the start of the current Riyadh day

 today:
next_follow_up_at falls within the current Riyadh calendar day

 tomorrow:
next_follow_up_at falls within the next Riyadh calendar day

 this_week:
next_follow_up_at is after tomorrow and not later than the end of the current Riyadh week

 unscheduled:
next_follow_up_at is null
```

Only open, non-archived Leads participate in operational follow-up buckets unless an explicit filter requests otherwise.

---

## 9. Pipeline View

Pipeline is a read-only visual representation of the same CRM Lead data.

Frozen rules:

```text
List view remains supported
Pipeline view is added
No dynamic routes
No drag-and-drop in v1
No direct visual mutation
```

Any stage change continues to use the existing explicit backend stage-change Action and its authorization, validation, transaction, and activity rules.

Pipeline columns use the frozen stage set only.

---

## 10. Routing and Query State

The only CRM routes remain:

```text
/crm/
/crm/?lead={leadId}
```

No `crm/[leadId]` route is permitted.

The query string may carry:

```text
view
page
search
stage
assignee
project
source
follow_up_state
date_from
date_to
lifecycle
archived
lead
```

Opening Lead details preserves all current query state.

Closing Lead details removes only `lead`.

Static Export remains mandatory.

---

## 11. Final Filters

Milestone C supports:

```text
stage
assignee
project
source
follow-up state
date range
active / archived
open / won / lost
```

Filters must compose safely and remain tenant-scoped.

The backend is the source of truth for filter semantics.

---

## 12. Operational Summary

CRM-local summary fields are:

```text
open_leads
overdue_follow_ups
today_follow_ups
unassigned_leads
monthly_conversions
lost_leads_in_selected_period
```

Advanced analytics, targets, commissions, forecasting, and marketing attribution are outside v1.

---

## 13. Authorization

### Administrator

May:

```text
view all Leads in own tenant
manage all follow-ups in own tenant
view team queue
reassign Leads
```

### Sales / Employee

May:

```text
view and manage assigned Leads only
manage follow-ups for assigned Leads only
```

May not:

```text
modify another employee's Lead
access another tenant
```

Authorization must be enforced in backend Actions. Frontend restrictions are not security controls.

Writes that read and mutate the current follow-up state must use a database transaction and row lock where required to prevent stale concurrent operations.

---

## 14. Closed and Archived Lead Rules

Follow-up scheduling, rescheduling, completion, and cancellation are allowed only for open, non-archived Leads.

Blocked states:

```text
won
lost
archived
```

Reopened or restored Leads become eligible again only after the existing reopen or restore command completes successfully.

Lead conversion continues to clear `next_follow_up_at`; Milestone C also requires it to clear `next_action_type` and `next_action_note` atomically.

Losing or archiving an open Lead must clear any current follow-up state atomically under the final domain contract.

---

## 15. Out of Scope

The following remain outside CRM Leads v1:

```text
WhatsApp integration
SMS integration
Email campaigns
Marketing automation
AI lead scoring
Bulk import
Bulk actions
Duplicate merge engine
Custom pipeline stages
Custom fields
Advanced reports
Sales targets
Sales commissions
Mobile application
Push notifications
Drag-and-drop stage mutation
```

---

## 16. Final Architecture Decision

Milestone C extends the existing Lead aggregate with current next-action state, preserves LeadActivity as the append-only history, keeps Pipeline as a read view, and retains the existing six-stage lifecycle without introducing `contacted`.
