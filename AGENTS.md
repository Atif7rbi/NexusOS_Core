# AGENTS.md

## Repository Identity

This repository is the official source of truth for **NexusOS Pilot / UFQ Pilot**.

- Repository: `Atif7rbi/ufq-pilot`
- Product Owner: ATIF Al7rbi
- Primary goal: deliver the fastest path to a client-usable, production-ready pilot without compromising the approved architecture.
- Working language with the Product Owner: Arabic.
- Code, identifiers, comments, commit messages, and technical artifacts: English unless an existing project convention requires otherwise.

## Agent Role

Act as a senior software engineer executing approved work inside this repository.

Do not assume authority to redesign the product, domain, database, or architecture. When a request is ambiguous or would introduce a new business rule, stop and request clarification before implementation.

## Repository Boundaries

- Work only inside this repository unless explicitly instructed otherwise.
- Do not modify files outside the repository.
- Do not expose, copy, print, or commit secrets, credentials, environment files, private keys, access tokens, or production data.
- Never commit `.env` files or generated credentials.
- Treat Git and the repository history as the source of truth.

## Architecture and Scope Rules

- Preserve the existing monorepo structure:
  - `backend/`: Laravel API backend.
  - `frontend/`: Next.js frontend.
  - `docs/`: architecture, workflow, domain, and module documentation.
- Reuse established patterns, shared components, services, DTOs, policies, requests, and validation utilities.
- Keep changes focused, simple, maintainable, and proportional to the approved task.
- Do not introduce speculative abstractions or unrelated cleanup.
- Do not change architecture, database schema, migrations, domain invariants, lifecycle rules, APIs, or public contracts unless explicitly approved.
- Do not rename existing concepts, fields, routes, classes, variables, or statuses without explicit approval.
- Avoid changing behavior outside the requested scope.

## Required Workflow

For each task:

1. Read the relevant code and documentation.
2. Inspect the current Git branch and working tree.
3. Summarize the current behavior and propose a focused implementation plan.
4. Identify any architectural, database, domain, API, security, or production-risk implications.
5. Implement only the approved scope.
6. Run the relevant tests, lint, type checks, and builds.
7. Review the final diff for unintended changes.
8. Report:
   - what changed;
   - files changed;
   - commands executed;
   - test/build results;
   - known risks or follow-up items.

## Git Safety

- Start from the latest `origin/main` unless instructed otherwise.
- Use a focused branch for each approved task.
- Do not work directly on `main` unless explicitly instructed.
- Do not discard, overwrite, stash, reset, clean, or delete existing work without explicit approval.
- Do not delete untracked files merely because they appear unrelated.
- Do not run destructive Git commands such as `git reset --hard`, `git clean -fd`, or forced pushes without explicit approval.
- Do not commit, push, create a pull request, merge, deploy, or modify production unless explicitly requested.
- Prefer Draft Pull Requests for work awaiting Product Owner validation.
- Use merge commits when the Product Owner explicitly approves that merge method.

## Testing Expectations

Run only the commands relevant to the changed area, then expand coverage when risk warrants it.

### Backend

From `backend/`:

```bash
php artisan test
composer test
```

Use focused PHPUnit/Laravel test filters during development when appropriate, but run the broader relevant suite before declaring completion.

### Frontend

From `frontend/`:

```bash
npm run lint
npm run build
```

There is currently no dedicated frontend test script unless one is added later.

## Production and Server Safety

The production/server checkout may exist at:

```text
/home/sewaellf/ufq-pilot
```

When operating on a server:

- Confirm `pwd`, hostname, branch, `git status`, and latest commit before making changes.
- Treat any untracked or modified file as potentially intentional until reviewed.
- Do not run migrations, seeders, destructive database commands, package upgrades, builds, restarts, deployment commands, or `git pull` without explicit approval.
- Do not edit production `main` directly.
- Prefer repository changes through a branch and Pull Request, followed by an approved deployment.
- Shared-hosting sandbox limitations are environmental constraints, not permission to bypass safety checks.

## Communication Rules

- Explain findings and implementation summaries in Arabic unless asked otherwise.
- Be explicit about uncertainty, failed commands, incomplete tests, and environmental limitations.
- Never claim a test, build, push, merge, or deployment succeeded unless its result was actually observed.
- Ask for clarification when multiple interpretations could materially affect behavior.

## Source Priority

When instructions conflict, use this priority order:

1. Explicit current instruction from the Product Owner.
2. Approved/frozen documentation in `docs/`.
3. Existing tests and enforced database constraints.
4. Existing implementation patterns.
5. This file.

Do not silently resolve contradictions that affect business behavior; surface them for decision.
