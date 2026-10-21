# Logistics Documentation Guide

This directory contains the target design for the Logistics module and the dated repository evidence used to plan its implementation. These documents do not all have equal authority.

The fork's branch and release workflow is documented in [`docs/BRANCHING-AND-RELEASES.md`](../BRANCHING-AND-RELEASES.md). The project is development-only, with no deployment or production environment, as [confirmed on 2026-10-05](../BRANCHING-AND-RELEASES.md#current-project-lifecycle-2026-10-05). The `production` branch contains development work. The consolidated plan's deployment and live-pilot gates apply to those future activities and do not require creating an environment before development can continue.

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


The local implementation dated 2026-10-16 includes private evidence, Company retention policies, command replay identity, scoped approvals, Sales-backed allocation, independently verified loading, Inventory-controlled stock issue, and receipt observations. The original 38-file PostgreSQL gate passed **586 tests and 2,849 assertions**. The final integrated selection, including the new admin workflows, passed **593 tests and 3,028 assertions across 39 files** on PostgreSQL **18.1** and PHP **8.5.0**; see the [dated execution evidence](P0-EXECUTION-REPORT-2026-09-24.md#2026-10-16-admin-workflows-and-configurable-loading-controls) for current results and limitations.

The installed admin routes are:

- `/admin/logistics/trips`: plan dedicated-vehicle and multi-stop trips, select confirmed Sales demand, record physical counts and loading checks, independently verify the load, issue stock through Inventory, and record receipt reports.
- `/admin/logistics/evidence`: configure Company evidence retention, upload private loading and delivery evidence, and retrieve it with permission checks.
- `/admin/module-settings`: suspend or resume new activity and approve Company loading locations and product handling rules. This is the settings link verified in the signed-in browser.

Configure staff through Security roles and Company access. The module registers its permissions without automatically assigning staff. A loader cannot approve or verify their own load. Retention starts with a configurable recommendation of **2,557 days**. Company 1 now has approved policy revision 1 at that period, saved through the signed-in Admin account on 2026-10-16. Other Companies remain blocked from uploading until their own authorized policy is saved. Expiry does not automatically delete evidence. Approved disposal and legal-hold checks remain required.

The four pending Logistics migrations have been applied to the development database after a read-only schema and ledger preflight. No operational trips, evidence, factory mappings or product handling rules were fabricated. Following explicit owner approval, the existing Admin role now has all 24 Logistics web permissions: 21 were added and all 1,895 existing permissions were preserved. The trip, evidence and settings pages were verified in the signed-in browser. Logistics remains suspended and dispatch rules remain unconfigured. Existing development records were preserved. The Git changes remain local and uncommitted.

The journeys are engineering foundations verified with synthetic records. Stock issue does not confirm physical vehicle departure, customer acceptance or financial settlement. Production forecasts are not usable stock. Products requiring formal quality release remain blocked until an authoritative quality-release integration is available. Carrier consignment and distributor pickup are not implemented. Configured operating rules, real-document walkthroughs, corrections and exception handling, the remaining mode implementations, and the plan's acceptance gates are required before the **all-four-mode first live pilot**.

For an existing installed development instance, applying schema migrations alone does not register the new action permissions. Run only the Logistics permission-definition seeder after the selected migrations: `php artisan db:seed --class='Shafique\Logistics\LogisticsDatabaseSeeder' --no-interaction`. It creates missing permission definitions without granting them to users. An authorized administrator then assigns them through Security roles and Company access. Do not reinstall all plugins or reset application data as an upgrade shortcut.

Recommended responsibility assignments remain configurable:

| Responsibility | Required Logistics capabilities | Additional control |
| --- | --- | --- |
| Planner | View and create trips, manage stops, allocate order quantities | Access to the selected Company |
| Loader | View trips, record loads and checks, request approvals | Must not verify or approve their own load |
| Independent verifier | View trips, verify loads, approve requests, consume approvals | Read required evidence; use a different user from the loader |
| Stock issuer | View trips, issue verified stock, approve requests, consume approvals | Native Inventory delivery authorization; independent from the loader |
| Delivery reporter | View trips, record delivery reports | Read evidence when attaching it; reports do not confirm acceptance |
| Evidence custodian | List, upload and read evidence | Retention approval, holds and disposal permissions are assigned separately |
| Controls manager | Approve loading rules and, when assigned, evidence retention | Company-scoped configuration; no automatic staff assignments |

Assign backups to the same relevant role permissions. Assigning a role does not bypass independent verification, current revision, Company membership, Inventory authorization, suspension, loading deadline or evidence checks.


## Pre-issue recovery — 2026-10-21

The trip screen now provides **Remove unused quantity**, **Confirm unloading**, and **Cancel trip**. Each action requires permission, the current trip revision and a reason. Unloading also requires confirmation that all goods in the current unissued load have been physically unloaded. These recovery actions remain available during suspension; they do not resume Logistics or authorize another issue.

Cancel a counted trip by confirming unloading first, then cancelling the trip. Removing a selection or cancelling a trip keeps the Sales order and native Inventory reservations available for replanning. Inventory cancellation or reservation release is a separate authorized action. Earlier counts and recovery reasons remain in history. A replacement trip can reuse an unissued movement after the earlier load has been relinquished; its new current load is protected normally.

Issued goods cannot be restored by unloading or cancelling the trip. They still require the Inventory-owned onsite correction or return workflow appropriate to the actual event. Formal departure, verified customer acceptance, authoritative quality release, carrier consignments and distributor pickup remain outstanding. The all-four-mode first live pilot is not authorized.

The recovery schema adds immutable events and saved reservation details. Historical reservation rows may be deleted by Inventory after safe release without losing accepted load details. Existing unsnapshotted loads retain protection until reviewed recovery. The remaining-demand calculation now retains issued quantities across Sales commitment revisions; revising an order does not make previously issued goods available for selection again.

The recovery migration is applied to the configured development database. The combined PostgreSQL selection and the final focused recovery tests passed; see the [verification and migration evidence](P0-EXECUTION-REPORT-2026-09-24.md#2026-10-21-pre-issue-cancellation-and-allocation-recovery). Logistics remains suspended, and dispatch rules still require configuration.
