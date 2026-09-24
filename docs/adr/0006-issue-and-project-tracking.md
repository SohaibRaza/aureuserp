# ADR 0006: Issue labels and Projects for ERP work

- **Status:** Accepted
- **Date:** 2026-09-23
- **Decision owners:** AureusERP maintainers

## Context

The fork will continue to receive work across Logistics, existing ERP modules, and new plugins. Repository labels apply across every issue and pull request in the repository. Creating a separate label for every module and every implementation phase would make the shared label list grow with each initiative and would mix repository-wide classification with one project's delivery plan.

The Logistics implementation already has a dedicated GitHub Project and phase labels. The fork also has an Aureus ERP portfolio Project. The repository needs a tracking scheme that can expand to more modules without creating duplicate project plans or treating a module's phase names as repository-wide categories.

## Decision

1. Use repository labels for stable categories shared across work: module or area (for example, `logistics`, `inventory`, or `accounts`) and issue type where a type adds useful information (for example, `type:decision` or `type:tracker`). Do not add an implementation-task label when the area label and issue itself already identify ordinary implementation work.
2. Do not create new `module:phase:*` labels. Phase is delivery metadata for an initiative, not a reusable repository-wide category.
3. Track the current phase in a single-select `Phase` field in the relevant module Project. The Logistics Project owns P0-P8 phase values for Logistics work. A later module may define its own values in that module's Project without expanding the repository's label list.
4. Use GitHub milestones only for named releases or deliverables where GitHub's issue completion percentage and optional due date are useful. Do not use a milestone as a second copy of the Project phase field.
5. Keep one dedicated Project for each substantial, independently managed module or initiative. The Aureus ERP portfolio Project tracks initiative-level entry points and cross-module work; it must not copy every child task from module Projects. Link the relevant module tracker issue to the portfolio instead of mirroring its full backlog.
6. Keep project status in the Project's Status field. Do not encode Todo, In Progress, Blocked, In Review, or Done as issue labels.
7. When a module becomes active, add its area label and a Project auto-add rule filtered to that label. When an initiative ends, retain its issue history; archive obsolete labels only after issue labeling and automation rules have been migrated and checked.

## Current Logistics migration

The Logistics Project is the source for Logistics phase assignments. Migrate existing `logistics:phase:P0` through `logistics:phase:P8` assignments into its `Phase` field before retiring those labels. Keep the `logistics` area label on every Logistics issue, keep `type:decision` on decision issues, and keep `type:tracker` on the master tracker. Update the Logistics Project auto-add rule and saved views before removing or archiving labels. Verify that all 54 Logistics issues remain in the project and that the decision-gates view still contains all 11 decision issues after migration.

## Consequences

- The repository label set stays bounded as modules and initiatives grow.
- Phase values are easy to group and filter inside their owning Projects.
- Cross-module portfolio reporting uses tracker issues or explicitly cross-module issues, not duplicated child backlogs.
- A phase value is not automatically visible on the issue outside its Project. Keep the issue title and body focused on its task and link to its owning Project and plan when phase context matters.
- Issue status and phase must each have one maintained value. Do not add a second label or field that tracks the same information.

## References

- [Logistics documentation guide](../logistics/README.md)
- [Consolidated Logistics plan](../logistics/plan/README.md)
- [AureusERP repository agent instructions](../../AGENTS.md)
- [GitHub Projects documentation](https://docs.github.com/en/issues/planning-and-tracking-with-projects/learning-about-projects/about-projects)
- [GitHub labels documentation](https://docs.github.com/en/issues/using-labels-and-milestones-to-track-work/managing-labels)
- [GitHub milestones documentation](https://docs.github.com/en/issues/using-labels-and-milestones-to-track-work/about-milestones)
