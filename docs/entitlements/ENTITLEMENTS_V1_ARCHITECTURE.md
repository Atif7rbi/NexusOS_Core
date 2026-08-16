# NexusOS Entitlements v1 Architecture

Version: 1.0

Status: Frozen Architecture — implementation awaits PostgreSQL runtime validation

## Purpose

Entitlements v1 defines whether a Tenant may use a commercial NexusOS Module.
Entitlement is independent from RBAC: an operation is available only when the
Tenant is entitled to its Module and the authenticated user is authorized by
the existing domain rules.

## Commercial Modules

The immutable commercial Module keys are:

- `projects`
- `units`
- `customers`
- `crm`
- `reservations`
- `contracts`
- `collections`

Authentication, Dashboard, Users, Settings, and Company Profile are core
platform capabilities. They are not commercial Modules and are never disabled
by Plan entitlement.

Placeholder pages such as Employees, Contractors, Expenses, and Reports are
not part of the Module catalog.

## Schema

Entitlements v1 uses exactly five tables:

- `modules`: immutable catalog key, Arabic/English names, and catalog status.
- `plans`: commercial Plan key, names, and status.
- `plan_modules`: unique Plan-to-Module membership.
- `tenant_licenses`: Tenant Plan history, lifecycle status, and explicit period.
- `tenant_modules`: optional unique Tenant-to-Module `enabled` or `disabled`
  override.

Foreign keys use restrictive deletion. CHECK constraints protect status and
period values. A PostgreSQL `BEFORE INSERT OR UPDATE` trigger takes a
transaction-scoped advisory lock for the Tenant and rejects overlapping
entitled periods while retaining non-overlapping historical Licenses. It uses
only PostgreSQL core capabilities and has no extension dependency.

## License lifecycle and time semantics

Supported statuses are:

- Entitled statuses: `trial`, `active`, `grace`.
- Terminal/non-entitled statuses: `expired`, `cancelled`.

All License timestamps are stored and compared as UTC instants.

An `active` or `trial` License is current only when:

```text
starts_at <= now <= ends_at
```

A `grace` License is current only when:

```text
starts_at <= now <= grace_ends_at
```

For `grace`, `grace_ends_at` is required and later than `ends_at`. It must be
null for every other status. A License whose effective time window has ended
is historical even if its status has not yet been changed to a terminal value.
It does not block a later non-overlapping License. Overlapping entitled periods
for one Tenant are rejected by PostgreSQL and by provisioning validation.

## Effective entitlement resolution

`ResolveEffectiveTenantModules` is the single source of truth. For each Module:

```text
inactive Module catalog entry
    -> DENY

Tenant override = disabled
    -> DENY

Tenant override = enabled
    -> ALLOW

no Tenant override
    -> use current License Plan membership

no valid current License
    -> DENY
```

The resolver is fail-closed. It does not infer entitlement from role, Tenant
identity, email, deployment environment, Demo Mode, or frontend state.

## Plan dependencies

Plans must contain explicit valid dependency compositions:

```text
units        -> projects
crm          -> customers
reservations -> projects + units + customers
contracts    -> reservations
collections  -> contracts
```

The runtime resolver does not add dependencies implicitly. Plan composition is
validated when the approved catalog and Plan are provisioned.

## Enforcement and error contract

Backend middleware enforces commercial Module entitlement at the shared route
boundary. Frontend visibility is only a UX mirror.

The security order is:

```text
Authentication
-> active User, Membership, and Tenant
-> tenant-scoped resource lookup when applicable
-> Module entitlement
-> RBAC
-> domain lifecycle
```

This ordering preserves cross-Tenant non-disclosure: a foreign or unavailable
resource remains `404 resource_not_found` and is not converted into an
entitlement response.

A same-Tenant request to an unavailable Module returns the canonical CP17
contract:

```json
{
  "message": "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية.",
  "error": {
    "code": "module_not_entitled",
    "message": "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية."
  }
}
```

The HTTP status is `403`.

## Authentication and frontend projection

Login and `GET /api/auth/user` return the effective commercial keys as:

```json
{
  "effective_modules": [
    "projects",
    "units",
    "customers",
    "crm",
    "reservations",
    "contracts",
    "collections"
  ]
}
```

The frontend uses this projection for Sidebar navigation, direct commercial
page guards, contextual actions, and Dashboard loading. Manual URLs and direct
API calls remain protected by backend enforcement.

Dashboard is a core capability and remains accessible. It does not request or
render a widget whose source Module is unavailable, preventing avoidable 403
chains while preserving independent widget loading and error handling.

## Provisioning policy

Migrations create only schema, foreign keys, constraints, indexes, and required
PostgreSQL support. They never provision a Tenant or hard-code environment
identity or License dates.

The idempotent `nexusos:provision-entitlements` Artisan command is the official
provisioning path. It creates/verifies the fixed seven-Module catalog and the
`pilot_full` Plan, resolves an explicit Tenant ULID or slug, requires explicit
timezone-qualified License dates, rejects invalid or overlapping periods, and
verifies the final effective Module set.

An exact rerun is a verified no-op. The command never silently replaces,
expires, cancels, or edits an existing commercial period. Demo and Live are
provisioned independently in their own databases with explicit arguments.

## Out of scope

Entitlements v1 does not include billing, payments, checkout, pricing UI,
renewal automation, Plan changes, external licensing servers, quotas, storage
limits, usage limits, or a generic feature-flag/permission engine.
