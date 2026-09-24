---
title: Consolidated Logistics Integration Contracts
version: 1.1.0
date: 2026-09-20
---

# Consolidated Logistics Integration Contracts

## Contract rules

All commands carry exact Company, actor, significant intent, expected revisions, idempotency identity where consequential, and a canonical result or explicit uncertainty. Adapters translate; they do not change ownership or weaken invariants.

## Mutating command specification

Before a command is implemented, its versioned contract must define the following fields and behaviors. A UI action, API route, queued job and direct service call that perform the same business action use this same command contract.

| Contract area | Required definition |
| --- | --- |
| Identity and scope | Command name/version, exact Company, business root, unguessable request key where replay matters, expected root and policy revision, significant-intent fingerprint, and fingerprint algorithm version. The contract lists included and excluded fields and uses one canonical serialization: explicit null differs from omission where business meaning differs; decimals and currencies use approved fixed scales; timestamps use UTC with declared source-zone handling; identifiers have one textual form; object keys and sets are sorted; ordered business collections preserve their approved order. Every caller sends the canonical intent fields to the owner service rather than independently inventing a fingerprint. |
| Actor and permission | Acting user or system identity, attributed external reporter when applicable, required permission, approval authority and separation-of-duty check. |
| Request | Typed fields, quantity and currency units, source identities, occurrence time, evidence references and explicit handling of optional or unknown values. |
| Transaction | Authoritative module, locked roots and order, local transaction boundary, preconditions and synchronous effects. |
| Result | Stable result identity, resulting revision/state, native effect references, accepted/rejected/unknown status and safe response fields. |
| Failure and retry | Zero-effect validation failures, conflict codes, lost-response lookup, same-intent replay, changed-intent conflict and unknown-outcome hold. |
| Correction | Cancellation eligibility, compensating owner command, reversal limits and preserved original references. |
| Audit and evidence | Occurrence/recording times, actor and reporter, policy/approval revision, before/after identifiers, evidence hash links and redaction rules. |

## Required command catalog

The catalog below is the minimum implementation surface. Each implementation unit expands its row into the specification above and links its tests. Commands may share an application service where that keeps one invariant in one owner; the catalog does not require one class or endpoint per row.

| Command and owner | Actor and permission | Request and unit meaning | Transaction/result/failure contract | Correction and proof |
| --- | --- | --- | --- | --- |
| Confirm or revise Sales commitment — Sales | Sales release owner; Sales approval policy. | Source line revision, commercial and stock quantities, units, packing and approved substitution. | Sales locks the order/lines and saves one immutable snapshot or rejects with zero effect. | New linked revision; IC1, R9-R14, U6, AE3. |
| Activate or release planning claim — Logistics with Sales/Inventory source contract | Planner with claim permission. | Source revision and exact quantity/unit. | Lock source and active claims; return saved claim/result; overclaim or stale source conflicts. | Release/remap under source lock; IC2, F1, U9, AE1-AE2. |
| Complete/cancel enrolled Inventory operation — Inventory | Authorized stock executor; Logistics release context for enrolled work. | Native operation revision and explicit move quantities in native units. | Inventory owns one transaction, locks affected roots, calls enrolled guards and returns canonical native result or explicit uncertainty. | Inventory cancellation/backorder/correction only; IC3-IC4, U3-U4, AE4/AE6/AE8. |
| Create native return or onsite issue correction — Inventory | Returns or scoped correction authority. | Completed source, explicit positive quantity/unit, destination, quality and reason. | Lock source and cumulative prior corrections; reject excess, ineligible or ambiguous requests with zero effect. | Linked native reversal/correction preserving issue history; IC5, U5, AE9/AE18. |
| Revise or cancel booking agreement — Logistics | Coordinator; freight approval when terms cross Q2/Q5 thresholds. | Provider roles, endpoints, assignments, terms, acceptance evidence and revision. | Lock booking root; append revision; stale revision conflicts. | Cancellation records fees and advance recovery separately; F3, U10. |
| Record and verify actual load — Logistics | Loader records; dispatch verifier verifies. | Shipment portions, source identities, actual quantities/units, lots, packs, measures, vehicle and evidence. | Lock load/allocation roots; material change creates a new revision and invalidates affected approval. | Unload/revise before issue; F2, U10, AE3/AE5. |
| Execute release and Inventory issue — Logistics coordinating Inventory | Release approver plus stock executor within approved separation rules. | Exact load revision, native operation set, approval and request identity. | Shared local transaction produces all native issue effects, consumes claims, saves release result and issues current gate authority; any local failure rolls back all effects. | Reconcile unknown result before retry; revoke unused authority or use onsite correction; F4/F6, U11, AE6-AE9. |
| Consume gate authority and record departure — Logistics | Gate operator. | Authority revision, vehicle/person check and occurrence time. | Lock authority/release; consume once with departure event. Stale, revoked or expired authority fails. | Actual unauthorized exit is an incident, never retrospective approval; F5, U11, AE7/AE10. |
| Record receipt observation — Logistics | Authorized staff recorder attributed to reporter. | Incremental/cumulative/correction/unclear intent, quantity/unit, occurrence uncertainty and evidence. | Append testimony and deduplicate only the request; do not change effective received quantity by itself. | Supersede/correct testimony without deleting disagreement; F10, U12, AE11-AE14. |
| Reconcile receipt and allocate outcome — Logistics | Receipt reconciler with scoped approval. | Observations/attestation scope, dispatched portions, accepted/returned/final-nondelivery/outstanding quantities and units. | Lock portions and existing allocations; conserve dispatched quantity; save unresolved attribution rather than infer it. | Bounded linked correction; F10, U12, AE11-AE14. |
| Transfer cargo custody — Logistics | Authorized custody recorder for the handover. | Current portion revision, exact quantity and unit, transferring and receiving custodians, location, occurrence time and evidence. | Lock the cohort and current portions; split when only part transfers; preserve quantity and append the handover result. | A later transfer or correction links to the original; F7, U13, AE15/AE17. |
| Record refusal or initiate physical return — Logistics coordinating Inventory and quality | Receipt or custody recorder; Inventory return and quality authorities act separately. | Current portion, refusal or prior acceptance, exact quantity and unit, actual custodian, approved destination, condition and evidence. | Record the physical fact first; Inventory owns any stock return command; refusal alone does not change custody. | Cancel only before physical action; otherwise append corrected custody and linked native return; F10-F11, U13, AE17-AE18. |
| Approve final non-delivery or loss allocation — Logistics | Scoped correction or loss approver under Q2/Q4/Q10. | Identified dispatched portion, exact quantity and unit, evidence basis, responsibility and financial dependencies. | Lock portion and prior outcomes; allocate only eligible outstanding quantity; do not fabricate an unobserved physical event. | Reversal is bounded by the eligible original allocation; F11, U13, AE16. |
| Record recovery and correct cargo disposition — Logistics coordinating Inventory and quality | Recovery recorder plus scoped correction and quality authorities. | Original allocation, recovered quantity and unit, actual custodian, location, condition, quarantine and evidence. | Lock original outcome and current portions; create a known recovered portion and linked correction without overlapping quantity. | Later disposition uses the normal quality, return or re-delivery command; F11, U13, AE16. |
| Approve or revise freight agreement — Logistics | Operations freight approver within Q2/Q5 limits. | Charge components, basis, supplier, recipient, payer, cost bearer, currency and booking revision. | Lock agreement root; append approved revision; does not post money. | Cancel/revise with fee, hold and advance-recovery treatment; F12, U16, AE19-AE22. |
| Create an advance or payment instruction — Accounts | Accounts preparer and required independent approver or executor. | Native obligation or approved advance purpose, amount, currency, recipient and bank authority, due date and request identity. | Accounts locks the obligation, residual and current instructions; creates one instruction without claiming successful payment. | Cancel only while Accounts permits; otherwise use reversal or recovery; IC8, U15, AE19-AE21. |
| Confirm or reconcile a payment outcome — Accounts | Authorized payment executor or reconciler, separated as Q2/Q5 require. | Original instruction/effect identity, authoritative provider or bank result, value date, amount, currency and evidence. | Accounts locks the instruction, effect and residual; saves confirmed success, confirmed failure or explicit uncertainty and prevents replacement while uncertain. | Later correction links to the confirmed native result; IC8, U15, AE20-AE21. |
| Reverse, refund or reallocate an Accounts effect — Accounts | Authorized reversal or recovery role with independent review where required. | Original advance, payment or allocation identity; exact amount/currency; reason; destination; expected revision. | Lock original effect and remaining reversible amount; append one native reversal, refund or reallocation result. | A further correction is bounded by the remaining eligible amount; IC8, U15-U16, AE19/AE22. |
| Accept protected evidence — Logistics | Permission for evidence class and Company. | Private staged object, detected type, size, hash, provenance and retention class. | Finalize immutable object before effective SQL association; mismatch fails dependent action. | Authorized retention/legal-hold process; IC9, U7, AE24. |
| Create, revoke or record expiry of scoped approval — Logistics | Approver with the action-specific authority; independent approval where Q2 requires it. | Approval type, exact Company and business scope, governed revision, limits, validity period, policy revision and evidence. | Lock the governed root and current approval; append one immutable lifecycle result; stale scope or self-approval conflict fails. There is no generic approval-consumption endpoint. A governed business command may consume its approval only inside that command's transaction; gate authority is consumed only with the departure event. | A new linked approval may supersede eligible authority; past authority remains auditable; R4/R6, DM7, U8, AE7/AE23. |
| Evaluate disposal eligibility and dispose eligible evidence — Logistics evidence owner | Data/privacy role and independent reviewer required by Q7; ordinary record users cannot execute it. | Evidence identity, approved retention-rule version and effective dates, legal-hold state, reason and approved destruction scope. An operator cannot manually make retention expire. | Lock evidence metadata and associations; verify elapsed retention, no legal hold and no required dependency; commit `authorized_for_disposal` then `disposal_pending`; request byte deletion with a stable operation identity; verify the deletion result and absence; then append `bytes_removed` and `disposed_per_retention_policy` with the tombstone. A crash boundary remains pending. Reconciliation checks object existence and the deletion receipt; missing bytes without a trustworthy deletion result remain an incident and cannot be finalized automatically. | Retry the same disposal identity or reconcile pending work; final disposal has no fabricated rollback and retains the minimum lawful audit proof; IC9, Q7, U7, AE24. |
| Suspend/resume Logistics activity — Logistics | Scoped administrator with independent review required by Q2. | Company, cutoff, reason, policy revision and affected capability. | Serialize control change with new commitments; saved control result is auditable. | New approved control revision; IC10, U2/U8, AE23. |
| Dry-run or apply opening adoption — Logistics | Separate import and recovery authorities. | Authorized cohort, source/native identities, physical/financial state and batch identity. | Dry-run first; apply once under mapping uniqueness; never execute historic stock or money again. | Forward reconciliation/correction only; F14, U19, AE29. |

## IC1. Sales demand and commitment

**Owner:** Sales. **Consumers:** Logistics planning/loading, Inventory fulfillment.

Sales must expose eligible source-line identity, company, product, commercial/native quantities and units, approved substitutions, revision, cancellation state, delivery party/address, restrictions and immutable commitment snapshot. Confirmed-line edits create a new revision/snapshot. Logistics may claim bounded quantities but may not redefine order eligibility or commercial completion.

Required changes may affect `plugins/webkul/sales/src/Services/OrderWorkflow.php`, `plugins/webkul/sales/src/Services/ProcurementRequester.php`, source-line models and owner-local snapshot migrations/tests.

## IC2. Demand claim and native successor lineage

**Owner:** Sales/Inventory source contract. **Consumer:** Logistics.

Claim activation, release, source reduction, partial native completion and successor/backorder remapping serialize at the source. A claim references original and successor identities without subtracting one physical dispatch twice. Uncertain native results retain protection until reconciliation.

## IC3. Inventory operation execution

**Owner:** Inventory. **Consumer:** Logistics release plus existing API/Filament/Barcode/Manufacturing callers.

Inventory must complete, cancel or partially execute an operation as one failure-atomic business command. Before any Inventory writer changes an operation enrolled in Logistics, Inventory invokes registered precondition guards inside the same database transaction. A guard may reject the command with zero effects; failure cannot be ignored or deferred. Inventory owns this extension point and does not depend on Logistics implementation classes. Logistics registers the guard and supplies the planning-claim or frozen-release context. Repeating a command after the operation reaches a terminal state returns the existing authoritative result when significant intent matches and rejects conflicting intent. Direct move writers must use the same locks, checks and result rules.

Primary surfaces: `plugins/webkul/inventories/src/Services/TransferWorkflow.php`, `plugins/webkul/inventories/src/Services/MoveCompleter.php`, `plugins/webkul/inventories/src/Services/MoveCanceller.php`, `plugins/webkul/inventories/src/Services/BackorderCreator.php`, `plugins/webkul/inventories/src/Services/MoveReserver.php`, `plugins/webkul/inventories/src/InventoryManager.php`, API and Barcode/Manufacturing callers.

## IC4. Quant and reservation identity

**Owner:** Inventory.

The approved fields that identify one Inventory balance record, including whether empty optional fields represent the same identity, are enforced consistently in application matching and an additive database uniqueness strategy. Creation of a missing balance record is serialized. A read-only duplicate preflight blocks an unsafe migration; this plan never merges historical stock rows automatically.

## IC5. Return and onsite correction

**Owner:** Inventory. **Consumers:** Logistics receiving/correction and native return APIs.

Return commands require explicit positive finite source quantities, completed eligible sources, cumulative limits, transaction/lock protection, successful replay and correct destination/quality handling. Omitted maps do not mean “return all.” Issued-but-onsite correction is a distinct owner-approved command preserving original issue history.

## IC6. Products, UoM, packing and quality

**Owners:** Products/Support/Inventory quality.

Expose product/UoM identities, conversion precision, lot/serial/quality restrictions and current packaging definitions. Historical meaning comes from snapshots. The contract rejects wrong-category UoM, unsupported factors, missing required lot, known unsafe handling and quality holds.

## IC7. Partners and operational roles

**Owner:** Partners. **Consumer:** Logistics and Accounts.

Native partner identity remains authoritative for companies and suppliers. Logistics snapshots historical operational roles and contact evidence. Supplier, driver, owner, broker, authorized receiver, payment recipient, payer and cost bearer are independent role assignments with company compatibility checks.

## IC8. Accounts advance/payment/reconciliation

**Owner:** Accounts. **Consumer:** Logistics freight allocation.

Accounts must support an approved pre-bill advance purpose, later supplier-document allocation, separate payment recipient authorization, company/currency/journal/method validation, effect-level idempotency, residual locks, reversal/refund/reallocation and unknown-outcome reconciliation. Payment posting and reconciliation are one protected business operation or expose explicit partial/unknown state that prevents duplicate authority.

Primary surfaces: `plugins/webkul/accounts/src/Services/PaymentRegistrar.php`, `plugins/webkul/accounts/src/Services/Reconciler.php`, `plugins/webkul/accounts/src/Services/MoveWorkflow.php`, payment models/requests and native UI/API callers.

**Current native implementation boundary (2026-09-24):** Invoice, bill, credit-note and refund pay APIs accept `idempotency_key` in the validated request body. It contains 1–128 ASCII letters, digits, dots, colons, underscores or hyphens, beginning with a letter or digit. The key is scoped to the exact company. Logistics callers must supply it and preserve the same significant request fields for every retry; omission remains backward-compatible for native callers but gives no cross-request replay guarantee. Responses include canonical `payment_ids`. Changed intent rejects, and replay rechecks payment-create permission and active-company access. Native APIs also retain their existing document-update permission checks. The Filament payment form supplies its own stable key.

Registration and result persistence are atomic with local posting and reconciliation. Historical registers without verified results reject replay pending review. Current native errors use HTTP 422 for instruction conflicts; the richer versioned Logistics command error contract above remains a separate integration task. Partial multi-batch allocation and batches whose currencies differ from the instruction are rejected; full same-currency batches and ordinary single-document partial payments are supported. Pre-bill advances, independently approved recipient changes, the full unknown-outcome lifecycle and cross-writer reversal controls remain required work, not implied by this registration repair.


## IC9. Evidence and Chatter

**Owner:** Logistics for protected evidence; Support/Chatter for collaboration.

Evidence storage provides private staging, validation, immutable retained objects, hash and size verification, authorized retrieval, retention, legal holds, approved disposal, restore and orphan cleanup. Validation detects content independently of extension, permits only approved formats, generates traversal-safe opaque object names, and quarantines or scans allowed document formats as approved under Q7. Retrieval prevents execution or active inline rendering and sends a safe content disposition plus `nosniff` behavior. Approved disposal preserves an immutable tombstone with identity, hash, metadata, authority, time and result. Chatter may link to evidence and carry non-authoritative discussion. Deleting a Chatter attachment or message cannot delete retained Logistics evidence.

## IC10. Plugin lifecycle and controls

**Owner:** Logistics and Plugin Manager.

Removal/pre-disable checks protect Logistics and required dependencies while operational/history obligations exist or metadata is inconsistent. Suspension uses a persisted effective cutoff and serializes with new commitment commands. Removal never drops protected history implicitly.

## IC11. UI/API/document parity

Every consequential UI, API, job, Barcode and direct-service entry point calls the same application/owner services and policies. Form/request validation protects trust boundaries but cannot replace service authorization. Printed passes show immutable revision and current/non-current status; reprint creates no authority.

## IC12. Optional external adapter

An external receiver/provider/payment/channel adapter is disabled until its capability worksheet proves:

- stable idempotent create identity;
- lookup after timeout and duplicate detection;
- explicit reversal/correction semantics;
- authentication, authorization, secret rotation and least-data sharing;
- approved secret storage and environment separation, credential-access audit and revocation;
- TLS certificate validation and an outbound destination allowlist with private-network blocking for configurable URLs;
- authenticated callback signatures, timestamp/nonce replay rejection, schema/payload limits and redacted failure logs;
- event freshness/hold cutoff and source ordering;
- reconciliation/export and outage fallback;
- preservation of manual/basic-phone workflow.

## Contract failure semantics

| Failure | Required response |
| --- | --- |
| Validation/authorization/revision conflict | Reject with zero effects and actionable reason. |
| Known invariant conflict | Reject under lock; preserve existing state. |
| Local late failure | Roll back the whole transaction. |
| Deadlock/serialization failure | Roll back first, then retry the complete database-only command with the same intent within an explicit bounded policy. Current native transfer services surface the failure; they do not retry a downstream step or silently repeat the command. |
| Lost response | Lookup canonical result before re-execution. |
| Remote timeout/unknown | Persist uncertainty, protect scope/amount, reconcile original identity. |
| Optional notification failure | Preserve committed business result and retry/record only if notification is required. |
| Evidence storage mismatch | Do not make the dependent event effective. |
