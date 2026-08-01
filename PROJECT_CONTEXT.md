# NexusOS Pilot / UFQ Pilot — Project Context

## Purpose

NexusOS Pilot is a real, client-usable business operating system pilot. It is not a throwaway demo and it is not the final commercial NexusOS Core product.

The pilot exists to:

- onboard the first real client quickly;
- operate with real business data and workflows;
- validate product decisions through actual use;
- collect concrete client requirements;
- reach production use and initial revenue without premature over-engineering.

The governing product question is:

> Does the first client need this for an operational release?

If yes, implement it correctly and narrowly. If no, defer it unless explicitly approved.

## Ownership and Roles

- Product Owner: ATIF Al7rbi.
- Software architecture and domain decisions are approved explicitly before implementation when they affect business rules, database structure, or public contracts.
- Coding agents act as implementation engineers, not autonomous product owners.
- GitHub is the canonical source of truth.

## Repository

- GitHub: `Atif7rbi/ufq-pilot`
- Default branch: `main`
- Monorepo areas:
  - `backend/`: Laravel 12 API.
  - `frontend/`: Next.js 16 application.
  - `docs/`: architecture, workflow, domain, and module specifications.

## Technology Baseline

### Backend

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- PostgreSQL
- PHPUnit 11
- Composer

### Frontend

- Next.js 16 App Router
- React 19
- TypeScript 5
- Tailwind CSS 4
- ESLint 9
- Static export is configured through Next.js `output: "export"`.

## Architecture Summary

The backend follows a database-first, layered approach:

- Presentation: controllers, requests, middleware, authentication, response formatting.
- Application/domain: actions, use cases, DTOs, policies, validation, lifecycle behavior.
- Infrastructure: framework services, jobs, logging, bindings, integrations.
- Database: PostgreSQL constraints, foreign keys, indexes, and transactional integrity as the final line of defense.

Backend domain modules are located under `backend/app/Modules/`, including:

- Collections
- Contracts
- Customers
- Projects
- Reservations
- Shared
- Units
- Users

The frontend uses:

- routes/pages under `frontend/src/app`;
- shared UI and layout components under `frontend/src/components`;
- API clients under `frontend/src/services`;
- typed contracts under `frontend/src/types`;
- providers for authentication, theme, language, shell state, and system settings.

The frontend API base is configured using `NEXT_PUBLIC_API_BASE_URL`.

## Approved Engineering Principles

- Fastest path to a client-usable release.
- Correct and maintainable, but no speculative architecture.
- Database constraints remain authoritative where established.
- Reuse shared components and existing domain patterns.
- Keep modules isolated and changes focused.
- Discuss and approve business/domain/database changes before implementation.
- Stabilization work should not quietly introduce new product scope.

## Working Process

The normal delivery sequence is:

1. Understand and inspect.
2. Break the request into focused tasks.
3. Confirm architectural or business-rule implications.
4. Implement on a dedicated branch.
5. Run relevant tests, lint, and builds.
6. Report changed files and verified results.
7. Obtain Product Owner approval.
8. Commit and push when requested.
9. Open a Draft Pull Request when requested or when validation is pending.
10. Merge only after approval.
11. Update and deploy the server only after merge approval.
12. Verify production behavior.

## Current Product Status

The following areas have reached client-facing or production-ready pilot stages:

- Authentication
- Dashboard v1
- Projects Workflow v1
- Customers Workflow v1
- Shared Form Validation UX
- Units backend and frontend work
- Reservations workflow
- Contracts workflow
- Collections Index v1

The latest known merged improvement at the time this file was introduced is Pull Request #10:

- Collections search matches customer name, project name, and unit number.
- Collection summary cards remain stable during search.
- The mobile user menu opens, displays user information and logout, closes on outside click, and logout works.
- Validation reported 233 tests and 1616 assertions passing, with lint and build passing.

Always verify the live repository state instead of assuming this summary is current.

## Operational Paths

A server checkout may be located at:

```text
/home/sewaellf/ufq-pilot
```

A local Ubuntu checkout may be located at:

```text
/home/s4d/Sniper/online/NexusOS/ufq-pilot
```

These paths are operational context only. The repository remains the source of truth, and agents must verify the actual path, branch, status, and commit before acting.

## Important Safety Notes

- The server may contain untracked operational or diagnostic files. Inspect them before deletion.
- Do not assume a clean local checkout means the server checkout is also clean.
- Never expose SSH keys, credentials, environment variables, private configuration, or client data.
- Do not run production migrations, deployment steps, package upgrades, or destructive commands without explicit approval.
- Do not use direct production edits as a substitute for the approved Git/PR workflow.
- Namecheap shared hosting may restrict Linux sandbox/user namespace behavior. Treat this as an environment limitation and report it accurately.

## Primary Verification Commands

### Repository state

```bash
git status --short --branch
git remote -v
git log -1 --oneline
```

### Backend

From `backend/`:

```bash
php artisan test
composer test
```

### Frontend

From `frontend/`:

```bash
npm run lint
npm run build
```

## Documentation Authority

Detailed and frozen decisions belong in `docs/`. This context file is an operational orientation document, not a replacement for module specifications, migration strategies, database freeze documents, or approved policy documents.

When this summary and a detailed approved document differ, use the approved detailed document and report the discrepancy.
