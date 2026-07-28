# NexusOS Pilot
# Development Environment
Version: 1.0
Status: Frozen
---
# Purpose
This document defines the official development environment used by the NexusOS Pilot project.
Every development, testing, and deployment activity should be performed using an environment compatible with these specifications.
---
# Technology Stack
The project is built using the following primary technologies:
Frontend
- Next.js
- React
- TypeScript
- Tailwind CSS
Backend
- Laravel 12
- PHP 8.2+
Database
- PostgreSQL
Version Control
- Git
- GitHub
---
# Architecture
The project follows:
- Database First Architecture
- Layered Architecture
- Domain-Oriented Design
- Feature-Based Development
---
# Repository
Official Repository
Atif7rbi/ufq-pilot
Default Branch
main
Feature development should always be performed in dedicated branches before merging into main.
---
# Local Development
Developers should maintain a consistent local environment.
Typical requirements include:
- Node.js LTS
- npm
- Composer
- PHP CLI
- Git
- PostgreSQL
- Visual Studio Code (recommended)
Equivalent tools may be used if they provide compatible behavior.
---
# Backend Environment
The backend is implemented using Laravel.
Recommended practices include:
- Composer dependency management.
- Environment configuration through .env.
- Laravel Artisan for development operations.
- PHPUnit / Pest for automated testing.
- PSR coding standards.
---
# Frontend Environment
The frontend is implemented using Next.js.
Recommended practices include:
- TypeScript for all application code.
- Functional React components.
- Tailwind CSS for styling.
- Feature-oriented directory organization.
- Reusable UI components.
---
# Database Environment
PostgreSQL is the official database engine.
The database should enforce data integrity through:
- Primary Keys
- Foreign Keys
- CHECK Constraints
- Unique Constraints
- Partial Indexes
- Transactions
Business integrity should not depend solely on application code.
---
# Source Control
Git is the official version control system.
General workflow:
- Create feature branch.
- Implement changes.
- Validate locally.
- Commit logically grouped changes.
- Push branch.
- Review.
- Merge after approval.
Direct commits to main should be avoided unless explicitly approved.
---
# Development Standards
Development should follow consistent standards:
- Clear naming.
- Small focused commits.
- Predictable directory structure.
- Reusable components.
- Minimal unnecessary abstraction.
Consistency is preferred over personal style.
---
# Testing Environment
Before approval, implementations should be verified using appropriate testing techniques.
Testing may include:
- Automated tests.
- Manual functional validation.
- UI verification.
- Production verification after deployment.
---
# Documentation
Documentation resides inside:
docs/MASTER_CONTEXT/
Project documentation is version-controlled together with the source code.
Documentation updates follow architectural approval.
---
# Production Environment
Production deployments should always use:
- Approved source code.
- Verified database migrations.
- Production configuration.
- Successful validation before release.
Production should never become the first testing environment.
---
# Environment Consistency
Every contributor should work toward maintaining consistent environments across development, testing, and production.
Reducing environmental differences minimizes deployment risk and improves reproducibility.
---
End of Document
