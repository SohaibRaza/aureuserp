# Fork branching and release workflow

This repository has two Git remotes:

| Remote | Repository | Purpose |
| --- | --- | --- |
| `upstream` | `aureuserp/aureuserp` | Original AureusERP project. Fetch its changes; do not push Shafique-specific work here. |
| `origin` | `SohaibRaza/aureuserp` | Maintained fork. Its `master` branch mirrors upstream, while `production` contains the fork's custom work. |

## Current project lifecycle (2026-10-05)

The owner confirmed that the project is entirely in development. No deployment is configured and no production environment exists. The `production` branch is the custom development code line. A push to this branch is not a deployment.

Use isolated PostgreSQL and MySQL 8.0 databases to verify affected shared migrations, transaction integrity and concurrency during development, as required by ADR 0008. PostgreSQL remains the primary development and acceptance database. Future deployment-target checks, migration rollout and live-pilot acceptance apply when those environments and operations are established; their absence does not block development or code review. Before applying a migration to existing development data, inspect that actual database and preserve its records. Keep business-policy approvals and technical dependencies attached to the behavior they govern.

## Branch responsibilities

- `origin/master` is the clean integration point for upstream AureusERP. Keep it free of Shafique-specific commits. Update it from `upstream/master` only after checking the upstream changes and the fork's current state.
- `origin/production` is the maintained custom branch. Logistics and other approved fork changes belong here. The branch name identifies the intended code line; it does **not** mean every commit is approved for a live deployment.
- Create a short-lived feature or fix branch from `production` for each bounded change. Review and test it before merging it back into `production`.
- Deploy an identified commit or release tag after the relevant application and business acceptance gates pass. Do not deploy a moving branch name without recording its commit SHA.

The upstream relationship is one-way: bring reviewed upstream changes into the fork, then merge them into `production` and resolve conflicts there. Do not merge `production` into `master`.

## Routine workflow

1. Fetch both remotes and check the branch graph and working tree: `git fetch upstream`, `git fetch origin`, `git status`, and `git log --graph --oneline --decorate --all`.
2. If `origin/master` has no fork-only commits, advance it to the reviewed `upstream/master` commit using a fast-forward. A non-fast-forward update means the mirror assumption has failed; investigate before changing the branch.
3. Start implementation branches from the latest `origin/production`. Keep each change scoped to an issue, and update the relevant Logistics plan or implementation evidence when behavior changes.
4. Merge the refreshed `origin/master` into a branch based on `production`, resolve conflicts by preserving both approved upstream behavior and fork requirements, and run the affected tests. Merge that reviewed result into `production`.
5. Before release, record the exact commit, migration plan, test results, unresolved risks, business approvals, and rollback or forward-recovery procedure. Tag the accepted commit with a versioned release identifier.

Protect both remote branches. Require reviewed changes and appropriate checks on `production`; restrict direct pushes and force pushes. Preserve any local uncommitted work before switching branches or integrating upstream.

## Upstream integration (2026-10-21)

The custom `production` branch integrates upstream `master` at `36744b5972f2b0cfdf602bea5418bbb57acdc844`, retaining the fork changes through `4fecc564b`. This brings in 285 upstream commits, including Point of Sale, API security and company validation fixes, barcode Manufacturing workflows, and Inventory corrections.

Conflict resolution preserves the fork's Logistics registration, stock mutation locks, transaction boundaries, and transparent routing before stock settlement. It also retains upstream's movement date and scrap mass-assignment fixes, installer failure handling and forced plugin migrations, and sidebar visibility checks. No application database migrations or Point of Sale installation are performed by this Git merge. Dependency installation and a frontend build are required after checking out this update.

Only `production` is updated by this integration. Neither local `master` nor the fork's remote `master` is moved. The older branch-state observations below are historical and must not be used as current commit references.

## Historical branch state (2026-09-23)

- `origin/production` contains commit `c796209d2`, which includes the consolidated Logistics plan, the non-posting plugin foundation, selected Sales and Inventory integrity work, and associated tests. The plan and code are not a complete or approved live Logistics module.
- `origin/master` and the fetched `upstream/master` pointed to `4033c7055` when this workflow was recorded.
- Local `master` points to merge commit `8caaf3f34` and is 11 commits ahead of `origin/master`. Those local commits are ancestors of `production`; local `master` is therefore **not yet a clean upstream mirror**. Aligning it requires a separate, deliberate branch move after verifying that no unique work would be lost. Do not assume `git pull` will make it clean.

The branch graph and remote state can change. Recheck them before following the routine workflow. The Logistics implementation order and release gates are in [the consolidated plan](logistics/plan/README.md), and the tracked work is in [the fork's master implementation issue](https://github.com/SohaibRaza/aureuserp/issues/54).
