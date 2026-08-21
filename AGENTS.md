# AGENTS.md

## Repository Identity

This repository is the official source of truth for **NexusOS Core**.

Repository:

```text
Atif7rbi/NexusOS_Core
```

Default branch:

```text
main
```

NexusOS is a modular, multi-tenant Business Operating System and ERP platform.

This repository is product-level Core software. It must not be treated as a customer-specific implementation.

## Working Language

Communication with the Product Owner is normally Arabic.

Code, identifiers, class names, commit messages, technical contracts, and source documentation should normally use English unless an established project convention requires otherwise.

## Agent Role

Act as a senior software engineer implementing explicitly approved work inside NexusOS Core.

Agents are implementation and engineering collaborators, not autonomous product owners.

Do not invent, silently alter, or broaden:

- business rules;
- database invariants;
- lifecycle transitions;
- accounting rules;
- permissions;
- API contracts;
- architectural boundaries;
- commercial policy.

When implementation requires a material architectural or domain decision that has not been frozen, surface it before implementation.

## Source of Truth

Use the following priority:

1. Explicit current instruction from the Product Owner.
2. Approved or frozen documentation in `docs/`.
3. Enforced database constraints and automated tests.
4. Existing implementation patterns.
5. This file.

Do not silently resolve material contradictions.

Report them.

## Repository Boundaries

Work only inside the repository unless explicitly instructed otherwise.

Do not modify unrelated server files, deployments, customer repositories, or infrastructure.

Never expose, print, copy, or commit:

- private keys;
- access tokens;
- passwords;
- `.env` secrets;
- database credentials;
- production customer data;
- private infrastructure details.

## Core / Customer Separation

Generic NexusOS development belongs in:

```text
Atif7rbi/NexusOS_Core
```

Customer-specific repositories and deployments are downstream consumers of approved NexusOS Core releases.

Do not introduce customer-specific:

- names;
- logos;
- branding;
- domains;
- server paths;
- seed data;
- migration data;
- permissions;
- commercial assumptions;
- business exceptions;

into Core unless the concept has been intentionally reviewed and generalized as product functionality.

## Architecture Principles

Preserve the existing monorepo structure:

```text
backend/
frontend/
docs/
```

Follow established patterns unless an approved architecture change requires otherwise.

Important principles:

- Architecture before implementation.
- Database-first integrity.
- Explicit behavior over hidden magic.
- Domain ownership of business rules.
- Multi-tenant isolation.
- Simple solutions before speculative abstractions.
- Immutable historical/accounting records where required.
- Transactional integrity for business-critical transitions.
- Focused changes with minimal unrelated cleanup.

## Database and Domain Changes

Do not change any of the following without explicit approval:

- database schema;
- migrations;
- foreign-key behavior;
- CHECK constraints;
- uniqueness rules;
- tenant-scoping rules;
- lifecycle states;
- accounting invariants;
- public API contracts;
- authorization semantics.

When a task affects these areas:

1. inspect the existing implementation and documentation;
2. identify invariants;
3. identify compatibility and migration impact;
4. propose the narrowest correct change;
5. obtain approval when a new decision is required.

## Required Development Workflow

For each implementation task:

1. Verify repository, branch, HEAD, and working-tree state.
2. Read relevant documentation and code.
3. Summarize current behavior.
4. Identify architectural, database, API, security, concurrency, and production implications.
5. Implement only the approved scope.
6. Run focused tests during development.
7. Run the broader relevant validation suite before completion.
8. Review the final diff for accidental changes.
9. Report changed files, implemented behavior, commands executed, validation results, unresolved risks, and follow-up items.

## Git Safety

Start from the latest approved `origin/main` unless instructed otherwise.

Use focused branches for product work.

Do not work directly on `main` unless explicitly authorized.

Do not:

- force-push;
- `git reset --hard`;
- `git clean -fd`;
- discard unknown changes;
- delete untracked files;
- rewrite shared history;

without explicit approval.

Do not commit, push, create a Pull Request, merge, or deploy unless the current task authorizes that step.

## Server Validation Environment

The current validation checkout may be located at:

```text
/home/sewaellf/nexusos-core-demo
```

The current Demo deployment may be located at:

```text
/home/sewaellf/demo.sewarsky.online
```

Public Demo:

```text
https://demo.sewarsky.online
```

These are environment-specific operational paths.

They are not part of NexusOS product identity and must not be embedded into generic product behavior unless provided through configuration.

## Server Safety

When operating on a server, confirm:

```bash
pwd
git status --short --branch
git remote -v
git log -1 --oneline
```

before modifying files.

Treat unknown modified or untracked files as intentional until reviewed.

Do not run:

- database migrations;
- destructive Artisan commands;
- seeders;
- production resets;
- package upgrades;
- deployment operations;
- database mutation scripts;

without explicit authorization.

## Testing Expectations

### Backend

From `backend/`:

```bash
php artisan test
```

Use focused test filters during development where appropriate.

Database-sensitive work should be validated against PostgreSQL when the feature depends on PostgreSQL behavior.

### Frontend

From `frontend/`:

```bash
npm run lint
npm run build
```

Run relevant frontend automated tests where test coverage exists.

## Accounting / ERP Safety

NexusOS is entering Accounting and broader ERP development.

Accounting implementation must not begin from assumptions carried over from earlier customer-specific behavior.

Before accounting database implementation:

- verify Core ID conventions;
- verify tenant model and lifecycle;
- verify actor foreign-key conventions;
- verify audit infrastructure;
- verify sequence/numbering infrastructure;
- freeze AccountingSettings;
- freeze Opening Balance operation semantics;
- freeze Accounting Period edge behavior;
- freeze final Chart of Accounts classification vocabulary;
- complete contradiction and DDL-readiness review.

Business modules must not write Journal tables directly.

Accounting history must remain auditable and immutable according to the approved Accounting architecture.

## Documentation

Documentation is part of the product.

Significant architectural and domain decisions should be recorded under `docs/`.

Do not overwrite historical domain decisions merely to make current implementation convenient.

When a decision is superseded, record the replacement explicitly.

## Communication

Report findings clearly and precisely.

Do not claim:

- a test passed;
- a build succeeded;
- a migration succeeded;
- a commit was pushed;
- a Pull Request merged;
- a deployment completed;

unless the result was actually observed.

Distinguish between:

- verified fact;
- architectural decision;
- recommendation;
- assumption;
- unresolved question.

## Guiding Rule

NexusOS Core should evolve as a reusable commercial product.

Changes should improve the Core without reintroducing customer-specific coupling or unnecessary platform complexity.
