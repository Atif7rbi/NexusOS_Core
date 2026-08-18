# NexusOS Entitlements v1 Architecture

Version: 1.1

Status: Implemented and runtime-validated — final handoff hardening in progress

## Purpose

Entitlements v1 defines whether a Tenant may use a commercial NexusOS Module and carries approved Plan-level commercial limits. Entitlement is independent from RBAC: an operation is available only when the Tenant is entitled to its Module and the authenticated user is authorized by the existing domain rules.

## Commercial Modules

The immutable commercial Module keys are:

- `projects`
- `units`
- `customers`
- `crm`
- `reservations`
- `contracts`
- `collections`

Authentication, Dashboard, Users, Settings, and Company Profile are core platform capabilities. They are not commercial Modules and are never disabled by Plan entitlement.

## Schema

Entitlements v1 uses:

- `modules`: immutable catalog key, Arabic/English names, and catalog status.
- `plans`: commercial Plan key, names, status, and nullable `users_limit`.
- `plan_modules`: unique Plan-to-Module membership.
- `tenant_licenses`: Tenant Plan history, lifecycle status, and explicit period.
- `tenant_modules`: optional unique Tenant-to-Module `enabled` or `disabled` override.

`plans.users_limit` is either null (unlimited) or a positive integer. The approved `pilot_full` Plan has `users_limit = 5`.

Foreign keys use restrictive deletion. CHECK constraints protect status, period, and seat-limit values. A PostgreSQL trigger rejects overlapping entitled periods for one Tenant without requiring an extension.

## License lifecycle and time semantics

Entitled statuses are `trial`, `active`, and `grace`. Terminal/non-entitled statuses are `expired` and `cancelled`.

All License timestamps are compared as UTC instants. `active` and `trial` are current when `starts_at <= now <= ends_at`. `grace` is current when `starts_at <= now <= grace_ends_at`.

## Effective entitlement resolution

`ResolveEffectiveTenantModules` is the Module source of truth:

```text
inactive Module catalog entry -> DENY
Tenant override = disabled     -> DENY
Tenant override = enabled      -> ALLOW
no Tenant override             -> current License Plan membership
no valid current License       -> DENY
```

The resolver is fail-closed. It does not infer entitlement from role, Tenant identity, email, deployment environment, Demo Mode, or frontend state.

## Commercial user-seat policy

`ResolveCurrentTenantLicense` resolves the current entitled License. `ResolveTenantUserLimit` resolves the current Plan seat policy.

Seat consumption is Tenant-scoped:

- `active`, `paused`, and `suspended` Tenant memberships consume a seat.
- `removed` memberships do not consume a seat.
- `system_owner` is a platform-level identity: it does not require Tenant membership, does not consume a Tenant seat, and is not exposed through Tenant Users management.
- A null `users_limit` means unlimited.
- If no current Plan policy can be resolved, new Tenant-user creation fails closed.

The backend serializes seat allocation per Tenant and is authoritative. Frontend counts are informational only.

## Plan dependencies

```text
units        -> projects
crm          -> customers
reservations -> projects + units + customers
contracts    -> reservations
collections  -> contracts
```

The runtime resolver does not add dependencies implicitly.

## Enforcement and error contract

Backend middleware enforces commercial Module entitlement. Frontend visibility is only a UX mirror.

The security order is:

```text
Authentication
-> active User, Membership, and Tenant
-> tenant-scoped resource lookup when applicable
-> Module entitlement
-> RBAC
-> domain lifecycle
```

Unavailable commercial Modules return `403 module_not_entitled`. Seat exhaustion returns `409 user_seat_limit_reached`. An unavailable seat policy returns `409 user_seat_limit_unavailable`.

## Authentication and frontend projection

Login and `GET /api/auth/user` return `effective_modules`. The frontend uses that projection for navigation and UX guards while backend enforcement remains authoritative.

## Provisioning policy

The idempotent `nexusos:provision-entitlements` Artisan command is the official provisioning path. It creates/verifies the fixed seven-Module catalog, the `pilot_full` Plan, the approved five-seat policy, and an explicitly dated current Tenant License.

An exact rerun is a verified no-op. The command never silently replaces, expires, cancels, or edits an existing commercial period. Demo and Live are provisioned independently.

## Out of scope

Entitlements v1 does not include billing, payments, checkout, pricing UI, renewal automation, Plan changes, external licensing servers, storage quotas, usage quotas, or Tenant-specific seat overrides. Tenant overrides and commercial lifecycle administration belong to the future Platform Owner Console.
