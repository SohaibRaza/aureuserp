# Logistics Documentation Guide

This directory contains the target design for the Logistics module and the dated repository evidence used to plan its implementation. These documents do not all have equal authority.

The fork's branch and release workflow is documented in [`docs/BRANCHING-AND-RELEASES.md`](../BRANCHING-AND-RELEASES.md). The `production` branch contains implementation work, but deployment still requires the acceptance gates in the consolidated plan.

## Which documents control implementation

For issue labels, phase tracking, and the relationship between the Logistics Project and the ERP portfolio, follow [ADR 0006: Issue labels and Projects for ERP work](../adr/0006-issue-and-project-tracking.md). Read the decision before adding or changing Logistics labels, project fields, auto-add rules, or portfolio links.

Use the following order when documents disagree:

1. Confirmed business requirements and observed operating practice.
2. Approved domain and architecture decisions.
3. The consolidated plan under [`plan/`](plan/README.md).
4. Approved integration contracts and implementation specifications.
5. Existing AureusERP code and database structures.

The current codebase shows what exists today. It does not define correct logistics behavior. If current code conflicts with an approved requirement, the implementation plan must identify the affected component and classify it for reuse, extension, refactoring, migration, replacement, deprecation, or removal.

Current manual practice is evidence of business needs, constraints and exceptions; it is not automatically a requirement to preserve the same workflow. Evaluate spreadsheet steps and workarounds critically. The target may simplify, automate or replace them while preserving validated business outcomes and necessary controls. The same principle applies to existing AureusERP behavior.

## Document classes

| Documents | Purpose | How to use them |
| --- | --- | --- |
| [`plan/`](plan/README.md) | Canonical target requirements, domain model, workflows, architecture, contracts, migration, implementation sequence, testing, rollout, risks, and decisions. | Start here for product, architecture, engineering, QA, and AI-agent work. |
| [`FACTORY-STOCK-PLANNING-WORKBOOK-REVIEW.md`](FACTORY-STOCK-PLANNING-WORKBOOK-REVIEW.md) | Owner-supplied factory order, stock and projected-production evidence reviewed on 2026-09-24. | Separates confirmed practice, formula defects and unresolved operating rules. Confirmed requirements are incorporated in the plan; the workbook is not proof of completed transport or payment. |
| [`REPOSITORY-AUDIT.md`](REPOSITORY-AUDIT.md), [`REUSE-MAP.md`](REUSE-MAP.md) | Dated observations about the repository and reusable components. | Recheck against current source before implementation. These files cannot override the target plan. |
| [`INTEGRATION-CONTRACTS.md`](INTEGRATION-CONTRACTS.md) | Historical Phase 1 contract proposal plus implementation-state notes. | Use for provenance. Implement the consolidated contracts in [`plan/05-INTEGRATION-CONTRACTS.md`](plan/05-INTEGRATION-CONTRACTS.md). |
| [`PHASE-2-BACKLOG.md`](PHASE-2-BACKLOG.md) | Historical work list that led to the current foundation. | Use for implementation history. Use [`plan/08-IMPLEMENTATION-ROADMAP.md`](plan/08-IMPLEMENTATION-ROADMAP.md) for current sequencing. |
| [`PHASE-2A-IMPLEMENTATION-REPORT.md`](PHASE-2A-IMPLEMENTATION-REPORT.md), [`PHASE-2B-IMPLEMENTATION-REPORT.md`](PHASE-2B-IMPLEMENTATION-REPORT.md), [`TEST-BASELINE.md`](TEST-BASELINE.md) | Dated implementation and test evidence. | Treat only the explicitly executed behavior as evidence. These reports do not approve business policy or production readiness. |

## Reading and change rules

- Update the owning canonical plan document when a requirement, decision, workflow, contract, or implementation phase changes.
- Preserve implementation reports as dated evidence. Add a newer report or clearly marked addendum instead of rewriting past results as if they were current.
- Distinguish `implemented`, `source inspected`, `test executed`, `operationally verified`, and `approved for production`.
- Do not infer business rules from fixtures, current UI behavior, mutable master data, or a convenient code path.
- Keep unresolved policy in [`plan/12-OPEN-DECISIONS.md`](plan/12-OPEN-DECISIONS.md) with an owner, required evidence, and the phases it blocks.
- Recheck repository paths, versions, and line references before using dated audit material in a code change.

PostgreSQL is the project database baseline under [ADR 0007](../adr/0007-postgresql-project-baseline.md). The canonical plan uses PostgreSQL acceptance; older exploratory results on other engines remain historical evidence only.

## Current P0 evidence

- [Technical baseline and integrity follow-up](P0-EXECUTION-REPORT-2026-09-24.md).
- [Stock and payment writer/lock audit](STOCK-AND-PAYMENT-WRITER-AUDIT.md).
- [Four-mode tabletop review and missing operational evidence](P0-TRANSPORT-MODE-TABLETOP-2026-09-24.md).

## Current status

The non-posting Logistics foundation and selected Sales and Inventory integrity repairs are committed on the fork's `production` branch and described by the Phase 2 reports. The complete operational Logistics module is not implemented or production approved. The first live pilot must include dedicated vehicles, multi-stop delivery, carrier consignments, and distributor pickup after every applicable decision and acceptance gate is satisfied.

Current engineering priority is dedicated vehicles and multi-stop delivery, followed by carrier consignments and distributor pickup. This does not change the settled all-four-mode first live pilot requirement without an explicit owner decision.
