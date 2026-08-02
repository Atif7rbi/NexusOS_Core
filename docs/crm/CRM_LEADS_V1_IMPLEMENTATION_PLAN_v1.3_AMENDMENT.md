# CRM Leads v1 — Implementation Plan v1.3 Amendment

**Status:** FINAL APPROVED  
**Nature:** This document amends Implementation Plan v1.2. Unchanged v1.2 content remains effective.

---

## 1. Tenant-Scoped Pause Guard

The pause guard must be tenant-scoped:

```php
Lead::query()
    ->where('tenant_id', $tenantUser->tenant_id)
    ->where('assigned_to', $tenantUser->user_id)
    ->whereIn('stage', LeadStage::openValues())
    ->whereNull('archived_at')
    ->exists();
```

Rules:

```text
paused:
  blocked when open, active Leads are assigned in the same tenant

suspended:
  allowed as emergency suspension
```

Leads in another tenant must never affect this decision.

---

## 2. User ID API Contract

Repository inspection confirms:

```text
users.id = BIGINT
API user IDs = integer
frontend user IDs = number
```

Apply consistently to:

```text
assigned_to.id
assigned_to request
assigned_to filter
duplicate response
Activity payload from_user_id / to_user_id
frontend Lead types
```

Do not cast CRM user IDs to strings.

---

## 3. Create assigned_to Contract

Administrator:

```text
assigned_to nullable integer
missing/null → unassigned
provided → must identify an eligible user in the current tenant
```

Sales/employee:

```text
assigned_to is prohibited
sending it returns 422 validation_error
Action assigns current user automatically
```

---

## 4. Final Authorization Source

Repository inspection confirms the current official role source is:

```php
$user->role
```

Using:

```php
User::ROLE_ADMINISTRATOR
User::ROLE_SALES
User::ROLE_EMPLOYEE
```

This matches existing module authorization patterns.

`TenantUser` is used for:

```text
active membership
tenant_id
tenant isolation
membership status
```

It is not used as the role source in the current repository.

Use the same authorization source consistently across all CRM operations.

---

## 5. Final Visibility Query

Tenant scope is always applied before visibility.

Administrator:

```text
no additional assignment restriction within current tenant
```

Sales/employee:

```php
$query->where(function (Builder $q) use ($user): void {
    $q->where('assigned_to', $user->id)
      ->orWhere(function (Builder $unassigned): void {
          $unassigned->whereNull('assigned_to')
              ->whereIn('stage', LeadStage::openValues());
      });
});
```

This is the final rule.

Consequences:

```text
own assigned won/lost → visible when non-archived
unassigned open       → visible read-only before claim
unassigned won/lost   → not visible, return 404
assigned to another   → not visible, return 404
archived              → administrator only through archived=true
```

This section overrides any broader unassigned visibility wording in older drafts.

---

## 6. Summary and Archive Mode

```text
GET /leads
  active-only view

GET /leads?archived=true
  archived-only view for administrator
```

Summary:

```text
follows current Visibility Scope
follows current Archive Scope
ignores search and other list filters
```

Sales/employee `archived=true` does not widen visibility.

---

## 7. Note Lifecycle

Notes are allowed only when:

```text
Lead is open
Lead is not archived
administrator, or currently assigned user
```

Notes are prohibited on:

```text
lost
won
archived
unassigned before claim for sales/employee
```

Rejected closed-stage notes map to:

```text
409 lead_not_in_open_stage
```

---

## 8. Execution Authorization State

With Architecture & Business Rules v1.1, Plan v1.2, and this Amendment:

```text
CRM Leads v1 Milestone A — FINAL APPROVED
```

Milestone B conversion remains excluded.
