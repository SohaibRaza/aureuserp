---
title: Consolidated Scope and Business Requirements
version: 1.1.0
date: 2026-09-20
---

# Consolidated Scope and Business Requirements

## Problem frame

Outbound logistics currently spans commercial commitments, mutable packaging definitions, warehouse execution, informal transport arrangements, multiple custody handovers, basic-phone reporting, disputed receipts, and freight payments. Native ERP records alone do not prove what was planned, loaded, issued, physically handed over, received, disputed, corrected, or paid. The target product must connect those facts without replacing the owning Sales, Inventory, Accounts, Product, Partner, or Support domains.

## Actors and responsibilities

| Actor | Responsibility |
| --- | --- |
| Sales release owner | Commercial eligibility, commitment, substitution and source amendment. |
| Product and UoM steward | Product identity, approved units, packaging definitions and effective versions. |
| Logistics coordinator | Planning, booking, provider communication, follow-up and exception assignment. |
| Warehouse loader | Actual goods, packaging, lot, condition and quantity entry. |
| Dispatch verifier | Independent confirmation of the exact frozen load revision. |
| Release approver | Approval of the exact load, vehicle, holds and departure scope. |
| Gate operator | Physical person and vehicle checks plus actual exit and entry recording. |
| Provider representative, driver and collector | Distinct external reporting and custody roles. One person may fill several roles only when recorded explicitly. |
| Distributor receiver | Reports presented, accepted, refused and damaged goods. |
| Receipt reconciler | Authorizes effective receipt allocations from the available evidence. |
| Accounts preparer | Matches freight agreements, charges, bills, advances and payment evidence. |
| Accounts approver and payment executor | Approves or executes financial effects within assigned limits. |
| Quality and returns owner | Quarantine, inspection, quality release, loss and return disposition. |
| System administrator | Company controls, permission assignment, configuration and approved recovery execution. Cannot approve or independently audit the same privileged change. |
| Auditor/control reviewer | Read-only history, configuration, recovery, release, payment and evidence-lifecycle review; independent of the activity reviewed. |

A person may hold several roles only where Q2 permits it. The role names remain separate so permissions, approvals and audit evidence do not become ambiguous when one person performs more than one task.

## Consolidated requirements

### Authority, scope, and audit

- R1. Business reality and approved domain rules govern; existing code and old data structures may be refactored when they conflict.
- R2. Sales, Inventory, Accounts, Products, Partners, and Support own their authoritative records; Logistics coordinates and records transport facts without parallel ledgers.
- R3. Every command, record, approval, document, and lookup is scoped to the exact Company; cross-company trips are excluded from the first release.
- R4. Consequential commands require current actor permission, expected revision, significant intent identity, approval scope, and authoritative invariant checks.
- R5. Same-intent replay returns one canonical result only after current access is rechecked; changed payload under the same key conflicts.
- R6. Material changes invalidate only affected approvals and release authorities; historical approvals remain auditable but cannot authorize current action.
- R7. Suspension blocks new commitments at a serialized cutoff while preserving authorized receipt, correction, settlement, and recovery of existing obligations.
- R8. Evidence records occurrence time, recording time, reporter, recorder, provenance, Company, hash, size, access, retention, legal holds, lifecycle state, correction links and any approved-disposal tombstone.

### Demand, quantities, and packing

- R9. Logistics consumes only demand declared eligible by the owning Sales/Inventory contract; tentative planning is distinct from an active claim and native reservation.
- R10. Concurrent claims, source edits, cancellation, partial dispatch, and native backorders cannot overconsume or double-subtract demand.
- R11. Commercial quantity, stock quantity, UoM, rounding, packing commitment, and actual loaded contents remain distinct and traceable.
- R12. Quantity/conversion meaning is snapshotted at the earliest approved commitment and again when actual loading differs; later master-data changes do not rewrite history.
- R13. Unknown, mixed, fractional, open, nested, or repacked contents cannot be inferred. Unsupported conversions block only the affected action and create owned work.
- R14. Product, lot/serial requirement, package, owner, quality status, storage identity, and outer dimensions/weight retain their distinct meanings.
- R15. Capacity evaluates actual equipment and each relevant segment independently for weight, volume, handling, lot and quality restrictions, and known prohibitions. A missing measurement is unknown; the system must not replace it with zero or use it as evidence that the load fits.

### Transport planning and execution

- R16. The first live pilot must support dedicated vehicles, multi-stop delivery, carrier consignments with applicable feeder, terminal and final-mile handovers, and distributor pickup. Each mode requires an approved responsibility matrix and acceptance evidence before the pilot starts.
- R17. Distinguish contracted provider, performing provider, vehicle owner, driver, broker/agent, authorized recipient, payer, and final cost bearer even when one person fills several roles.
- R18. Preserve booking revisions, acceptance evidence, planned and actual assignments, service endpoints, issuer-scoped consignment references, terms, extras, and cancellation consequences.
- R19. Actual loading is separate from planned allocation; substitutions, short loads, rejected cargo, and unselected native lines remain explicit.
- R20. One controlled departure releases only its verified frozen native cargo set. An intentionally reduced dispatch requires a new verified load and release revision; it may depart only when every Inventory operation in that revised set succeeds. A partially applied execution, failure, stale result or unknown result creates no gate authority.
- R21. Stock issue, custody handover, gate release, physical departure, carrier terminal arrival, distributor receipt, and operational closure are separate facts.
- R22. Unauthorized or unverified physical events remain recordable as incidents without fabricating or retroactively granting approval and without falsifying native results.

### Custody, receiving, returns, and correction

- R23. Current cargo portions never overlap. A split retires its parent; transfers conserve content and preserve custody history without another stock issue.
- R24. Receiving input declares incremental, cumulative, correction, or unclear intent. Physical-event observations and cumulative attestations have separate identities and coverage.
- R25. Multiple reports of one receipt reconcile to one event; equal quantities alone do not prove duplication. A cumulative attestation may cover several events without inventing arrival facts.
- R26. Verified allocations cannot exceed identified dispatched portions. Unknown source attribution is retained as unresolved work rather than guessed FIFO/proportional fulfillment.
- R27. Acceptance, condition, custody, quality disposition, commercial fulfillment, return, loss, and recovered goods remain independent facts.
- R28. Corrections are bounded by original facts and dependent effects. Accepted-then-returned goods retain both histories; loss recovery reverses only the recoverable original disposition.
- R29. Issued cargo that never departed is unloaded, recounted, and corrected through an approved native Inventory path; it is never represented as a fictional customer return.
- R30. Returned, damaged, wrong-recipient, or recovered goods do not become saleable until the owning Inventory/quality decision permits it.

### Freight, money, and settlement

- R31. Logistics records approved freight agreements, components, extras, claims, holds, allocations, and operational responsibility; Accounts owns liabilities, advances, bills, payments, reconciliation, reversals, and book residuals.
- R32. Supplier, payment recipient, bank destination, actual payer, cost bearer, and beneficiary are independently authorized and preserved.
- R33. A real advance may precede a final bill and is later allocated or recovered through an approved native accounting contract. Final bills are not fabricated to justify advances.
- R34. Payment instruction, execution uncertainty, confirmed posting, settlement allocation, hold, claim, offset, and recoverable advance are separate states.
- R35. Pending or unknown payout protects against duplicate payment without falsely reducing the accounting balance.
- R36. Freight allocation conserves charge totals after currency rounding. Missing or disputed allocation basis remains explicit and owned, never zeroed or arbitrarily equalized.

### Operability, rollout, and extensibility

- R37. Basic-phone participants can use attributed staff assistance and independent verification; a driver app, OTP, portal, or personal device is not mandatory.
- R38. Workspaces, printed documents, exports, and queues expose owner, next action, revisions, quantities, custody, evidence, money, and independent closure states appropriate to the role.
- R39. Opening adoption, outages, restore, and retries reconcile physical, native, financial, and optional external outcomes before replay.
- R40. Every supported mode passes applicable security, goods, receiving, money, evidence, recovery, usability, workload, and performance gates before the first live pilot.

## Included scope

- All R16 modes and applicable feeder/terminal/final-mile custody.
- Source allocation, partial loading, native issue, release, gate and physical event separation.
- Incremental/cumulative/unclear receipts, shortage, overage, damage, refusal, wrong recipient, loss, recovery, returns, and onsite abort.
- Negotiated freight, actual advances, aggregate bills, separate recipients/payers, holds, disputes, and allocations to native accounting.
- Phone-assisted reporting, printed documents, protected evidence, command replay protection, suspension, recovery, cutover, and operational queues.

## Deferred capabilities

- Automatic rate selection, zone precedence, route optimization, advanced dock appointments, calibrated load suggestions, external receiving adapters, provider/receiver portals, and optional communication channels.
- General mixed-SKU or arbitrary-depth package authoring beyond approved pilot cases.
- Multi-company shared physical trips and owned-fleet maintenance.

Deferred work must preserve these contracts when implemented; it is not silently authorized by configuration placeholders.

## Outside product identity

- Replacement Sales, Inventory, Accounts, Product, Partner, quality, or Support systems.
- A parallel stock, payable, receivable, bank, or cash ledger.
- Mandatory driver application, per-pouch serialization, universal event sourcing, generic workflow engine, insurance administration, or complete carrier-network simulation.
- Product cash collection; if the same driver handles it, freight and product money remain separate authorities and records.

## Success measures

- Every dispatched quantity traces to eligible demand, actual load, native issue, current cargo, and an explainable outcome or owned exception.
- Every freight amount traces from approved agreement to native accounting result, allocation, residual, and reversal/claim state where applicable.
- No stale approval, duplicate command, cross-company identifier, unsupported conversion, or unknown native outcome can create release or payment authority.
- Staff and backup staff complete representative all-mode work with approved workload, response, and recovery targets.
- By transport mode, the pilot reports baseline-relative original/revised commitment adherence, departure and delivery-window adherence, receipt-confirmation lag, and exception-resolution age against Q9-approved thresholds.
- Restore and opening adoption do not duplicate goods, receipts, payments, or evidence.
