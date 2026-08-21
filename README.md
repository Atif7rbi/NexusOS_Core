# NexusOS

NexusOS is a modular, multi-tenant Business Operating System designed to provide a shared operational foundation for business management and ERP capabilities.

This repository contains the official **NexusOS Core** product.

## Repository

```text
Atif7rbi/NexusOS_Core
```

The `main` branch represents the approved Core baseline.

## Structure

- `backend/` — Laravel 12 API and business domains.
- `frontend/` — Next.js application.
- `docs/` — Architecture, domain specifications, engineering decisions, and project context.

## Technology Baseline

### Backend

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- PostgreSQL
- PHPUnit

### Frontend

- Next.js 16
- React 19
- TypeScript
- Tailwind CSS
- Static export where required by the deployment model

## Product Direction

NexusOS is evolving from a validated operational foundation into a reusable commercial Business Operating System and ERP platform.

The Core is not tied to a specific customer.

Customer-specific deployments are downstream installations of approved NexusOS Core releases and must not define the architecture or identity of this repository.

## Engineering Principles

- Architecture before implementation.
- Database integrity as a first-class concern.
- Explicit behavior over hidden magic.
- Simple designs before speculative abstractions.
- Domain ownership of business rules.
- Multi-tenant isolation by design.
- Auditable financial and operational behavior.
- Incremental, reviewed, production-safe evolution.

## Development Workflow

The normal workflow is:

```text
inspect
→ design / approve
→ feature branch
→ implement
→ test
→ review
→ commit / push
→ pull request
→ approve
→ merge
→ deploy to validation environment
→ verify
```

Direct product development should not be performed in customer-specific repositories.

## Current Development Environment

The current server-side validation checkout may be located at:

```text
/home/sewaellf/nexusos-core-demo
```

The current Demo deployment is:

```text
https://demo.sewarsky.online
```

These are operational environment details only and are not part of the NexusOS product identity.

## Documentation

The primary architectural and operational context is maintained under:

```text
docs/MASTER_CONTEXT/
```

Detailed module and domain specifications live under their respective directories in `docs/`.

When a high-level summary conflicts with a newer approved domain or architecture document, the newer explicitly approved document takes precedence.

## Core / Client Separation

The governing direction is:

```text
NexusOS Core
→ develop
→ test
→ review
→ approve
→ release
```

Only after approval may a Core release be used to upgrade a customer-specific deployment.

Customer-specific code, branding, migration data, domains, credentials, or infrastructure assumptions must not become part of NexusOS Core unless intentionally generalized as product capability.
