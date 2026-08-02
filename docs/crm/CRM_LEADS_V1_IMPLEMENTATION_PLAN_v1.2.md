# CRM Leads v1 — Implementation Plan v1.2

**Status:** APPROVED BASE PLAN  
**Precedence:** Architecture & Business Rules v1.1 is authoritative. Implementation Plan v1.3 Amendment overrides this document where explicitly stated.

---

## 1. Milestone A Scope

Implement:

```text
leads table
lead_activities table
Create / Update / Show / List
pagination / search / filters / summary
visibility and archive scopes
duplicate detection and acknowledgment
automatic assignment on creation
claim and assignment
forward and backward stage transitions
MoveLeadToLost
ReopenLead
ArchiveLead
RestoreLead
Lead notes and activity timeline
paused-user open-leads guard
backend DB/API/concurrency tests
frontend /crm
frontend /crm/{leadId}
translations and frontend tests
```

Exclude:

```text
ConvertLeadToCustomer
conversion route
ConvertLeadDialog
Customer creation/link/promotion
conversion tests
```

---

## 2. Migrations

### `2026_08_02_100000_create_leads_table.php`

Use repository-native Laravel schema types:

```php
$table->ulid('id')->primary();
$table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
$table->string('name', 255);
$table->string('phone', 30);
$table->string('email', 255)->nullable();
$table->string('source', 30);
$table->text('source_detail')->nullable();
$table->foreignUlid('project_id')->nullable()->constrained('projects')->restrictOnDelete();
$table->foreignUlid('unit_id')->nullable()->constrained('units')->restrictOnDelete();
$table->string('stage', 20)->default('new');
$table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
$table->timestampTz('next_follow_up_at')->nullable();
$table->string('lost_reason', 30)->nullable();
$table->text('lost_reason_detail')->nullable();
$table->timestampTz('lost_at')->nullable();
$table->foreignId('lost_by')->nullable()->constrained('users')->restrictOnDelete();
$table->foreignUlid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
$table->timestampTz('converted_at')->nullable();
$table->foreignId('converted_by')->nullable()->constrained('users')->restrictOnDelete();
$table->string('conversion_mode', 30)->nullable();
$table->timestampTz('archived_at')->nullable();
$table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
$table->string('archive_reason', 30)->nullable();
$table->text('archive_reason_detail')->nullable();
$table->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
$table->timestampTz('restored_at')->nullable();
$table->foreignId('created_by')->constrained('users')->restrictOnDelete();
$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestampsTz();
```

Required CHECK constraints:

```text
phone canonical ^05[0-9]{8}$
stage enum
source enum
source/source_detail invariant
lost_reason enum
lost state invariant
lost_reason_detail invariant
conversion_mode enum
won state invariant
archive_reason enum
archive state invariant
archive_reason_detail invariant
unit requires project
```

Required indexes:

```text
(tenant_id, phone)
(tenant_id, assigned_to)
(tenant_id, stage)
(tenant_id, created_at)
(tenant_id, project_id)
archived_at
partial (tenant_id, next_follow_up_at)
  where next_follow_up_at is not null
    and archived_at is null
    and stage is open
```

### `2026_08_02_100001_create_lead_activities_table.php`

Create after `leads`.

```php
$table->ulid('id')->primary();
$table->foreignUlid('tenant_id')->constrained('tenants')->restrictOnDelete();
$table->foreignUlid('lead_id')->constrained('leads')->restrictOnDelete();
$table->string('type', 20);
$table->text('body')->nullable();
$table->jsonb('payload')->nullable();
$table->timestampTz('occurred_at');
$table->foreignId('created_by')->constrained('users')->restrictOnDelete();
$table->timestampTz('created_at');
```

Required DB invariant:

```text
note:
  body non-empty
  payload null

non-note:
  body null
  payload non-null
```

Activities use `ON DELETE RESTRICT`.

Rollback order:

```text
lead_activities
leads
```

---

## 3. Phone Storage

```text
leads.phone stores canonical 05XXXXXXXX
no normalized_phone column
SaudiMobileNormalizer is reused
DB CHECK is the final defense
```

Normalization occurs before write and before duplicate comparison.

---

## 4. API

### `GET /leads`

Query parameters:

```text
search
stage
source
assigned_to
project_id
overdue
archived
page
per_page
```

`archived=true` means archived-only and is administrator-only. Sales/employee requests for it are ignored without widening visibility.

Response envelope:

```json
{
  "data": {
    "leads": [],
    "summary": {},
    "pagination": {}
  }
}
```

Summary follows current visibility and archive scope and is calculated before search/filter parameters.

### Commands

```text
POST   /leads
GET    /leads/{lead}
PATCH  /leads/{lead}
PATCH  /leads/{lead}/stage
PATCH  /leads/{lead}/claim
PATCH  /leads/{lead}/assign
PATCH  /leads/{lead}/lose
PATCH  /leads/{lead}/reopen
PATCH  /leads/{lead}/archive
PATCH  /leads/{lead}/restore
GET    /leads/{lead}/activities
POST   /leads/{lead}/notes
```

---

## 5. Duplicate Protocol

First create request without acknowledgment:

```text
visible duplicates found → HTTP 409 lead_phone_duplicate_detected
no visible duplicates    → HTTP 201
```

Second request:

```json
{
  "acknowledge_duplicate": true
}
```

Within transaction:

- Re-run duplicate detection.
- Create regardless of current visible duplicate count.
- Never expose or hint at hidden matches.

No duplicate token exists in v1.

---

## 6. Error Mapping

```text
404 lead_not_found
403 lead_action_not_authorized
409 lead_not_in_open_stage
409 lead_stage_transition_not_allowed
409 lead_already_won
409 lead_already_lost
409 lead_not_lost
409 lead_already_archived
409 lead_not_archived
409 lead_is_archived
409 lead_won_cannot_be_archived
409 lead_claim_conflict
409 lead_phone_duplicate_detected
409 user_has_open_assigned_leads
422 lead_unit_project_mismatch
422 lead_project_is_archived
422 lead_unit_is_archived
422 lead_assignee_role_not_eligible
422 lead_assignee_not_active
422 validation_error
```

Use the repository-standard error envelope.

---

## 7. Concurrency

Claim:

```text
conditional UPDATE
WHERE assigned_to IS NULL
  AND stage is open
  AND archived_at IS NULL
```

Zero affected rows:

```text
409 lead_claim_conflict
```

Lifecycle commands:

```text
transaction
SELECT FOR UPDATE Lead
validate
mutate Lead
insert Activity
commit
```

Conversion remains Milestone B.

---

## 8. Module Structure

```text
backend/app/Modules/Leads/
  Actions/
  Controllers/
  Enums/
  Exceptions/
  Models/
  Requests/
  Support/LeadDuplicateDetector.php
```

Required Actions:

```text
CreateLeadAction
UpdateLeadAction
MoveLeadStageAction
ClaimLeadAction
AssignLeadAction
MoveLeadToLostAction
ReopenLeadAction
AddLeadNoteAction
ArchiveLeadAction
RestoreLeadAction
```

---

## 9. Frontend

```text
/frontend/src/app/crm/page.tsx
/frontend/src/app/crm/[leadId]/page.tsx
/frontend/src/types/lead.ts
/frontend/src/services/leads.ts
/frontend/src/components/leads/*
```

No conversion button or placeholder.

---

## 10. Validation

Backend:

```text
targeted CRM tests
full Laravel suite
PostgreSQL constraint tests
claim concurrency test
migration fresh
rollback and re-run
```

Frontend:

```text
unit tests
lint
TypeScript validation
production build
```

---

## 11. Commit Boundaries

```text
feat(crm): add leads database and domain foundation
feat(crm): implement lead commands and API
test(crm): cover lead constraints and workflows
feat(crm): add leads frontend workflow
test(crm): add frontend lead coverage
```
