---
title: AureusERP Gap and Refactor Map
version: 1.1.0
date: 2026-09-20
---

# AureusERP Gap and Refactor Map

## Purpose

This is a dated implementation baseline, not the target specification. It was inspected on 2026-09-19 at Git commit `3807c2d498b0c3e609f6884d764b0518e38e30a8` with local-only changes present. Each implementation unit must record a fresh commit and local-diff identity and re-verify every cited component before editing it. “Reuse” means the behavior is directionally sound; it does not waive the cited qualification.

| ID | Current component | Problem or limitation | Required target change | Disposition |
| --- | --- | --- | --- | --- |
| G1 | `plugins/shafique/logistics` registration/settings | Useful nonposting foundation; operational domain is largely absent. | Extend the existing plugin; preserve exact-company controls/history and stricter dependency removal. | Reuse and extend. |
| G2 | `plugins/shafique/logistics/src/Services/OperationalControl.php` | Control change locks Company/control, but new-activity authorization is a read and does not define an eligibility cutoff. | Serialize new commitment checks with control changes; persist approved cutoff; permit only scoped existing obligations during suspension. | Refactor. |
| G3 | `plugins/shafique/logistics/src/Listeners/PreventRemoval.php` | Strong dependency protection exists but needs qualification against history/evidence obligations. | Preserve fail-closed inconsistent-metadata behavior and prove CLI/UI/plugin-manager coverage. | Reuse and qualify. |
| G4 | `plugins/webkul/sales/src/Services/OrderWorkflow.php` | Confirmation has a useful transaction/root lock, but no durable request identity and no immutable quantity/packing commitment snapshot. | Preserve atomic confirmation; add owner snapshot/revision contract and bounded idempotency only where consequential. | Extend owner. |
| G5 | `plugins/webkul/inventories/src/Services/TransferWorkflow.php` | Complete/cancel do not provide the required shared transaction/root-lock boundary. | Add failure-atomic owner workflows, state checks under lock, terminal replay and caller transaction participation. | Refactor owner. |
| G6 | Inventory direct callers (`plugins/webkul/inventories/src/InventoryManager.php`, move services, API, Barcode, Manufacturing) | Multiple paths can bypass a Logistics-only wrapper or shared invariant. | Enumerate all writers; converge on owner services and narrow enrolled precondition hook; preserve unenrolled behavior. | Refactor boundary. |
| G7 | `plugins/webkul/inventories/src/Models/ProductQuantity.php` and reservation flow | Existing-row locking does not solve missing-bucket creation; nullable identity and duplicates are unsettled. | Approve canonical identity, preflight duplicates, implement database-backed uniqueness/serialization on both engines. | Refactor owner/schema. |
| G8 | Inventory return flow | Positive explicit quantities exist, but completed-source lock, cumulative limits, successful replay and API quantity contract are incomplete. | Enforce source state/aggregate under lock, explicit API map, replay and cancellation semantics. | Complete owner contract. |
| G9 | Inventory cancellation/return semantics | Completed moves cannot simply cancel; native return origin can imply customer custody. | Add approved issued-but-onsite correction preserving issue history, recount and quality state. | New owner capability; pilot blocker. |
| G10 | `plugins/webkul/support/src/Models/UOM.php` and `plugins/webkul/products/src/Models/Packaging.php` | Mutable/float-oriented current master meaning can rewrite history. | Snapshot exact approved commitment and actual loading meaning; validate at native precision; avoid ERP-wide numeric rewrite. | Extend Sales/Logistics. |
| G11 | `plugins/webkul/accounts/src/Services/PaymentRegistrar.php` and callers | Posting/reconciliation/listener atomicity, effect idempotency, residual lock and unknown outcome are not proven. | Create owner-local protected financial execution contract with canonical result/uncertainty. | Refactor owner. |
| G12 | Accounts payment model | Supplier and payment recipient semantics are coupled/insufficient for approved third-party recipient; pre-bill advance path is unproven. | Preserve supplier liability; separately authorize recipient/bank; support genuine advance then allocation/refund/recovery. | Extend owner/schema; pilot blocker. |
| G13 | Existing reconciliation tests and fixtures | Some recorded fixtures and expectations use the same debit or credit direction on both sides of a reconciliation. That cannot prove that accounting reconciliation is correct. | Correct the fixture to represent the intended opposing accounting entries, then reproduce the behavior. Change production code only if the corrected fixture exposes a real accounting defect. | Repair tests before freight integration. |
| G14 | Chatter message/notification behavior | Application termination is not durable after-commit work; general attachment deletion can remove bytes. | Use after-commit for optional notifications; use protected Logistics evidence storage and durable outbox only for required effects. | Separate ownership. |
| G15 | Native UI/API policies | Entry-point validation may differ and direct services remain callable. | Apply policy plus service-level exact-company/permission/authority checks across UI/API/job/direct entry points. | Harden shared services. |
| G16 | Current schema/test evidence | PostgreSQL selected-suite history exists; MySQL, full suite, browser, load, restore and Accounts cases are incomplete. | Establish fresh isolated evidence per `09-TESTING-AND-ACCEPTANCE.md`; do not promote historic counts to release proof. | Requalify. |
| G17 | Existing documentation and contracts | Some historical reports describe behavior later withdrawn by current source or evidence. | Change canonical contracts through approved business requirements and decisions. Keep implementation reports as dated evidence. Reverify current source as the starting state, but never allow it to override the target plan. | Reconcile documentation. |
| G18 | No complete Logistics operational UI | Operators would otherwise navigate conceptual records and native modules separately. | Build four role-oriented workspaces and revisioned documents after service contracts stabilize. | New Logistics capability. |

## Required refactor rules

- Put a rule in the module that owns the invariant even when Logistics is the first consumer.
- Preserve public/native behavior for unenrolled work unless the existing behavior itself violates an approved owner invariant.
- Do not add a Logistics dependency to Inventory, Accounts or Sales. Use synchronous contracts/events whose failure semantics are explicit.
- Characterize current behavior and all direct callers before modifying a shared service.
- Any refactor affecting stock or money requires persisted-state, outer-rollback, critical-listener, concurrency and supported-engine proof.
- Do not mask accounting or inventory defects with Logistics compensating rows.

## Reuse decisions

Sound foundations worth retaining include modular plugin registration, native Eloquent/service patterns, exact-company controls, plugin dependency removal protection, Sales confirmation locking, explicit positive return quantities, native stock/accounting ownership, policies/Form Requests, Filament/Livewire conventions, and the isolated test bootstrap. Each still requires current verification in its cited unit.
