# NexusOS Entitlements v1 — Provisioning Runbook

Status: Implemented — production runtime verification required after each deployment

## Scope

This runbook provisions the approved commercial Module catalog, the `pilot_full` Plan, its commercial user-seat policy, and one explicitly dated current Tenant License.

Commercial Modules:

- `projects`
- `units`
- `customers`
- `crm`
- `reservations`
- `contracts`
- `collections`

`pilot_full` has `users_limit = 5`. `system_owner` is platform-level and does not consume a Tenant seat or appear in Tenant Users management. Removed memberships do not consume a seat; active, paused, and suspended memberships do.

Dashboard, Users, Settings, authentication, and Company Profile remain core platform capabilities and are not commercial Modules.

## Required release order

Run independently against each environment database:

1. Take/verify the required environment backup.
2. Put the application into maintenance mode when appropriate.
3. Deploy the approved code.
4. Run approved migrations.
5. Run the provisioning command with the exact Tenant and approved dates when provisioning a new entitlement period.
6. Verify all seven effective Modules and `pilot_full.users_limit = 5`.
7. Configure the exact environment CORS origin (`CORS_ALLOWED_ORIGINS`) and rebuild Laravel config cache.
8. Bring the application up.
9. Perform authentication, navigation, representative API, seat-policy, and CORS smoke checks.

Demo and UFQ are provisioned independently in their own databases. No Demo, email, Tenant, or environment bypass is permitted.

## Command

```bash
php artisan nexusos:provision-entitlements \
  --tenant='<tenant-ulid-or-exact-slug>' \
  --plan='pilot_full' \
  --status='active' \
  --starts-at='<ISO-8601 timestamp with timezone>' \
  --ends-at='<ISO-8601 timestamp with timezone>'
```

For `grace`, `--grace-ends-at` is required and later than `--ends-at`.

## CORS

Use exact origins only. Examples:

```text
Demo: CORS_ALLOWED_ORIGINS=https://demo.sewarsky.online
UFQ:  CORS_ALLOWED_ORIGINS=https://ufq.sewarsky.online
```

Comma-separated exact origins are supported when an environment genuinely requires more than one. Do not use `*` in production.

## Safe reruns and conflicts

An exact provisioning rerun is a verified no-op. An overlapping entitled License period is a conflict and fails without replacing, expiring, cancelling, or editing the existing License.

Renewal, Plan changes, and Tenant-specific seat overrides remain outside Entitlements v1 and belong to the future Platform Owner lifecycle.
