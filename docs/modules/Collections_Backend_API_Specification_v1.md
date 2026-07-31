# Collections Backend API Specification v1

**Status:** FROZEN
**Project:** NexusOS Pilot
**Repository:** Atif7rbi/ufq-pilot

---

## 1. Purpose

This document defines the API contract for the Collections index endpoint: what goes in, what comes out, and the behavioral rules. Implementation strategy is left to the backend engineer.

---

## 2. Endpoint

```
GET /api/collections
```

**Authentication:** Required. Same middleware as all other authenticated routes (`auth:sanctum`, `tenant.active`). Unauthenticated requests return 401. Inactive tenant membership returns 403 with existing `tenant_membership_*` codes.

**Tenant scoping:** Results are always scoped to the authenticated user's active tenant. No cross-tenant data is ever returned.

---

## 3. Request Parameters

All parameters are optional. Unknown parameters are ignored.

| Parameter | Type | Constraints | Default | Description |
|---|---|---|---|---|
| `page` | integer | min: 1 | 1 | Page number |
| `per_page` | integer | min: 1, max: 100 | 20 | Items per page |
| `search` | string | max: 255 | — | Partial match against customer name or unit number |
| `status` | string | one of: `draft`, `active`, `completed`, `cancelled` | — | Filter by contract status |
| `schedule_state` | string | one of: `absent`, `draft`, `scheduled`, `cancelled` | — | Filter by derived schedule state |

Invalid parameter values (wrong type or out-of-range for `per_page`, unrecognised enum value for `status` or `schedule_state`) return `422`. Valid parameters that match no records return an empty list, not an error.

---

## 4. Response Contract

**HTTP 200:**

```json
{
  "data": {
    "items": [
      {
        "contract_id": "01JXXXXXXXXXXXXXXXXXXXXX",
        "contract_status": "active",
        "contract_total_amount": "1000000.00",
        "currency": "SAR",
        "customer_name": "أحمد الغامدي",
        "unit_number": "A-101",
        "project_name": "مشروع حي المنار",
        "schedule_state": "scheduled",
        "schedule_active_total": "1000000.00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 5,
      "per_page": 20,
      "total": 12
    },
    "summary": {
      "total_contracts": 94,
      "scheduled_count": 41,
      "draft_count": 18,
      "absent_count": 28,
      "cancelled_count": 7
    }
  }
}
```

### 4.1 Item fields

| Field | Type | Notes |
|---|---|---|
| `contract_id` | string (ULID) | Primary identifier |
| `contract_status` | string | One of: `draft`, `active`, `completed`, `cancelled` |
| `contract_total_amount` | string decimal | Always exactly two decimal places e.g. `"1000000.00"` |
| `currency` | string | Three-letter ISO code e.g. `"SAR"` |
| `customer_name` | string or null | Null if no customer on the reservation |
| `unit_number` | string or null | Null if no unit on the reservation |
| `project_name` | string or null | Null if no project on the unit |
| `schedule_state` | string | One of: `absent`, `draft`, `scheduled`, `cancelled` — see §5 |
| `schedule_active_total` | string decimal | Sum of non-cancelled collection amounts; `"0.00"` when `absent` or `cancelled` |

### 4.2 Pagination fields

| Field | Type | Notes |
|---|---|---|
| `current_page` | integer | |
| `last_page` | integer | |
| `per_page` | integer | |
| `total` | integer | Count of contracts matching the active filters, across all pages |

`pagination.total` is filter-scoped. It reflects the same `search`, `status`, and `schedule_state` filters as `data.items`.

### 4.3 Summary fields

Summary counts are **tenant-wide**. They are not affected by `search`, `status`, `schedule_state`, `page`, or `per_page`. They always represent all valid indexed contracts in the authenticated tenant, regardless of what filters are currently applied to the list.

| Field | Meaning |
|---|---|
| `total_contracts` | All valid indexed contracts in the tenant |
| `scheduled_count` | Tenant-wide count where `schedule_state = scheduled` |
| `draft_count` | Tenant-wide count where `schedule_state = draft` |
| `absent_count` | Tenant-wide count where `schedule_state = absent` |
| `cancelled_count` | Tenant-wide count where `schedule_state = cancelled` |

Invariant: `total_contracts = scheduled_count + draft_count + absent_count + cancelled_count`.

Contracts excluded from the index due to integrity violations (see §5.1) are not counted in any summary field.

---

## 5. Schedule State Rules

`schedule_state` is derived per contract from its collection rows. It is never stored.

| Condition | `schedule_state` |
|---|---|
| No collections exist for the contract | `absent` |
| Collections exist; none are active (all cancelled) | `cancelled` |
| All active collections have `status = draft` | `draft` |
| All active collections have `status = scheduled` | `scheduled` |
| Active collections have mixed statuses | Integrity violation — see §5.1 |

`schedule_active_total` is the arithmetic sum of `amount` for all non-cancelled collections. Decimal precision must be exact; floating-point arithmetic must not be used.

### 5.1 Integrity violations

Contracts whose collections resolve to a mixed-status state are **excluded** from `data.items` and from all `summary` counts. They are not surfaced in the index. The index is an operational monitoring view; integrity violations are handled outside this endpoint.

---

## 6. Filtering Rules

Filters apply to `data.items` and `pagination.total`. They do not affect `summary`.

### `search`

Leading and trailing whitespace is trimmed before matching. Matching is case-insensitive and partial (substring). A contract matches if:

- `customer_name` is non-null and contains the search term, **or**
- `unit_number` is non-null and contains the search term.

A null `customer_name` cannot satisfy the customer condition; a null `unit_number` cannot satisfy the unit condition. A contract with a null customer is not excluded if its unit number matches, and vice versa.

### `status`

Exact match against contract status. Applied independently of schedule state.

### `schedule_state`

Filters by derived schedule state. The derived state must be computed before this filter can be applied. The computation strategy is left to the backend engineer.

### Filter composition

All active filters are applied together (AND logic). A contract must satisfy every provided filter to appear in `data.items`.

---

## 7. Performance Requirement

Avoiding the N+1 query pattern is a **normative requirement**.

The implementation must demonstrate that the number of database queries required to build the Collections index remains bounded and does not grow linearly with the number of returned contracts.

The specification intentionally does not mandate an exact query count or a specific implementation strategy. The choice of SQL, joins, subqueries, batching, or application-layer approach is left to the backend engineer.

---

## 8. Ordering

Default and only order: `contracts.created_at DESC` (newest first). No client-controllable sort in v1.

---

## 9. Error Responses

| Condition | HTTP | Notes |
|---|---|---|
| Unauthenticated | 401 | Handled by auth middleware |
| Inactive tenant membership | 403 | `tenant_membership_*` codes, handled by tenant middleware |
| Invalid parameter value | 422 | Standard Laravel validation response |

No domain-specific error codes. The endpoint is read-only.

---

## 10. Required Test Coverage

**Schedule state derivation:**
- Each of the four valid `schedule_state` values (`absent`, `draft`, `scheduled`, `cancelled`) is returned correctly.
- Contracts with integrity-violating collection state are excluded from `items` and all `summary` counts.

**Filtering:**
- `search` by customer name returns matching contracts and excludes non-matching ones.
- `search` by unit number returns matching contracts and excludes non-matching ones.
- A contract with a null customer but a matching unit number is returned when searching.
- A contract with a null unit but a matching customer name is returned when searching.
- `status` filter returns only contracts with the specified contract status.
- `schedule_state` filter returns only contracts with the specified derived state.
- Combined `search`, `status`, and `schedule_state` filters are applied together correctly.

**Summary invariants:**
- `summary` is unchanged when `search` is applied; only `items` and `pagination.total` change.
- `summary` is unchanged when `status` is applied; only `items` and `pagination.total` change.
- `summary` is unchanged when `schedule_state` is applied; only `items` and `pagination.total` change.
- `pagination.total` reflects the filtered result set.

**Pagination and ordering:**
- `page` and `per_page` produce correct slicing.
- Default ordering is `contracts.created_at DESC`.
- Invalid `page` value returns 422.
- Invalid `per_page` value returns 422.
- Invalid `status` value returns 422.
- Invalid `schedule_state` value returns 422.

**Field correctness:**
- `contract_total_amount` is returned with exactly two decimal places.
- `schedule_active_total` is calculated exactly and excludes cancelled collection rows.
- Empty tenant returns empty `items`, zero `pagination.total`, and zero summary counts.

**Tenant isolation:**
- No contract from another tenant appears in results.

**Performance:**

The implementation must demonstrate that the number of database queries required to build the Collections index remains bounded and does not grow linearly with the number of returned contracts.

The specification intentionally does not mandate an exact query count or a specific implementation strategy.

---

## Implementation Notes (Non-Normative)

The following notes are informational only and do not form part of the contract. The backend engineer may implement the contract by any means that produces the specified behavior and passes the required tests.

- The schedule state derivation logic already exists in the codebase and may be reused without modification.
- Loading collections for all contracts on the current page in a single batched query satisfies the performance requirement in §7.
- The tenant-wide summary and the filtered item list may be computed in separate queries; the summary query must not apply item filters.
- The endpoint belongs to the Collections module.
