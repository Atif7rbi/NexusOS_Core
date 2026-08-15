# NexusOS Entitlements v1 — Provisioning Runbook

Status: Approved for CP18–CP20 implementation

## Scope

This runbook provisions the approved commercial Module catalog, the
`pilot_full` Plan, and one explicitly dated current Tenant License. It does
not provision quotas, billing, payments, or environment-specific identity.

Commercial Modules:

- `projects`
- `units`
- `customers`
- `crm`
- `reservations`
- `contracts`
- `collections`

Dashboard, Users, Settings, authentication, and Company Profile remain core
platform capabilities and are not controlled by commercial entitlements.

## Required release order

Run the following sequence independently against each environment database:

1. Put the application into maintenance mode.
2. Deploy the approved code.
3. Run the approved migrations.
4. Run the provisioning command with the exact Tenant and approved dates.
5. Verify that all seven effective Modules are reported.
6. Clear or rebuild the required Laravel caches.
7. Bring the application up.
8. Perform authentication, navigation, and representative API smoke checks.

No Demo, UFQ, database-name, email, or environment bypass exists. Demo and
UFQ must each be provisioned in their own database.

## Command

```bash
php artisan nexusos:provision-entitlements \
  --tenant='<tenant-ulid-or-exact-slug>' \
  --plan='pilot_full' \
  --status='active' \
  --starts-at='<ISO-8601 timestamp with timezone>' \
  --ends-at='<ISO-8601 timestamp with timezone>'
```

For `grace`, `--grace-ends-at` is required and must be later than
`--ends-at`. It must be omitted for other statuses.

The command does not choose a default commercial period. The deployment
operator must provide both dates explicitly.

## Safe reruns and conflicts

An exact rerun for the same Tenant, Plan, status, period, and effective
configuration is a verified no-op. An overlapping entitled License period is a
conflict and the command fails without replacing, expiring, cancelling, or
otherwise mutating the existing License. A time-expired historical License does
not block a later non-overlapping period.

Renewal and Plan changes are outside Entitlements v1.
