# CRM Leads v1 — Milestone C Database & Domain Contract

**Project:** NexusOS Pilot
**Module:** CRM / Leads v1
**Milestone:** C — Sales Follow-up & Pipeline Completion
**Version:** 1.0
**Status:** FROZEN
**Repository:** `Atif7rbi/ufq-pilot`

---

## 1. Scope

This document freezes the database and domain contract for Milestone C.

It supplements the existing CRM Leads v1 schema and does not replace previously frozen Lead, LeadActivity, conversion, archive, assignment, or stage contracts except where explicitly stated.

---

## 2. Lead Schema Amendment

Add these nullable fields to `leads`:

```text
next_action_type  varchar(30) nullable
next_action_note  text nullable
```

The existing field remains:

```text
next_follow_up_at timestamp with time zone nullable
```

No separate `lead_follow_ups` table is introduced.

No stored `follow_up_state` column is introduced.

No stored `last_follow_up_completed_at` column is introduced.

---

## 3. Next Action Enumeration

`next_action_type` accepts only:

```text
call
whatsapp
meeting
site_visit
send_offer
waiting_response
other
```

Any other non-null value is rejected by PostgreSQL.

---

## 4. Lead Follow-up State Constraint

The database must enforce the following invariant:

```text
When next_follow_up_at is null:
- next_action_type must be null
- next_action_note must be null

When next_follow_up_at is not null:
- next_action_type must be non-null
```

The note contract is:

```text
When next_action_type = other:
- next_action_note is required
- btrim(next_action_note) must not be empty

When next_action_type is null:
- next_action_note must be null

When next_action_type is any approved non-other value:
- next_action_note may be null or non-empty text
```

Whitespace-only notes are not accepted when a note is present.

---

## 5. Database Constraints

The implementation must add named PostgreSQL constraints equivalent to:

```text
leads_next_action_type_check
leads_follow_up_state_check
leads_next_action_note_check
```

Required semantics:

```sql
CHECK (
    next_action_type IS NULL
    OR next_action_type IN (
        'call',
        'whatsapp',
        'meeting',
        'site_visit',
        'send_offer',
        'waiting_response',
        'other'
    )
)
```

```sql
CHECK (
    (
        next_follow_up_at IS NULL
        AND next_action_type IS NULL
        AND next_action_note IS NULL
    )
    OR
    (
        next_follow_up_at IS NOT NULL
        AND next_action_type IS NOT NULL
    )
)
```

```sql
CHECK (
    (
        next_action_type = 'other'
        AND next_action_note IS NOT NULL
        AND btrim(next_action_note) <> ''
    )
    OR
    (
        next_action_type IS NOT NULL
        AND next_action_type <> 'other'
        AND (
            next_action_note IS NULL
            OR btrim(next_action_note) <> ''
        )
    )
    OR
    (
        next_action_type IS NULL
        AND next_action_note IS NULL
    )
)
```

---

## 6. Index Contract

The existing partial index on:

```text
tenant_id + next_follow_up_at
```

for open, non-archived Leads remains valid and authoritative for queue date operations.

The implementation must review whether a replacement is required only if the existing predicate or name conflicts with the final migration strategy.

A separate index on `next_action_type` is not required for v1 unless query-plan validation proves it necessary.

Pipeline queries continue to use the existing tenant/stage index.

---

## 7. Follow-up Commands

The domain exposes four explicit write Actions:

```text
ScheduleLeadFollowUpAction
RescheduleLeadFollowUpAction
CompleteLeadFollowUpAction
CancelLeadFollowUpAction
```

Controller or Request classes must not mutate Lead follow-up fields directly.

Each Action must:

```text
resolve the actor's active tenant membership
load the Lead within the tenant boundary
lock the Lead row for update
verify authorization
verify open and non-archived state
verify operation-specific preconditions
mutate current follow-up fields
append one LeadActivity
commit atomically
return the refreshed Lead/result DTO
```

---

## 8. Command Preconditions

### Schedule

```text
current next_follow_up_at must be null
new datetime must be valid
new action type must be approved
```

### Reschedule

```text
current next_follow_up_at must be non-null
new datetime must be valid
new action type must be approved
```

Rescheduling to identical effective values is rejected as a semantic no-op unless the final existing repository convention explicitly permits no-op mutations. The preferred v1 behavior is rejection.

### Complete

```text
current next_follow_up_at must be non-null
```

Completion clears:

```text
next_follow_up_at
next_action_type
next_action_note
```

### Cancel

```text
current next_follow_up_at must be non-null
```

Cancellation clears the same three fields.

---

## 9. Datetime Contract

API input must use an unambiguous ISO-8601 datetime containing an offset or UTC designator.

The application normalizes timestamps consistently with the repository's existing timestamp convention before persistence.

Queue bucket calculations use `Asia/Riyadh` boundaries.

The database stores timezone-aware values.

The API must not infer a timezone from a datetime string that omits an offset.

---

## 10. Follow-up Activity Contract

Add these values to the approved LeadActivity type set:

```text
follow_up_scheduled
follow_up_rescheduled
follow_up_completed
follow_up_cancelled
```

Each activity must identify:

```text
lead_id
tenant_id
type
created_by
created_at
payload
```

Payload requirements:

### Scheduled

```text
previous_follow_up_at: null
new_follow_up_at
previous_action_type: null
new_action_type
previous_action_note: null
new_action_note
```

### Rescheduled

```text
previous_follow_up_at
new_follow_up_at
previous_action_type
new_action_type
previous_action_note
new_action_note
```

### Completed

```text
previous_follow_up_at
new_follow_up_at: null
previous_action_type
new_action_type: null
previous_action_note
new_action_note: null
note
```

### Cancelled

```text
previous_follow_up_at
new_follow_up_at: null
previous_action_type
new_action_type: null
previous_action_note
new_action_note: null
note
```

Actor and occurrence time remain available through the activity's canonical actor and timestamp columns; payload duplication is optional only where current repository serialization conventions require it.

---

## 11. Interaction with Existing Lead Commands

The following commands must preserve the final follow-up invariant.

### Convert Lead

On successful conversion, atomically clear:

```text
next_follow_up_at
next_action_type
next_action_note
```

### Lose Lead

On successful loss, atomically clear the same fields.

### Archive Lead

On successful archive, atomically clear the same fields.

### Reopen Lead

Reopening does not restore a previous follow-up automatically.

### Restore Lead

Restoring does not restore a previous follow-up automatically.

### Edit Lead

Generic Lead update endpoints must not accept direct writes to follow-up fields.

---

## 12. Read Model Contract

The Lead list/detail response may expose:

```text
next_follow_up_at
next_action_type
next_action_note
follow_up_state
```

`follow_up_state` is computed and accepts:

```text
overdue
today
tomorrow
this_week
scheduled_later
unscheduled
```

`scheduled_later` is permitted as a read-model classification for a scheduled date beyond the current Riyadh week, even though the primary daily queue tabs are overdue, today, tomorrow, this week, and unscheduled.

Closed or archived Leads may return `follow_up_state = null` in operational responses because domain constraints require no active follow-up state.

---

## 13. Queue Query Contract

Operational queue queries include only:

```text
tenant_id = current tenant
archived_at IS NULL
stage IN ('new', 'qualified', 'viewing', 'negotiation')
```

Bucket predicates use half-open intervals:

```text
[start, end)
```

This avoids overlap at exact midnight boundaries.

`this_week` excludes today and tomorrow when those buckets are presented separately.

The week boundary must follow the application's frozen Riyadh business-week convention. If no repository-wide convention exists, Milestone C uses the ISO week boundary for deterministic implementation and tests; this must be documented in code and API tests.

---

## 14. Summary Query Contract

Summary values must be derived from tenant-scoped database queries, not from the current paginated result set.

Required values:

```text
open_leads
overdue_follow_ups
today_follow_ups
unassigned_leads
monthly_conversions
lost_leads_in_selected_period
```

`monthly_conversions` uses `converted_at` within the current Riyadh calendar month.

`lost_leads_in_selected_period` uses `lost_at` and the selected date interval. When no interval is selected, the API must use one explicit documented default rather than silently varying by endpoint.

---

## 15. Authorization Contract

Administrator:

```text
may operate on any eligible Lead in own tenant
```

Sales / Employee:

```text
may operate only when assigned_to equals the actor's eligible identity under the existing CRM assignment contract
```

Unassigned Leads must be claimed before a Sales / Employee actor can manage follow-ups.

Cross-tenant lookup must resolve as not found or the repository's existing tenant-isolation response, without leaking existence.

---

## 16. Concurrency Contract

All follow-up write Actions must lock the Lead row.

Concurrent operations against the same current follow-up must produce:

```text
exactly one valid mutation for a state-dependent command
no duplicate semantic activities
no partial field clearing
no stale overwrite
```

At minimum, PostgreSQL runtime coverage must include competing completion/cancellation or competing reschedule operations.

---

## 17. Migration Strategy

Milestone C uses a new forward migration. It must not edit a migration that has already run in production.

The migration must:

```text
add next_action_type
add next_action_note
add named check constraints
preserve existing Lead data
validate compatibility with rows where next_follow_up_at is already non-null
```

Because production may contain existing rows with `next_follow_up_at` and no action type, the migration must include an explicit safe transition strategy before validating the final invariant.

Approved transition principle:

```text
Existing scheduled follow-ups are backfilled with a deterministic action type representing an unspecified legacy follow-up.
```

The implementation must not invent `other` without a required note. The preferred compatible value is `waiting_response` only if product semantics accept it; otherwise the migration must introduce and freeze a dedicated compatibility value before implementation. No migration may be deployed until this legacy-data decision is verified against production data.

---

## 18. Testing Contract

Backend tests must cover:

```text
all four commands
all action types
other-note requirement
database constraints
authorization
tenant isolation
open/closed/archived eligibility
conversion/loss/archive clearing
activity payloads
Riyadh bucket boundaries
UTC conversion
summary queries
filter composition
concurrency
```

Frontend tests must cover:

```text
daily queue states
follow-up forms and validation
complete/cancel confirmation
pipeline rendering
query-state preservation
list/pipeline switching
filters
summary values
static-export route contract
```

PostgreSQL runtime validation is mandatory.

---

## 19. Frozen Outcome

Milestone C stores the current follow-up on `leads`, stores history in append-only `lead_activities`, enforces state integrity in PostgreSQL, exposes all writes through explicit transactional Actions, and computes queue classifications using Asia/Riyadh calendar boundaries.
