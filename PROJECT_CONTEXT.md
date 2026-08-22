# NexusOS Core — Project Context

## Purpose

NexusOS is a modular, multi-tenant Business Operating System intended to provide a coherent operational and ERP platform for organizations.

This repository is the official NexusOS Core product repository.

NexusOS Core is not a customer-specific implementation, demonstration fork, or temporary pilot repository.

Its purpose is to provide a reusable and maintainable product foundation from which approved customer deployments can be derived.

## Product Vision

NexusOS aims to become a comprehensive Business Operating System that integrates operational workflows, customer management, projects, commercial processes, financial operations, accounting, reporting, permissions, and future ERP capabilities within one consistent architecture.

The platform should remain:

- multi-tenant;
- modular;
- auditable;
- operationally practical;
- commercially reusable;
- maintainable over the long term;
- extensible without unnecessary architectural redesign.

## Repository

Official repository:

```text
Atif7rbi/NexusOS_Core
```

Default branch:

```text
main
```

GitHub is the canonical source of truth.

## Repository Structure

```text
backend/
frontend/
docs/
```

### Backend

Laravel-based API and business-domain implementation.

### Frontend

Next.js application containing the user-facing NexusOS interface.

### Documentation

Architecture, domain specifications, frozen decisions, implementation contracts, roadmap, and engineering context.

## Technology Baseline

### Backend

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- PostgreSQL
- PHPUnit
- Composer

### Frontend

- Next.js 16 App Router
- React 19
- TypeScript
- Tailwind CSS
- ESLint
- Static export where required by the deployment architecture

## Architecture Direction

NexusOS follows a database-first, domain-oriented, layered architecture.

The primary responsibility boundaries are:

### Presentation

Controllers, requests, authentication, middleware, API contracts, and user-facing interaction boundaries.

### Application / Domain

Business actions, lifecycle rules, policies, validation, use cases, DTOs, concurrency behavior, and domain invariants.

### Infrastructure

Framework integration, jobs, technical services, logging, external integrations, and platform plumbing.

### Database

PostgreSQL constraints, foreign keys, indexes, transactions, locking, and database-level integrity guarantees.

The database is the final line of defense for invariants that can and should be enforced structurally.

## Product Modules

The current Core contains operational foundations across areas including:

- Authentication
- Tenants
- Users
- Roles and authorization
- Entitlements
- Projects
- Customers
- Units
- CRM Leads
- Lead follow-ups and conversion
- Reservations
- Contracts
- Collection schedules
- Dashboard and operational views
- Shared validation and platform infrastructure

The exact current implementation status must be verified from the repository and current documentation rather than inferred from this summary.

## ERP Direction

NexusOS is entering a broader ERP evolution.

The high-level direction includes:

```text
Existing Operational Core
→ Accounting Core
→ Receivables and Payments
→ Vendors, Expenses and Accounts Payable
→ Project Costing
→ Budgeting
→ Financial and ERP Reporting
→ Approval Workflows
→ Document Management
→ ERP Hardening
→ Commercial ERP Release
```

Implementation must remain incremental.

Large ERP scope must not be introduced before the relevant architecture, invariants, database model, and integration boundaries are explicitly reviewed and approved.

## Accounting Direction

The accounting foundation follows:

```text
Business Transaction
→ Accounting Rule
→ Journal Entry
→ Journal Lines
→ Ledger
→ Financial Reporting
```

Operational modules must not write accounting tables directly.

Accounting Core owns journal integrity, posting invariants, numbering, locking, persistence, and immutable accounting history.

Business modules own the accounting meaning of their business transactions.

## Engineering Principles

### Architecture Before Implementation

Business rules, data ownership, lifecycle behavior, and database implications should be understood before significant implementation begins.

### Database First

Important integrity rules should be enforced at the database level whenever structurally appropriate.

### Explicit Over Implicit

Avoid hidden behavior and framework magic when it obscures business meaning or lifecycle transitions.

### Simplicity Before Abstraction

Do not introduce generalized frameworks before real use cases prove the need.

### Domain Ownership

Every business rule should have a clear owning domain.

Do not duplicate business responsibility across controllers, services, models, and UI layers.

### Multi-Tenant Safety

Tenant isolation is a fundamental Core invariant.

Cross-tenant references must be prevented or validated at the strongest practical boundary.

### Production-Safe Evolution

Features should be developed incrementally, validated thoroughly, and merged only after review.

## Core and Customer Deployment Separation

NexusOS Core is developed independently of customer-specific deployments.

The direction is:

```text
NexusOS_Core
→ develop
→ test
→ review
→ approve
```

Then, when appropriate:

```text
approved NexusOS Core release
→ customer upgrade
```

Customer-specific repositories or deployments must not become the development source for generic NexusOS functionality.

Customer names, customer-specific defaults, branding, infrastructure, credentials, migration data, or operational assumptions must not be embedded in NexusOS Core unless intentionally converted into generic product capability.

## Current Server Validation Environment

The current server working copy may be located at:

```text
/home/sewaellf/nexusos-core-demo
```

The Demo frontend is currently deployed under:

```text
/home/sewaellf/demo.sewarsky.online
```

Public Demo URL:

```text
https://demo.sewarsky.online
```

The backend public directory is connected to the Demo deployment through the server environment.

These paths are operational context only.

They must not be treated as product architecture or customer identity.

## Development Workflow

The normal development sequence is:

1. Inspect the current repository state.
2. Read relevant approved documentation.
3. Identify architecture, database, API, security, and domain implications.
4. Discuss and freeze important business decisions.
5. Create a focused feature branch.
6. Implement only the approved scope.
7. Run relevant backend tests.
8. Run relevant frontend tests, lint, and build.
9. Review the final diff.
10. Commit and push.
11. Review through Git / Pull Request workflow.
12. Merge only after approval.
13. Deploy the approved result to the validation environment.
14. Verify runtime behavior.

## Git Policy

- `main` is the approved Core baseline.
- Product work should normally use dedicated branches.
- Do not silently rewrite history.
- Do not force-push without explicit authorization.
- Do not discard unknown local or server changes.
- Do not bypass the approved review process for production-affecting work.

## Safety Rules

Never expose or commit:

- passwords;
- access tokens;
- private keys;
- `.env` credentials;
- production secrets;
- customer data;
- private infrastructure credentials.

Do not run destructive migrations, database resets, deployment operations, or production changes without explicit authorization.

## Verification Commands

### Repository

```bash
git status --short --branch
git remote -v
git log -1 --oneline
```

### Backend

From `backend/`:

```bash
php artisan test
```

Additional focused tests may be used during implementation.

### Frontend

From `frontend/`:

```bash
npm run lint
npm run build
```

Run frontend automated tests when the affected area has test coverage.

## Documentation Authority

`PROJECT_CONTEXT.md` is an orientation document.

Detailed approved architecture and domain documents under `docs/` remain authoritative for their respective scopes.

When documents conflict:

1. explicit current Product Owner decision;
2. newer approved/frozen architecture or domain document;
3. enforced database constraints and tests;
4. implementation;
5. this orientation document.

Material conflicts should be reported and resolved explicitly rather than silently guessed.

## Current Transition

The repository was created from a mature operational implementation that originated during an earlier customer-validation phase.

Some historical names, defaults, tests, migrations, documentation, and runtime identifiers may therefore still reference the former Pilot or customer-specific context.

Those remnants are being systematically generalized before major ERP and Accounting Core development continues.

No new accounting migrations should be introduced until this Core generalization and architectural verification work is complete.
