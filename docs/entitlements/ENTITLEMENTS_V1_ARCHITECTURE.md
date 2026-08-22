# NexusOS Entitlements v1 Architecture

Version: 1.2

Status: Implemented and runtime-validated — final handoff hardening in progress

## Purpose

Entitlements v1 defines whether a Tenant may use a commercial NexusOS Module and carries approved Plan-level and License-level commercial limits. Entitlement is independent from RBAC: an operation is available only when the Tenant is entitled to its Module and the authenticated user is authorized by the existing domain rules.

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
- `tenant_licenses`: Tenant Plan history, lifecycle status, explicit period, and nullable `users_limit_override`.
- `tenant_modules`: optional unique Tenant-to-Module `enabled` or `disabled` override.

`plans.users_limit` is either null (unlimited) or a positive integer. `tenant_licenses.users_limit_override` is either null (inherit the Plan limit) or a positive integer that overrides the Plan limit for that License period.

Approved Entitlements v1 Plans are:

- `business_full`: all seven commercial Modules, `users_limit = 5`.
- `demo_full`: all seven commercial Modules, `users_limit = null` (unlimited Demo usage).

The former `pilot_full` key is renamed to `business_full` by a forward Core
migration that preserves the existing Plan row and its License relationships.

Foreign keys use restrictive deletion. CHECK constraints protect status, period, Plan seat limits, and License seat overrides. A PostgreSQL trigger rejects overlapping entitled periods for one Tenant without requiring an extension.

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

`ResolveCurrentTenantLicense` resolves the current entitled License. `ResolveTenantUserLimit` resolves the effective seat policy using:

```text
effective_users_limit = tenant_license.users_limit_override ?? plan.users_limit
```

This allows commercial upgrades for one customer without changing the shared Plan definition. For example, a Tenant on the current five-seat commercial Plan inherits five seats by default; setting its current License override to eight raises only that Tenant to eight seats.

Seat consumption is Tenant-scoped:

- `active`, `paused`, and `suspended` Tenant memberships consume a seat.
- `removed` memberships do not consume a seat.
- `system_owner` is a platform-level identity: it does not require Tenant membership, does not consume a Tenant seat, and is not exposed through Tenant Users management.
- A null effective limit means unlimited.
- If no current License policy can be resolved, new Tenant-user creation fails closed.

The backend serializes seat allocation per Tenant and is authoritative. Frontend counts are informational only.

Seat-limit changes are administrative commercial operations. `nexusos:set-user-limit` updates only the current License override. `--limit=<positive integer>` sets a customer-specific limit and `--inherit` clears the override so the License returns to its Plan limit.

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

The idempotent `nexusos:provision-entitlements` Artisan command is the official provisioning path. It creates/verifies the fixed seven-Module catalog, an approved Entitlements v1 Plan (`business_full` or `demo_full`), and an explicitly dated current Tenant License. An optional positive `--users-limit-override` may be supplied when the License is first provisioned.

An exact rerun is a verified no-op. The provisioning command never silently replaces, expires, cancels, or edits an existing commercial period. Later seat upgrades on the current License use `nexusos:set-user-limit` instead of creating a replacement License.

Demo and Live are provisioned independently. Demo uses `demo_full`; customer environments use the approved commercial Plan and may receive a License-specific seat override when commercially authorized.

## Out of scope

Entitlements v1 does not include billing, payments, checkout, pricing UI, renewal automation, external licensing servers, storage quotas, usage quotas, or a Platform Owner Console. Plan lifecycle administration and automated renewal remain future Platform Owner capabilities.
