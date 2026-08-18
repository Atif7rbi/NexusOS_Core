# NexusOS Entitlements v1 — Provisioning Runbook

Status: Implemented — production runtime verification required after each deployment

## Scope

This runbook provisions the approved commercial Module catalog, an approved Plan, its user-seat policy, and one explicitly dated current Tenant License.

Commercial Modules:

- `projects`
- `units`
- `customers`
- `crm`
- `reservations`
- `contracts`
- `collections`

Approved Plans:

- `pilot_full`: all seven Modules, `users_limit = 5`.
- `demo_full`: all seven Modules, `users_limit = null` (unlimited).

A current Tenant License may carry `users_limit_override`. When present it overrides the Plan seat limit for that Tenant and License period. When null, the Plan limit is inherited.

`system_owner` is platform-level and does not consume a Tenant seat or appear in Tenant Users management. Removed memberships do not consume a seat; active, paused, and suspended memberships do.

Dashboard, Users, Settings, authentication, and Company Profile remain core platform capabilities and are not commercial Modules.

## Required release order

Run independently against each environment database:

1. Take/verify the required environment backup.
2. Put the application into maintenance mode when appropriate.
3. Deploy the approved code.
4. Run approved migrations.
5. Run the provisioning command with the exact Tenant, approved Plan, and approved dates when provisioning a new entitlement period.
6. Verify all seven effective Modules and the effective user-seat policy.
7. Configure the exact environment CORS origin (`CORS_ALLOWED_ORIGINS`) and rebuild Laravel config cache.
8. Bring the application up.
9. Perform authentication, navigation, representative API, seat-policy, and CORS smoke checks.

Demo and UFQ are provisioned independently in their own databases. No Demo, email, Tenant, or environment bypass is permitted.

## Provisioning commands

Customer/Pilot example:

```bash
php artisan nexusos:provision-entitlements \
  --tenant='<tenant-ulid-or-exact-slug>' \
  --plan='pilot_full' \
  --status='active' \
  --starts-at='<ISO-8601 timestamp with timezone>' \
  --ends-at='<ISO-8601 timestamp with timezone>'
```

Demo example:

```bash
php artisan nexusos:provision-entitlements \
  --tenant='<demo-tenant-ulid-or-exact-slug>' \
  --plan='demo_full' \
  --status='active' \
  --starts-at='<ISO-8601 timestamp with timezone>' \
  --ends-at='<ISO-8601 timestamp with timezone>'
```

For `grace`, `--grace-ends-at` is required and later than `--ends-at`.

A positive customer-specific seat override may be supplied during initial provisioning:

```text
--users-limit-override=8
```

## Changing a current customer seat limit

Use the dedicated administrative command. This changes only the current entitled License and does not alter the shared Plan or any other Tenant.

Set an override:

```bash
php artisan nexusos:set-user-limit \
  --tenant='<tenant-ulid-or-exact-slug>' \
  --limit=8
```

Return the License to its Plan limit:

```bash
php artisan nexusos:set-user-limit \
  --tenant='<tenant-ulid-or-exact-slug>' \
  --inherit
```

For example, UFQ on `pilot_full` inherits five seats. If a commercial upgrade is approved to eight seats, `--limit=8` makes its effective limit eight without affecting other `pilot_full` customers.

## CORS

Use exact origins only. Examples:

```text
Demo: CORS_ALLOWED_ORIGINS=https://demo.sewarsky.online
UFQ:  CORS_ALLOWED_ORIGINS=https://ufq.sewarsky.online
```

Comma-separated exact origins are supported when an environment genuinely requires more than one. Do not use `*` in production.

## Safe reruns and conflicts

An exact provisioning rerun is a verified no-op. An overlapping entitled License period is a conflict and fails without replacing, expiring, cancelling, or editing the existing License.

Seat upgrades on an existing current License use `nexusos:set-user-limit`; they do not require a replacement License period.

Renewal automation, broader Plan lifecycle changes, and a Platform Owner Console remain outside Entitlements v1.
