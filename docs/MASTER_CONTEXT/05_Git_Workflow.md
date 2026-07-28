# NexusOS Pilot
# Git Workflow
Version: 1.0
Status: Frozen
---
# Purpose
This document defines the official Git workflow used throughout the NexusOS Pilot project.
Following a consistent workflow improves traceability, simplifies code review, and minimizes deployment risk.
---
# Repository
Official Repository
Atif7rbi/ufq-pilot
Default Branch
main
The main branch always represents the latest approved production-ready code.
---
# Branch Strategy
Development should never begin directly on the main branch.
Every implementation must start from a dedicated feature branch.
Typical branch naming:
feature/<feature-name>
Examples:
feature/projects
feature/customers
feature/dashboard
feature/units
---
# Development Workflow
Every implementation follows the same sequence.
1. Update local main.
2. Create a feature branch.
3. Implement the approved scope.
4. Validate locally.
5. Commit logically grouped changes.
6. Push the feature branch.
7. Review the implementation.
8. Merge after approval.
9. Verify production deployment.
No step should be skipped.
---
# Branch Creation
Feature branches should always originate from the latest version of main.
Only one logical feature should exist within a single feature branch.
Large unrelated changes should be divided into separate branches.
---
# Commit Policy
Commits should represent meaningful engineering progress.
A commit should:
- Solve one logical problem.
- Be independently understandable.
- Leave the repository in a working state.
Avoid combining unrelated modifications into a single commit.
---
# Commit Messages
Commit messages should clearly describe the completed work.
Preferred style:
type: short description
Examples:
feat: implement customer archive workflow
fix: resolve dashboard statistics bug
refactor: simplify project service validation
docs: update architecture documentation
test: add units feature tests
---
# Local Validation
Before pushing changes:
- Run relevant automated tests.
- Verify application behavior.
- Review modified files.
- Ensure the project builds successfully.
Broken code should never be committed intentionally.
---
# Push Policy
Only validated commits should be pushed.
Frequent small pushes are preferred over large batches of unrelated changes.
---
# Code Review
Every implementation should be reviewed before merging.
The review verifies:
- Requirement satisfaction.
- Architectural consistency.
- Code quality.
- Readability.
- Test coverage.
- Maintainability.
---
# Merge Policy
A feature branch may be merged only after:
- Implementation completed.
- Tests passed.
- Functional review approved.
- Documentation updated when required.
The merge represents acceptance of the feature.
---
# Main Branch Protection
The main branch should always remain stable.
It should never contain:
- Experimental work.
- Incomplete features.
- Broken tests.
- Unapproved architecture.
---
# Hotfix Workflow
Production fixes should be isolated.
Typical flow:
1. Create hotfix branch.
2. Implement minimal correction.
3. Validate.
4. Review.
5. Merge.
6. Deploy.
7. Document if necessary.
The objective is restoring production stability with minimal risk.
---
# Documentation Changes
Documentation follows the same workflow as source code.
Documentation updates should be committed together with the implementation they describe whenever practical.
---
# Rollback Philosophy
Each commit should make rollback straightforward.
Small logical commits reduce deployment risk and simplify recovery when necessary.
---
# Guiding Principle
Git history is part of the project's engineering documentation.
A clean commit history improves maintainability, simplifies debugging, and provides a reliable record of the project's evolution.
---
End of Document
