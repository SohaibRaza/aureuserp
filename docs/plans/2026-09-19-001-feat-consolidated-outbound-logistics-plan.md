---
title: "Consolidated Outbound Logistics - Plan"
type: feat
date: 2026-09-19
deepened: 2026-09-19
artifact_contract: ce-unified-plan/v1
artifact_readiness: requirements-only
product_contract_source: supplied-plans-and-repository-evidence
execution: code
---

# Consolidated Outbound Logistics - Plan

## Goal Capsule

Build one AureusERP outbound logistics extension that follows demand through transport booking, loading, native stock issue, physical departure, receiving, exceptions, and freight settlement.

**Pilot scope:** all transport modes in the first live pilot: dedicated hired vehicles, multi-stop deliveries, goods-transporter consignments with applicable feeder/terminal/final-mile handovers, and distributor-arranged pickup. Develop these through small engineering slices; none of those slices alone is the authorized live pilot.

**Readiness:** the technical work is decomposed below, but this is a decision-gated implementation plan. Launch-blocking product and native correction decisions remain in Q1–Q10. The metadata therefore stays `requirements-only` rather than falsely granting blanket execution readiness. U1 and review of the existing foundation can start; other units have specific decision gates. Completing the decision register and resolving the structural gates allows a subsequent readiness change without creating another plan.

**Authority:** current user decisions and repository instructions govern; current source and the September 19 evidence addendum govern implementation claims; the requirements in this document govern the proposed consolidated product; the two supplied draft packs provide provenance, not business approval.

**Boundaries:** planning creates no application changes, operational postings, scheduled jobs, policy approvals, commits, or pushes. The stashed `docs/logistics/plan` is excluded from this work. Future implementation must preserve unrelated local work and follow the repository's explicit authorization rules for Git and production operations.

**Stop conditions:** an unapproved quantity conversion, unknown stock/payment outcome, unsafe load, unverified consequential authority, or unresolved shared-writer contract blocks the affected action. It never prevents an authorized user recording what physically happened.

---

## Comparison and Consolidation

### Source identity and evaluation method

- **Plan A:** supplied folder `shafique-foods-logistics-v0.2`; README identifies version 0.2.0, dated September 15, 2026.
- **Plan B:** supplied folder `shafique-outbound-transport-v0.2.0`; version 0.2.0, dated September 16, 2026.
- **Repository evidence:** the six documents in `docs/logistics` named by the user, plus the current owning source and test definitions. The malformed supplied report path resolves to `docs/logistics/PHASE-2A-IMPLEMENTATION-REPORT.md`.

Pack references below use the label A or B followed by a path within that supplied pack. They are external source locators, not proposed repository directories. Implementation paths are repository-relative.

Both packs explicitly describe drafts and synthetic specification models. A reports 22 model checks and 84 unexecuted ERP scenarios; B reports 30 model checks and 64 unexecuted application scenarios. These are reported source-pack results, not tests rerun for this plan. Requirement counts are not quality scores. Assessment focuses on enforceable behavior, fit to this repository, operational completeness, and delivery cost.

### Comparative assessment

| Dimension | Plan A | Plan B | Consolidated decision |
| --- | --- | --- | --- |
| Repository fit | Explicit AureusERP ownership, child Company scope, plugin lifecycle, native execution lineage. Packaged audit predates current changes. | Portable design deliberately leaves ERP, framework, tenancy, and adapters open. | Use A's native boundaries, refreshed against current source. Close generic platform discovery already answered by the repository. |
| Demand allocation | Strong split-claim lifecycle and native backorder remapping. | Strong warning that every consuming writer must share authority; worker leases are not cargo reservations. | Use both: native shared enforcement, bounded planning claims, and explicit remapping of remaining demand. |
| Packing | Separates commitment, actual packing, stock units, measurements, and provenance. | Makes immutable child dependency versions and unknown lot identity explicit. | Capture earliest commitment and actual loading separately. Freeze validated breakdowns; defer a general hierarchy editor. |
| Departure consistency | Defines a release batch for one controlled departure and second-operation failure proof. | Defines revision-bound single-use release, revocation, and cancellation/late-acknowledgement handling. | One short local transaction for a proven batch; separately controlled physical release. No speculative configurable saga. |
| Receiving | Distinguishes incremental, cumulative, corrected, and unclear reports. | Strong canonical receiving-event identity and competing evidence reconciliation. | One canonical receiving event with multiple observations and explicit input intent. |
| Cargo and correction | Nonoverlapping leaf portions, custody transfers, first-acceptance history, downstream correction review. | Clear lost-then-found reversal and unresolved source-order attribution. | Preserve both invariants in ordinary relational records; no pouch serialization or event-sourced platform. |
| Money | Clear native Accounts ownership, genuine pre-bill advance, supplier/recipient separation. | Clear book balance versus pending payout authority, overlapping holds, and third-party payer reporting. | Native financial authority and bounded payment intent; Logistics stores approved terms and allocations to native results. |
| Safety | Practical capacity uncertainty, actual equipment, source quality controls. | Explicit staged/in-transit/delivered quality holds and wrong-distributor fulfillment rules. | No generic override of known restrictions; capture physical incidents and route disposition to the native/quality owner. |
| Low-tech operations | Task-first workspaces, coordinator calls, protected evidence. | Adds trusted callback handling for consequential changes and coordinator workload/backup measures. | Basic-phone participation with accountable assisted entry; no mandatory driver app or OTP. |
| Pilot sequencing | Full operational coverage precedes pilot, but Accounts can delay early engineering proof. | Useful capability activation matrix; later thin-slice refinement conflicts with earlier broad master-data sequence. | Small engineering slices, parallel owner work, one all-mode pilot gate as chosen by the user. |
| Recovery | Strong in-flight adoption, preserved native IDs, evidence restoration, inactive-assignee handling. | Strong restored-queue/unknown-outcome reconciliation and per-operation capability worksheet. | One cutover/recovery process with physical, stock, financial, and evidence checks. |
| Complexity | Roughly thirty conceptual records and arbitrary-depth packing invite overbuilding despite warnings. | Broad entity catalogue, generic external integration machinery, and setup-first slices invite the same. | Concepts do not each become a table, service, screen, or phase. Add only persisted distinctions needed for invariants. |
| Evidence | Honest model limitations, but old “no functional baseline” statement is now stale. | Honest model limitations, but no current-source implementation mapping. | Distinguish source-inspected, historically tested, newly verified, and production-qualified evidence. |
| Implementability | Rich requirements and failure semantics, but tables, hooks, owner policies, and some corrections are unresolved. | Rich operational controls, but source-specific writers, locking, backorders, and lifecycle are unresolved. | Supply the bounded units, decision gates, file paths, test cases, and rollout criteria below. |

### Strongest ideas retained

A's distinctive contributions are **one-departure release batches**, **native subset/backorder lineage**, **earliest conversion snapshots**, **receipt input intent**, and **plugin/evidence survival**. See A `docs/04-design/RELEASE-AND-RECOVERY-PROTOCOL.md`, `CARGO-AND-RECEIPT-RULES.md`, and `docs/05-delivery/CUTOVER-AND-RECOVERY-RUNBOOK.md`.

B's distinctive contributions are **canonical reconciliation of conflicting reports**, **bounded loss reversals**, **unattributed combined receipts**, **pending-money authority**, **quality containment across the journey**, and **pilot workload gates**. See B `docs/04-architecture/transaction-and-command-contracts.md`, `quantity-identity-and-corrections.md`, and `docs/06-delivery/pilot-scope-and-launch-gates.md`.

The plans agree on most fundamentals. Their overlaps should become one rule, not two parallel approval, receipt, cargo, or recovery frameworks.

### Weaknesses eliminated and residual gaps

1. **Replace conflicting delivery sequences.** A puts money ahead of foundation in one sequence; B starts broad setup before later recommending a thin slice. This plan has one dependency structure and separates engineering proof from the all-mode live pilot.
2. **Replace generic rediscovery with current evidence.** Neither an old packaged audit nor memory can establish today's implementation. Review existing changes rather than rebuilding the plugin or redoing the whole audit.
3. **Remove a speculative second release engine.** A's per-operation fallback is safe as a warning, but lacks a proven onsite correction. It is not an implementation option in this plan. Failure to prove local batch atomicity blocks that capability and requires a new decision.
4. **Do not confuse payment stages.** A's state table describes “pending/posted” as applied money in one place. B clarifies the distinction. R24 owns separate instruction, posting, allocation, and residual states.
5. **Remove generic packaging/routing/integration infrastructure from the critical path.** Immutable breakdowns, manual pricing, known movement legs, and native services cover the evidenced need.
6. **Resolve actual shared-writer protection.** Neither pack identifies every current direct Inventory caller. A Logistics-only lock remains insufficient even if its own tests pass.
7. **Resolve issued-but-onsite correction.** Atomic posting does not solve cancellation after commit but before departure. A fictional distributor return is unacceptable.
8. **Resolve the evidence file/SQL boundary.** A SQL rollback cannot restore deleted bytes. Evidence durability must precede acceptance of its database association.
9. **Define suspension eligibility.** An old trip must not accept new cargo merely because “existing work may continue.” The cutoff and concurrent enforcement need an explicit contract.
10. **Avoid fabricated estimates.** No verified traffic, team capacity, legal payload, tax treatment, language, recovery target, or approved operator workload exists in the inputs. These remain owner inputs, not attractive invented defaults.

### Alternatives considered

| Alternative | Benefit | Decisive reason not to select it |
| --- | --- | --- |
| Adopt A unchanged | Best native-domain fit. | Stale baseline, unresolved policy, money-coupled engineering sequence, and excessive conceptual breadth. |
| Adopt B unchanged | Best operational ambiguity and activation controls. | Generic ERP architecture would recreate settled repository choices and miss native lifecycle/backorder details. |
| Implement the union of both catalogues literally | Superficially maximizes coverage. | Duplicates rules, records, and workspaces without increasing guarantees. |
| Logistics-only wrappers around existing native calls | Small visible initial diff. | Native API, Barcode, Manufacturing, and direct services can bypass those locks and restrictions. |
| Generic distributed workflow/outbox platform for every command | Uniform-looking architecture. | Local database calls do not require remote orchestration; it adds failure modes and maintenance. |
| Dedicated-only first live pilot | Earlier supervised feedback with fewer modes. | User explicitly chose all transport modes for the first live pilot. Retain thin engineering slices, not narrowed live scope. |

---

## Current Repository Baseline

The planning checkout was `master` at `3807c2d498b0c3e609f6884d764b0518e38e30a8` with substantial existing local work. Commit identity does not describe those uncommitted changes. No application tests, builds, migrations, database queries, uninstall actions, or operational writes were run during this planning task.

| Area | Current evidence | Consequence |
| --- | --- | --- |
| Runtime packages | `composer.lock` identifies Laravel 13.21.1, Filament 5.7.6, Livewire 4.3.3, Pest 4.7.5, PHPUnit 12.5.30. September 19 report records PHP 8.5.0/PostgreSQL 18.1. | Use locked versions for technical design. The older version block in `AGENTS.md` is stale; retain its behavioral instructions. Runtime deployment versions still require execution-time confirmation. |
| Nonposting foundation | Existing `plugins/shafique/logistics` includes registration, Company controls, settings, control history, and removal protection. | Review and extend it. Do not scaffold a competing extension. |
| Foundation scope | `OperationalControl` defaults new activity to suspended; exact-company authorization exists. `PreventRemoval` blocks installed Logistics and required dependencies, including inconsistent metadata. | Preserve the stricter current removal boundary; qualify new-command/suspension serialization. |
| Sales confirmation | `OrderWorkflow::confirm()` owns a transaction and root lock with confirmed-state replay. | Preserve this local repair. It is not request-key/payload idempotency. |
| Inventory completion/cancel | `TransferWorkflow::complete()` and `cancel()` still lack a shared transaction/root-lock boundary. | Shared hardening remains prerequisite work. September 19 `TEST-BASELINE.md` supersedes earlier completion/concurrency success claims. |
| Return creation | Explicit positive finite source quantities and one-request transaction are present. | Shared source state, successful replay, cumulative return limits, and API quantity input remain incomplete. |
| Quant/reservation | Existing-row locking does not settle missing-bucket creation or NULL-safe uniqueness. Direct move callers remain exposed. | Quant identity, all-writer locking, and cross-engine evidence are gates. |
| Accounts | Native bill/payment/reconciliation and standalone vendor payment primitives exist. Safe pre-bill advance/recipient/idempotency contracts are unproven. | Do not create fake final bills or replace the accounting supplier with the receiving driver. |
| Chatter | Follower work uses an application-termination callback; attachment deletion can remove bytes. | Termination is not after-commit; Chatter cannot own protected operational evidence. |
| Reported test evidence | Latest addendum reports 177 selected tests/515 assertions on disposable PostgreSQL. | Historical bounded evidence only. Full suite, MySQL, browser, loads, general notifications, advances/payees, and outstanding Recon failures remain separate gates. |
| Institutional context | No `docs/solutions` corpus, root `CONCEPTS.md`, or `STRATEGY.md` was found. | Use the current repository contracts and reports; do not invent missing conventions. |

Source anchors: `docs/logistics/INTEGRATION-CONTRACTS.md`; `PHASE-2-BACKLOG.md`; `PHASE-2A-IMPLEMENTATION-REPORT.md`; `TEST-BASELINE.md`; `plugins/webkul/inventories/src/InventoryManager.php`; `plugins/webkul/accounts/src/Services/PaymentRegistrar.php`; `plugins/webkul/chatter/src/Models/Message.php`.

---

## Product Contract

### Summary

Extend the existing Logistics plugin with one controlled outbound workflow. Preserve native commercial, stock, and accounting ownership while adding transport commitments, packing history, physical custody, receiving evidence, and explainable freight links. The first live pilot covers every supported transport mode and its recovery paths.

### Problem Frame

Outbound work combines variable packing, informal transport arrangements, shared loads, basic-phone reporting, receipt disputes, and several parties handling freight. Native ERP records do not by themselves establish who carried which goods, what physically happened, or whether reported receipt and payment evidence are authoritative. The two plans describe these gaps well but leave competing implementation sequences and unresolved owner policies.

### Actors and responsibility

| ID | Actor | Responsibility |
| --- | --- | --- |
| A1 | Sales/master-data owner | Eligible demand, source commitment, substitution, remainder, product/UoM meaning. |
| A2 | Planner/coordinator | Booking, source-linked planning, call records, follow-up, owned exceptions. |
| A3 | Warehouse/load verifier | Actual quantity/packing/lot and vehicle inspection; disposition evidence. |
| A4 | Supervisor/gate operator | Scoped approvals and controlled handover/departure under assigned authority. |
| A5 | Driver/provider/collector | Operational participation without a required account or smartphone. |
| A6 | Distributor contact/receipt verifier | Receiving evidence and authorized reconciliation; roles may be distinct. |
| A7 | Accounts/financial approver | Native liabilities, advances, recipient authorization, payments, allocation, corrections. |
| A8 | Quality owner/support/technical owner | Quality disposition, operational recovery, access/lifecycle, reliable system boundaries. |

Role names identify accountability, not approval to assign those permissions to actual users. Q2 resolves separation and exceptional delegated entry.

### Requirements

**Authority and lifecycle**

- R1. Native Sales, Inventory, Accounts, Partners, Products, and Support remain the owners of their records and ledgers; Logistics adds coordination and evidence only.
- R2. Every action and reference uses the source's exact `company_id`, including child Companies; shared physical trips across companies are excluded.
- R3. Suspension blocks new commitments while preserving authorized work on existing obligations; protected history and dependencies survive removal attempts.
- R4. Commands enforce current actor access, expected revision, significant payload identity, and authoritative business invariants before mutation or retrieval of a saved result.
- R5. Material changes invalidate only affected approvals, and one current release authority can authorize only its intended departure.
- R6. Preserve durable private evidence, provenance, occurrence/recording time, and correction links with controlled retention and access.

**Demand, packing, and transport**

- R7. Read eligible native demand and distinguish tentative plans from bounded active claims and native physical reservations.
- R8. Splits, partial dispatch, source edits, and native backorders preserve quantity lineage without duplicated or double-subtracted consumption.
- R9. Preserve commercial/native quantity and conversion intent at the earliest relevant commitment, separately from actual physical loading.
- R10. Keep product, required lot, stock UoM, exact contents, outer packs, and measure provenance distinct; unknown data cannot authorize an unsupported conversion or unsafe fit.
- R11. Plan against actual equipment, independent weight/space/handling constraints, and cargo onboard each relevant segment; manual checks cannot override known prohibitions.
- R12. Support dedicated transport, multi-stop loads, carrier consignments and applicable linked service legs, and distributor pickup without inventing an unseen carrier itinerary.
- R13. Keep contracted provider, performing provider, owner, driver, authorized recipient, actual payer, and final cost bearer distinct while permitting one known person to hold several roles.
- R14. Preserve negotiated booking revisions, service endpoints, provider acceptance evidence, actual assignments, and planned versus chargeable quantities.

**Execution, receiving, and recovery**

- R15. Issue only the verified native cargo set for one controlled departure; no normal gate authority exists for a rejected, partial, or unknown posting result.
- R16. Stock issue, physical custody, departure, service endpoint, receipt verification, and financial closure remain separate facts.
- R17. Staff can record progress, unauthorized departure, overage, refusal, wrong recipient, or uncertain custody without manufacturing approval or posting.
- R18. Receiving input declares incremental, cumulative, correction, or unclear intent. Event-specific observations reconcile to one canonical physical event; cumulative confirmations are scoped attestations that may cover several events and must not invent a delivery attempt or arrival time.
- R19. Authorized receipt allocation is bounded by identified dispatched portions; conflicting evidence or unknown source attribution cannot silently change fulfillment.
- R20. Current cargo portions do not overlap; splitting retires the parent, transfers preserve content, and condition is independent of acceptance and custody.
- R21. Receipt, loss, return, and recovered-goods corrections are bounded by original facts and dependent effects; a prior accepted customer return does not erase original acceptance.
- R22. Quality and commercial reassignment decisions stay with their owners; returned goods do not become saleable merely because physical custody changed.

**Freight and operation**

- R23. Record approved freight, extras, aggregate bills, advances, payers, and allocations to canonical Accounts records without a parallel payable balance.
- R24. Keep payment instructions, confirmed postings, applied settlements, holds, claims, and recoverable advances distinct; unknown or pending disbursement protects further payment authority without falsely reducing book balance.
- R25. A genuine advance precedes the final bill and is later allocated through the approved native contract; supplier versus recipient and distributor-paid freight require explicit authority.
- R26. Freight allocation is explainable and conserves the charge after currency rounding; missing basis remains unallocated rather than becoming zero or an arbitrary equal split.
- R27. Basic-phone drivers and small providers can complete enabled workflows through attributed staff assistance; consequential changes require the approved independent verification route.
- R28. Workspaces, printed documents, exports, and queues show the relevant quantity/evidence/money dimensions and give every exception an owner and next action.
- R29. Cutover adopts existing postings once; restore and outage recovery reconcile physical, native, and any external outcomes before replay.
- R30. All transport modes pass their own acceptance gates before the first live pilot; engineering slice completion is not pilot authorization.
- R31. Measure operational workload, response performance, recovery, and queue age against approved targets before expansion.
- R32. Optional automation preserves the same controls and manual fallback; rates, channels, portals, and external receiving integrations do not become prerequisites without an approved use case.

### Product decisions

- **All-mode first live pilot.** Chosen over a dedicated-only pilot by the user after the rollout alternatives were presented. This increases pre-pilot coverage and preparation, while preserving small engineering increments. (session-settled: user-directed — chosen over a dedicated-only pilot: user requires all transport modes at the first live pilot.) Governs R12, R30.
- **Native record ownership and exact-company scope.** Adopt the current repository integration boundary; do not infer a new tenancy model from B's platform-neutral questions. Governs R1, R2.
- **Explicit quantities and evidence.** Preserve the shared return repair and both packs' rejection of inferred full returns, guessed pack factors, and automatic promotion of reports. Governs R8–R10, R17–R22.

### Key flows

- F1. A1 releases demand; A2 claims source quantities and books service; A3 records actual loading; A4 releases the verified departure through native Inventory. Covers R7–R16.
- F2. A2 records calls or documents; A6 reconciles receipt evidence; A3/A8 resolve custody, refusal, loss, and returns. Covers R17–R22, R27.
- F3. A2 records agreed charges; A7 approves and records native advance/bill/payment effects; Logistics links allocations and unresolved obligations. Covers R23–R26.
- F4. A8 suspends or restores processing; staff reconcile existing obligations and resume scoped activity after verified recovery. Covers R3, R6, R28–R31.

### Acceptance examples

| ID | Given and action | Required outcome |
| --- | --- | --- |
| AE1 | Two independent planners request 60 each against 100 eligible units. | Both cannot retain 60; 40 plus 60 can succeed. Stock reservation remains native. |
| AE2 | Ship 40 of a 100-unit move and create a native 60-unit backorder. | The remainder maps once to the successor; posting does not consume unselected lines or subtract the 40 twice. |
| AE3 | A confirmed 6×12 packing commitment is followed by a master change to 6×18. | Historical stock/commitment meaning stays unchanged; actual repacking needs its own supported contents and revision. |
| AE4 | The second native operation in one departure fails after the first executes. | The proven local boundary rolls back all participating effects; no gate authority is issued. |
| AE5 | A vehicle changes or release is revoked while gate confirmation races. | Only a current, authorized revision can be consumed; an actual unauthorized exit is recorded as an incident. |
| AE6 | 60 accepted; receiver later says “100 total,” possibly covering several undocumented drops. Another receipt changes the revision before verification. | Recompute the difference against current scope. Preserve cumulative coverage without inventing one physical event; later event-specific evidence reconciles within that coverage and cannot add the same accepted quantity again. |
| AE7 | Driver says 100, paper says 100, receiver says 95. | Three observations support one reconciled event; no automatic 295, maximum, average, or latest-wins result. |
| AE8 | 100 units split into 60 and 40; the latter move to another vehicle. | Current leaves total 100, each with one resolved custodian or explicit uncertainty; no second factory issue. |
| AE9 | 90 accepted and 10 closed as loss; six are recovered. | Reverse six of the original loss into unresolved cargo, then authorize disposition; total remains 100. |
| AE10 | An advance of 20 is paid before a final bill of 63; a later 10 is paid by a distributor on the company's behalf. | Native approved allocation leaves 33 when all effects are verified/applied; no second company cash payment or goods-payment misclassification. |
| AE11 | A payout response is lost or an old backup lacks a later external success. | Retain/reconstruct uncertainty, reconcile the original reference, and block blind replacement. |
| AE12 | Goods were issued but the truck never departed; the load is aborted. | Record onsite custody and unload; restore availability only through a proven native correction, never fictional customer receipt/return history. |
| AE13 | One receipt of 180 covers two 100-unit source orders without shortage attribution. | Preserve the observed total and an attribution task; do not invent FIFO/proportional order fulfillment. |
| AE14 | A carrier consignment arrives at destination terminal, or an authorized distributor collector takes goods. | Evaluate the actual contracted endpoint and recipient authority; terminal arrival alone is not distributor receipt. |
| AE15 | Activity is suspended while a coordinator adds cargo to an old trip. | Concurrent guarded evaluation rejects the new commitment; existing receipt/correction tasks stay accessible. |
| AE16 | Database association fails after evidence upload, or storage lacks required bytes. | No accepted event references missing evidence; an unreferenced upload can be cleaned safely. |

### Scope boundaries

**Included in the first live pilot:** every R12 mode, applicable freight control including real advances, partial loading/receipt, phone-based reporting, source quality restrictions, controlled returns and onsite aborts, breakdown/transfer evidence, documents, negative authorization cases, and recovery. Mode-specific responsibilities must be approved; unsupported behavior is not enabled merely to meet the scope label.

**Deferred to follow-up work:** automated rate selection and zone precedence, route optimization, sophisticated dock appointments, calibrated capacity suggestions beyond initial manual checks, optional communication/receiver/provider channels, automated external GRNs, arbitrary mixed-SKU/repack authoring, and multi-company transport. R32 preserves applicable source requirements as deferred capabilities.

**Outside this product's identity:** a replacement Sales/Inventory/Accounts system, owned-fleet maintenance suite, insurance administration, mandatory driver application, per-pouch serialization, general workflow engine, universal event sourcing, or complete carrier-network simulation. Product cash collection remains separate from freight even when the same driver handles both.

### Decision gates and unresolved questions

A recommendation is not company policy. Resolve a gate with an accountable person, approved rule, representative evidence, effective date, and permitted actions. A meeting or empty configuration record is not closure.

| Gate | Required decision / recommended direction | Blocks | Owner and evidence | Source decisions |
| --- | --- | --- | --- | --- |
| Q1 | Native stock UoM, commercial packing commitment, SKU changes, whole/open/fractional/mixed packing. Recommend native units and exact snapshots; no inferred pouch base unit. | U6 and production packing/import paths. | Product/Sales/warehouse; real cartons/bundles and source orders before/after changes. | A D-001–003; B DEC-02–04 |
| Q2 | Permissions, approval thresholds, consequential call verification, receipt evidence and delegated-entry separation. | U8, authoritative receipt/release and payment activation. | Operations/Accounts; role matrix and call-only role play. | A D-004/005; B DEC-14/17 |
| Q3 | Canonical quant identity, shared lock protocol, partial-order remainder, enrolled native-writer behavior, and issued-but-onsite correction. Recommend preserving native backorders and explicit correction. | U3–U5, U9/U11 stock behavior. | Inventory/Sales; duplicate preflight and source-backed transaction/correction examples. | A D-007/012/025; B DEC-01/13/30/32 |
| Q4 | Actual equipment limits, inspection evidence, unknown measurements, required lot/quality restrictions, return quarantine and loss disposition. | Loading, return/loss and all-mode departure qualification. | Warehouse/quality; measured samples and authorized disposition procedures. | A D-014/017/018/025/027; B DEC-05–07/27/35/37 |
| Q5 | Native accounting recognition, genuine advance allocation, supplier documents, taxes/rounding, recipient authorization, distributor-paid treatment, holds/offsets and cost allocation. | U15/U16 financial behavior and freight-bearing pilot. | Accounts; native pre-bill and separate-recipient examples, approved accounting policy. | A D-009–011; B DEC-09–12/16/21/26 |
| Q6 | Per-mode service endpoints, collector authority, payer/cost responsibility, first/final-mile custody, and source attribution of combined receipts. | U12/U18 mode-specific fulfillment and all-mode pilot. | Operations/Sales/distributors; one real walkthrough per mode. | A D-006/008; B DEC-10/28/34/36 |
| Q7 | Protected evidence collection, access, retention/deletion, and storage restoration. Recommend private immutable accepted evidence; no default ID/audio collection. | U7 production collection/retention. | Data/operations owner; approved classes and restore sample. | A D-019; B DEC-22 |
| Q8 | Document names, numbering, languages, read-back instructions, and any required appointment/waiting terms. | Final documents and operator acceptance. | Dispatch/Accounts; reviewed paper samples. | A D-013/016/028; B DEC-18/19 |
| Q9 | Suspension cutoff, outage authority, recovery objectives, pilot cohort/sample mix, throughput and workload targets. Recommend no new commitments during suspension and no implicit emergency override. | U8 cutoff, U19/U20 launch acceptance. | Sponsor/operations/technical owner; observed volumes, backup drill, backup-coordinator exercise. | A D-021/022/026/029; B DEC-24/25/29 |
| Q10 | Operational/final closure, correction authority, inactive-owner escalation, and conflicting receipt resolution. | U12/U13 final resolution and pilot closure. | Operations/Accounts; shortage, recovered loss, and paid-then-corrected examples. | A D-018/023/030; B DEC-23/27/33/35 |
| Q11 | Optional zone/rate, route, and scheduling automation rules. Deferred; manual approved planning/pricing remains available. | Future automation only. | Operations; stable real rules and measured value. | A D-015/016; B DEC-08/19/20/31 |
| Q12 | Optional external receiving/channel adapters and retention of remote operation identities. Deferred until an integration is selected. | External GRN or digital-channel writes only. | Native/external system owner; create/lookup/reverse/replay capability proof. | A D-020/028; B DEC-15/30 |

The repository resolves A D-024 and B DEC-24's basic tenancy/platform direction through R1/R2; deployment qualification remains Q9. All-mode pilot scope within A D-006/B DEC-29 is user-settled, while actual sites/providers and mode responsibilities remain Q6/Q9. A D-022's removal protection is already implemented locally; qualification remains U2. Other source decisions are consolidated above rather than copied into another 67-item queue.

---

## Planning Contract

### Key technical decisions

- KTD1. **Extend the modular monolith.** Use `plugins/shafique/logistics`, Eloquent, existing native application services, and installed Filament/Livewire conventions. Add owner-local changes only for demonstrated seams. Implements R1/R2; no framework upgrade or new dependency is proposed.
- KTD2. **One proven local release transaction.** Native Inventory effects, selected operation results, claim remapping, and critical Logistics evidence must commit on the same connection. There is no per-operation fallback engine. If native participation cannot be proven, stop U11/U18 and revise the design. Implements R8/R15/R16.
- KTD3. **One shared writer protocol.** Inventory owns operation/move/quant invariants, including missing-quant creation and cumulative returns. Document the full affected-root set and deterministic acquisition order before modifying writers. Integrate the Company/control boundary for Logistics activity checks; do not hold locks during file uploads, calls, printing, or remote I/O. Implements R3/R7/R8/R15.
- KTD4. **Guard enrolled native execution.** Claimed or frozen Logistics cargo cannot be completed through another native surface without the approved release context. Use a narrow synchronous native precondition hook where needed; Logistics supplies its own enrollment guard. Native owner code must not import the Logistics module. Preserve unenrolled native behavior and explicitly reconcile historical/native results. Implements R1/R4/R8/R15.
- KTD5. **Snapshot commitments, not a second master system.** Capture immutable source-line quantity/conversion facts in the native owner at confirmation/release and relevant confirmed-line changes. Logistics retains a reference plus actual-loading breakdowns. Store exact decimal quantities/factors or rational breakdowns; validate the conversion at the boundary to existing native precision. No blanket numeric rewrite. Implements R9/R10.
- KTD6. **Small command records and scoped approvals.** Persist company/command/key uniqueness, significant payload snapshot/fingerprint, expected revision, result references, and actor context for consequential new commands. Different keys still serialize at the same business root. Recheck access before returning a saved result. Do not retrofit request keys across every native API without a need. Implements R4/R5.
- KTD7. **Durable evidence before effective association.** Stage/validate files privately, finalize immutable bytes and hash/size, then associate them atomically with the accepted database event. SQL failure may leave a recoverable orphan; it must not create accepted evidence with missing bytes. Chatter holds optional links, not deletion ownership. Implements R6.
- KTD8. **Relational cargo and canonical receipts.** Start with one identified cargo portion per dispatched cohort and create child portions only for a real split. Store observations separately from canonical receiving allocations; distinguish physical events from cumulative attestations and preserve unresolved attribution. Materialized totals are derived from effective nonoverlapping facts, with no event-sourcing framework. Implements R18–R21.
- KTD9. **Owner-local financial safeguards.** Harden payment/advance execution and residual allocation in Accounts. Logistics charge approval never equals accounting recognition or disbursement. For actual manual cash/bank execution, Accounts must record protected instruction and reconciliation evidence; do not invent a bank API. Implements R23–R26.
- KTD10. **After-commit is not durable delivery.** Keep required local database work inside its owner transaction. Dispatch optional irreversible notifications after commit. Persist outgoing business work only when a real asynchronous/external effect must survive a crash, using existing workers and one bounded durable record/relay. Implements R4/R6/R24/R29/R32.
- KTD11. **Simple operator surfaces.** Build a trip workspace, receiving/follow-up workspace, freight workspace, exceptions queue, and existing settings. Shared services own rules across UI/API/Barcode callers; no one-screen-per-concept design. Implements R27/R28.
- KTD12. **Evidence-driven activation.** Every mode is included at the first live pilot, but its acceptance evidence remains independent. Shared-owner migrations/locking changes require PostgreSQL and supported MySQL qualification. A green Python model or Pest parallel suite is not a shared-resource race test. Implements R30/R31.

### High-Level Technical Design

**Ownership and integration**

~~~mermaid
flowchart TB
  Sales[Native Sales and quantity commitments] --> Logistics[Logistics planning and trip workspace]
  Masters[Native Partners Products UoM Company] --> Logistics
  Logistics --> Inventory[Native Inventory guarded execution]
  Logistics --> Receipt[Observations cargo and reconciled receipt]
  Logistics --> Freight[Freight agreements and native allocations]
  Freight --> Accounts[Native Accounts payment and reconciliation]
  Inventory --> Release[Release result and gate authority]
  Evidence[Private protected evidence] --> Receipt
  Evidence --> Release
  Evidence --> Freight
  Controls[Exact-company permissions and suspension] --> Logistics
~~~

**Controlled command sequence**

~~~mermaid
sequenceDiagram
  participant Staff
  participant L as Logistics command
  participant C as Company and approval controls
  participant I as Native Inventory
  participant D as Shared database
  Staff->>L: Submit verified intent and request identity
  L->>C: Authorize current access and scope
  L->>D: Begin transaction and acquire ordered owner locks
  L->>C: Recheck revision, holds, suspension and approval
  L->>I: Execute exact enrolled native cargo set
  I->>D: Persist stock effects and backorder lineage
  L->>D: Persist consumed claims, canonical result and critical event
  D-->>L: Commit or roll back as a unit
  L-->>Staff: Canonical result or explicit failure
  Staff->>L: Record controlled physical handover
  L->>C: Recheck and consume current release authority
~~~

**Departure and recovery lifecycle**

~~~mermaid
stateDiagram-v2
  [*] --> Draft
  Draft --> Verified: Actual load approved
  Verified --> Posted: Native batch commits
  Verified --> Draft: Material revision
  Posted --> Released: Current gate authority issued
  Released --> Departed: Guarded authority consumption
  Released --> Posted: Authority revoked
  Posted --> OnsiteRecovery: Abort before physical departure
  OnsiteRecovery --> Reconciled: Native correction and recount
  Draft --> Incident: Unauthorized physical departure
  Verified --> Incident: Unauthorized physical departure
  Posted --> Incident: Unauthorized physical departure
  Departed --> Receiving
  Incident --> Receiving: Reconcile actual dispatched scope
  Receiving --> OperationalClosure: Cargo and exceptions resolved
~~~

An uncertain command result is an independent reconciliation condition that blocks the affected authorization; it is not a physical departure state. Financial closure remains independent of this lifecycle.

**Readiness and action gates**

~~~mermaid
flowchart TB
  Intent[Requested business action] --> Access{Current company access and permission?}
  Access -->|No| Reject[Reject without effect]
  Access -->|Yes| Existing{New commitment or existing obligation?}
  Existing -->|New| Active{Company accepts new activity?}
  Active -->|No| Reject
  Active -->|Yes| Policy{Required policy and evidence resolved?}
  Existing -->|Existing| Policy
  Policy -->|No| Capture[Keep observations and owned blocked work]
  Policy -->|Yes| Outcome{Native state and prior outcome known?}
  Outcome -->|No| Reconcile[Reconcile canonical result]
  Outcome -->|Yes| Execute[Execute guarded owner command]
~~~

**Quantity and evidence flow**

~~~mermaid
flowchart TB
  Source[Native source line and immutable commitment] --> Claim[Active unposted claim]
  Claim --> Native[Native issue and remainder mapping]
  Native --> Cargo[Dispatched cohort]
  Cargo --> Leaves[Current nonoverlapping portions]
  Report[Incremental cumulative corrected or unclear observation] --> Event[Physical event or scoped cumulative attestation]
  Event --> Attribution[Authorized source attribution]
  Attribution --> Outcomes[Acceptance return loss or outstanding allocation]
  Leaves --> Outcomes
  Outcomes --> Correction[Bounded correction and dependent work]
~~~

### Persistence shape and invariants

These are proposed persistence families, not a mandate for a table per noun. Reuse existing rows or child relations where they preserve identity and lifecycle. Physical migrations are confined to the owning plugin and gated as listed.

| Owner / record family | Minimum persisted meaning | Constraint / index direction |
| --- | --- | --- |
| Sales quantity commitment | Source line, commercial/native quantity and units, conversion/breakdown, rounding, revision, provenance. | Append-only source/revision identity; legacy unknown is a valid explicit classification. |
| Logistics trip and stops | Exact company, origin, recipient/location snapshots, planned/actual revisions, mode and ordered known stops. | Company/operational-state/date indexes; no shared-company trip. |
| Demand allocation and actual load lines | Source operation/move lineage, active claim quantity, commitment reference, actual product/lot/outer packs. | Many claims per move; guarded aggregate limits, not unique move ID. |
| Booking and assignment revisions | Native partner identities, service endpoints, acceptance evidence, negotiated terms, historical driver/vehicle/recipient roles. | Stable booking identity; revisions retain prior approved terms. |
| Release batch and native result items | Frozen departure/native set, actual-intent snapshot, result references, gate authority consumption. | One current release authority for an exact revision; native business-effect identity is separately protected. |
| Command result and approval | Company/type/key, significant intent, revisions, canonical results, authority scope and invalidation. | Unique company/type/key; permissions applied before replay retrieval. |
| Evidence and associations | Private immutable object, content metadata, hash, company/access, retention, event associations. | No cascade deletion of accepted bytes; safe orphan identification. |
| Cargo portions and outcome allocations | Original source cohort, parent/child split, effective quantities, custodian, authoritative acceptance/return/loss links. | Nonnegative quantities; parent retirement and child conservation under the cohort lock. |
| Observations and receiving records | Reporter/recorder/time/intent, physical-event identity or attestation scope, verification/conflict, coverage/allocation and correction references. | Request deduplication separate from event matching and cumulative coverage; no simplistic date/quantity uniqueness. |
| Exception/correction case | Affected fact/quantity/charge, owner role and assignee, next action, age, disposition and dependent work. | Company/owner/state/age indexes; inactive assignee does not orphan work. |
| Freight charge and financial allocations | Versioned approved component, source agreement, native bill/payment/advance links, applied amount and reversal reference. | Allocation conservation and supplier/company/currency compatibility; no mutable parallel AP balance. |
| Accounts instruction / owner idempotency | Approved obligation/advance purpose, recipient/destination snapshot, pending/known result and protected amount. | Shared Accounts locking and native effect identity; external references only for actual execution channels. |

Quant uniqueness is Inventory-owned. Q3 must settle whether storage category/user are identity dimensions and how nullable lot/package/partner values compare. Database uniqueness backs the missing-bucket strategy; a read-only duplicate report precedes any additive constraint. Never merge or delete stock rows automatically.

For cargo, use the original-obligation equation: **dispatched = accepted at least once + physically returned without prior acceptance + approved final unaccepted disposition + outstanding**. Apply it to effective nonoverlapping source portions. Subsequent returns of accepted goods belong to a linked native return history, not another additive term. Unattributed observations sit outside that equation until their scope is reconciled.

### Implementation constraints and scaling

- Use native factories, feature-test helpers, named routes, Form Requests for HTTP boundaries, and existing policy conventions. New UI follows installed Filament/Livewire patterns.
- Small bounded transactions lock relevant business roots rather than every trip or Company for the duration of a workflow. Discovery of recursively affected backorders/reservations must precede lower-level mutation.
- Use decimal storage and supported native precision; account for the current native float-oriented return/UoM boundary with validation and regression tests. Do not introduce an ERP-wide numeric migration.
- Paginate operational boards, eager-load required relations, and index active-company/status/owner/date queries. Do not hydrate a full historical cargo tree to render one queue row.
- Batch release size and latency targets come from Q9 measurements. If proven transaction limits cannot handle a real all-mode load, revisit the boundary before activation rather than silently switching to partial commits.
- Snapshot material facts and keep correction history; do not copy entire master records or build generic audit/event storage.
- Shared services must preserve ordinary unenrolled Inventory/Manufacturing callers. All claimed-source mutation routes must either participate in the shared guard or be blocked within the enrolled scope.
- Initial external effects are optional. A remote adapter requires evidence for stable create identity, timeout lookup, reversal, read freshness/hold cutoff, and reconciliation export before its write path is enabled.

Official guidance checked September 19, 2026 supports KTD2/KTD3/KTD10: [Laravel 13 transactions](https://laravel.com/framework/docs/13.x/database#database-transactions), [pessimistic locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking), [after-commit queue behavior](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions), [PostgreSQL 18 row locks](https://www.postgresql.org/docs/18/explicit-locking.html), and [NULL-sensitive uniqueness](https://www.postgresql.org/docs/18/ddl-constraints.html#DDL-CONSTRAINTS-UNIQUE-CONSTRAINTS). PostgreSQL syntax alone is not a MySQL portability design.

KTD5/KTD6's material-intent checks follow [OWASP transaction authorization](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html). KTD10's durable handoff distinction follows [AWS transactional outbox guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html); an outbox still requires duplicate-safe consumers. No external source determines the company's accounting or transport policy.

### Delivery sequence

| Stage | Outcome | Units / gate |
| --- | --- | --- |
| E0 | Reconcile current work and settle next-unit decisions. | U1/U2; Q1–Q10 assigned and evidence collected in parallel. |
| E1 | Qualify shared Inventory safety and native correction. | U3–U6; Q1/Q3/Q4. Structural multi-operation/backorder probes occur here. |
| E2 | Deliver one-operation engineering workflow with truthful receiving and exceptions. | U7–U13; Q2/Q6/Q7/Q9/Q10. This is not a live pilot. |
| E3 | Complete approved freight control and operator workspaces. | U14–U17; Accounts work can begin alongside E1, with Q5 resolved. |
| E4 | Complete every transport mode and recovery/cutover. | U18/U19; all-mode policy and acceptance gates. |
| P1 | Supervised all-mode live pilot. | U20; every applicable gate passed and accountable owners approve actual evidence. |
| Later | Improve efficiency from observed bottlenecks. | R32/Q11/Q12; no automatic expansion or new automation by this document. |

---

## Implementation Units

`L` in the navigation table abbreviates `plugins/shafique/logistics` only. Unit file lists spell out repository paths; files marked “new” do not yet exist. Migration basenames describe intended changes, with timestamps assigned during implementation.

| Unit | Outcome | Primary files | Depends on |
| --- | --- | --- | --- |
| U1 | Current baseline and isolated proof environment | Test runner, testing configuration, evidence docs | None |
| U2 | Qualify existing foundation/lifecycle | L services/listener and foundation/removal tests | U1 |
| U3 | Shared quant/reservation integrity | Inventory ProductQuantity/MoveReserver | U1, Q3 |
| U4 | Native transition and enrollment boundary | Inventory TransferWorkflow/direct writers | U3, Q3 |
| U5 | Safe returns and issued-onsite correction | Inventory return/correction services | U4, Q3/Q4 |
| U6 | Commitment and actual-packing history | Sales commitment hooks, source snapshots | U1, Q1 |
| U7 | Protected evidence lifecycle | L evidence model/service/storage boundary | U2, Q7 |
| U8 | Command authority and suspension | L command/approval services and policies | U2/U7, Q2/Q9 |
| U9 | Source claims and backorder mapping | L allocation service, Inventory hooks | U4/U6/U8 |
| U10 | Booking and actual loading | L trip/booking/load workspace | U9, Q4/Q6 |
| U11 | Verified native release and gate | L release service, Inventory boundary | U5/U10 |
| U12 | Canonical receiving | L observation/receipt services | U7/U8/U11, Q2/Q6/Q10 |
| U13 | Cargo custody and corrections | L cargo/exception services | U5/U12, Q4/Q10 |
| U14 | Restore Recon regression boundary | Existing Recon tests/fixtures | U1 |
| U15 | Accounts advance/payment integrity | Accounts payment/reconcile services | U14, Q5 |
| U16 | Freight agreements and allocations | L freight workflow and native references | U10/U15, Q5 |
| U17 | Workspaces, documents, and reports | L Filament/views/queries | U12/U13/U16, Q8 |
| U18 | All-mode execution | L multi-stop/consignment/pickup relations | U11–U13/U16, Q6 |
| U19 | Cutover/outage/restore | L adoption/recovery, runbook | U17/U18, Q7/Q9 |
| U20 | All-mode pilot qualification | Integrated acceptance evidence | U19, Q1–Q10 closed |

### U1. Reconcile current work and qualify isolated evidence

**Goal:** establish which existing changes and tests are the starting point without overwriting them.  
**Requirements:** R1, R30/R31. **Dependencies:** none.

**Files:** existing `scripts/run_logistics_phase2a_tests.sh`, `config/testing.php`, `phpunit.xml`, `plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php`, `plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php`, and the current `docs/logistics/TEST-BASELINE.md` / `docs/logistics/PHASE-2A-IMPLEMENTATION-REPORT.md`.

**Approach:** inventory current local changes and ownership; qualify the existing PostgreSQL harness; prepare a proven disposable MySQL target for shared-owner changes. Update stale evidence only after new execution. Wiring CI is a separate reviewed change within this unit; do not run bootstrap against an ordinary database.

**Patterns:** current runner marker/identity checks and supported vendor test discovery. **Execution note:** establish unchanged baseline evidence before new behavior.

**Test scenarios:**

1. A selected suite starts on fresh isolated storage/database and cleans only its marked task root.
2. A non-testing or unresolved target is rejected before destructive bootstrap.
3. Shafique tests are discovered alongside Webkul tests; outbound communications cannot reach real recipients.

**Verification:** retained baseline distinguishes historical failures, new failures, engine coverage, and untested work.

### U2. Qualify the existing nonposting foundation

**Goal:** preserve and verify installed-state, exact-company, suspension, and removal behavior.  
**Requirements:** R2/R3. **Dependencies:** U1.

**Files:** existing `plugins/shafique/logistics/src/Services/OperationalControl.php`, `plugins/shafique/logistics/src/Listeners/PreventRemoval.php`, `plugins/shafique/logistics/src/LogisticsServiceProvider.php`, `plugins/shafique/logistics/src/LogisticsPlugin.php`; `plugins/webkul/plugin-manager/src/Events/UninstallingPlugins.php` and its CLI/UI callers; `plugins/shafique/logistics/tests/Feature/FoundationTest.php` and `plugins/shafique/logistics/tests/Feature/RemovalTest.php`.

**Approach:** review existing work first; fix only demonstrated gaps. Keep installed-plugin/dependency removal protection, including inconsistent metadata. Resolve actual new-activity serialization in U8, not by claiming the current read check is locked.

**Patterns:** existing settings action and control-history transaction.

**Test scenarios:**

1. Parent-company access alone cannot mutate a child Company's controls.
2. Failed control-history persistence rolls back the setting.
3. CLI/UI removal and dependency removal preserve migration/plugin state; unrelated removal remains possible.
4. Missing control defaults to suspended and unauthorized direct calls fail.

**Verification:** current foundation/removal tests pass on the approved target; no operational posting feature is introduced.

### U3. Protect canonical quant creation and reservation

**Goal:** enforce shared stock bucket and reservation invariants.  
**Requirements:** R1/R7/R10. **Dependencies:** U1; Q3 approved.

**Files:** existing `plugins/webkul/inventories/src/Models/ProductQuantity.php`, `plugins/webkul/inventories/src/Services/MoveReserver.php` and direct callers; new owner migration under `plugins/webkul/inventories/database/migrations`; new `plugins/webkul/inventories/tests/Feature/Workflows/ReservationConcurrencyTest.php` and `plugins/webkul/inventories/tests/Feature/Workflows/QuantIdentityTest.php`.

**Approach:** perform authorized read-only duplicate preflight before defining an additive constraint. Choose null-equality and missing-row serialization against the approved identity. Apply the invariant where native writers converge per KTD3.

**Patterns:** native matching/reservation semantics and Sales independent-worker test pattern. **Execution note:** start with contention and missing-bucket characterization.

**Test scenarios:**

1. Independent 60+60 reservations against 100 cannot overallocate; 40+60 succeeds.
2. Concurrent creation of the same nullable bucket produces one canonical identity.
3. Different lot/package/partner identities remain distinct.
4. Existing duplicates stop migration with evidence; no stock row is silently repaired.

**Verification:** stored quantity/reservation outcomes and migration behavior pass separately on PostgreSQL/MySQL.

### U4. Harden native transitions and protect enrolled execution

**Goal:** make completion/cancellation failure-atomic and coordinate all relevant native writers.  
**Requirements:** R4/R8/R15/R16. **Dependencies:** U3; Q3 lock and enrollment contract.

**Files:** existing `plugins/webkul/inventories/src/Services/TransferWorkflow.php`, `plugins/webkul/inventories/src/Services/MoveCompleter.php`, `plugins/webkul/inventories/src/Services/MoveCanceller.php`, `plugins/webkul/inventories/src/Services/BackorderCreator.php`, `plugins/webkul/inventories/src/InventoryManager.php`; narrow native precondition events where needed; Logistics enrollment listener introduced with U9; `plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php`; new `plugins/webkul/inventories/tests/Feature/Workflows/TransferCompletionConcurrencyTest.php`; relevant API/Barcode/Manufacturing caller tests.

**Approach:** map direct move callers and recursively affected roots. Enforce owner transactions, state checks, ordered locks, and terminal replay. Establish the narrow hook needed for KTD4 without importing Logistics into Inventory. Audit critical listeners and connection propagation.

**Patterns:** `plugins/webkul/sales/src/Services/OrderWorkflow.php` and its confirmation-integrity/concurrency tests.

**Test scenarios:**

1. Completion versus cancellation yields one coherent terminal result and stock effect.
2. A late listener or ambient outer rollback leaves no partial stock/backorder/event state.
3. Partial/subset completion preserves unselected lines and successor identity.
4. A two-operation transaction fails on operation two and rolls back operation one.
5. Unenrolled API/Filament/Barcode/direct Manufacturing work retains native semantics.

**Verification:** no relevant writer bypasses the documented owner invariant; prove each supported engine independently.

### U5. Complete return and issued-onsite correction contracts

**Goal:** conserve returnable quantity and safely recover stock issued before a departure that never occurred.  
**Requirements:** R8/R21/R22. **Dependencies:** U4; Q3/Q4.

**Files:** existing `plugins/webkul/inventories/src/Services/TransferWorkflow.php` and API return request/controller boundary; owner-local correction service if the approved native path requires one; `plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php` and `plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php`; new `plugins/webkul/inventories/tests/Feature/Workflows/ReturnConcurrencyTest.php` and `plugins/webkul/inventories/tests/Feature/Workflows/OnsiteIssueCorrectionTest.php`.

**Approach:** preserve explicit positive-map validation. Add in-lock completed-source checks, cumulative active return limits and successful replay. Define API quantities without inferring an omitted map as “all.” Prove the approved onsite correction separately from a customer return.

**Test scenarios:**

1. Empty/foreign/nonpositive/nonfinite requests produce zero effects.
2. Two competing 60-unit returns against 100 cannot both remain active; cancellation and replay preserve the available remainder.
3. Issued cargo unloaded onsite regains availability only after approved correction and recount.
4. Customer returns retain origin links and quality inspection; no automatic saleable release.

**Verification:** AE12 and native return invariants hold through shared callers, with preserved physical and accounting meaning.

### U6. Preserve quantity commitment and packing history

**Goal:** prevent master-data changes from rewriting historical demand or loading.  
**Requirements:** R9/R10. **Dependencies:** U1; Q1.

**Files:** existing `plugins/webkul/sales/src/Services/OrderWorkflow.php`, `plugins/webkul/sales/src/Services/ProcurementRequester.php` and source-line mutation hooks; owner snapshot model/migration under `plugins/webkul/sales`; Logistics loading snapshot mapping; existing `plugins/webkul/support/src/Models/UOM.php` / `plugins/webkul/products/src/Models/Packaging.php` as patterns; new `plugins/webkul/sales/tests/Feature/Workflows/QuantityCommitmentTest.php` and `plugins/shafique/logistics/tests/Feature/PackingSnapshotTest.php`.

**Approach:** implement KTD5 at confirmation and relevant confirmed-line revisions. Keep legacy provenance unknown when absent. Freeze child contents rather than relying on current nested definitions.

**Test scenarios:**

1. Covers AE3: 6×12 commitment survives a later 6×18 master change.
2. Equivalent outer and inner presentations count contents/volume only at the intended level.
3. Wrong-category UoM, unsupported fractional pack, or missing required lot blocks the affected action.
4. Loading variance preserves original commitment and records a separately approved actual breakdown.

**Verification:** native quantities, rounding, and historical provenance reconcile without a generic hierarchy engine.

### U7. Own protected evidence bytes and associations

**Goal:** retain usable evidence across rollback, access changes, and shared attachment deletion.  
**Requirements:** R6. **Dependencies:** U2; Q7.

**Files:** new `plugins/shafique/logistics/src/Models/Evidence.php`, `plugins/shafique/logistics/src/Services/EvidenceStore.php`, evidence migration and storage configuration; new `plugins/shafique/logistics/tests/Feature/EvidenceRetentionTest.php`.

**Approach:** implement KTD7; validate uploads at the trust boundary and use private authorized retrieval. Audit Chatter links without sharing destructive ownership. Retention applies to objects and associations.

**Test scenarios:**

1. Covers AE16: failed SQL association leaves only an identifiable unreferenced object.
2. Missing or mismatched bytes cannot support an accepted event.
3. Cross-company download, unsafe upload, and unauthorized deletion fail.
4. Chatter deletion and backup restoration preserve the accepted original bytes and associations.

**Verification:** evidence can be restored and accessed by permitted actors; a hash-only record is not accepted as proof of restoration.

### U8. Add scoped command authority and suspension enforcement

**Goal:** make consequential Logistics commands safe under retries, stale approvals, and concurrent suspension.  
**Requirements:** R3–R5/R17. **Dependencies:** U2/U7; Q2/Q9.

**Files:** extend `plugins/shafique/logistics/src/Services/OperationalControl.php`; new `plugins/shafique/logistics/src/Services/CommandRecorder.php`, `plugins/shafique/logistics/src/Services/ApprovalControl.php`, command/approval models and migrations, command-specific policies; new `plugins/shafique/logistics/tests/Feature/CommandAuthorizationTest.php` and `plugins/shafique/logistics/tests/Feature/SuspensionConcurrencyTest.php`.

**Approach:** implement KTD6 and serialize the activity decision with the existing Company/control change boundary. Adopt Q9's persisted eligibility cutoff; an old trip alone is not continuing authority. No generic workflow engine.

**Test scenarios:**

1. Same key/payload returns one permitted result; changed payload conflicts.
2. Revoked permission or a cross-company key cannot retrieve the saved result.
3. Covers AE15: suspension racing a new claim blocks whichever action loses the approved serialization boundary.
4. Harmless notes retain applicable approvals; material cargo/payee changes invalidate only their scopes.
5. Recording an incident remains possible while release is blocked.

**Verification:** direct service and UI entry points enforce the same rules and leave rejected commands without effects.

### U9. Implement source claims and native remainder mapping

**Goal:** allocate eligible demand without a shadow stock ledger.  
**Requirements:** R7/R8. **Dependencies:** U4/U6/U8; Q3.

**Files:** new `plugins/shafique/logistics/src/Models/DemandAllocation.php`, `plugins/shafique/logistics/src/Services/DemandAllocator.php`, native enrollment/revision listeners and allocation migration; new `plugins/shafique/logistics/tests/Feature/AllocationConcurrencyTest.php` and `plugins/shafique/logistics/tests/Feature/NativeLineageTest.php`.

**Approach:** implement active claim transitions against the shared source authority. Retire posted claims from availability deductions and remap native backorders. Block enrolled native execution without matching release context; ordinary native work remains independent.

**Test scenarios:**

1. Covers AE1/AE2, including cancel/reallocate and native source reduction.
2. A native completion/cancel/quantity edit through another surface cannot silently violate an enrolled claim.
3. Retrying a remap creates no duplicate successor claim.
4. An uncertain execution retains protection until canonical reconciliation.

**Verification:** fresh native and Logistics reads agree on demand, active claims, and historical consumption under contention.

### U10. Build booking and actual-load workflow

**Goal:** give staff one practical workspace for source-linked plans, service terms, and verified actuals.  
**Requirements:** R10–R14/R27. **Dependencies:** U9; Q4/Q6.

**Files:** new trip/stop/booking/load models and migrations under `plugins/shafique/logistics`; `plugins/shafique/logistics/src/Services/BookingWorkflow.php`; `plugins/shafique/logistics/src/Filament/Resources/TripResource.php` and supporting pages; new `plugins/shafique/logistics/tests/Feature/BookingWorkflowTest.php` and `plugins/shafique/logistics/tests/Feature/LoadingTest.php`.

**Approach:** use native Partner/product/company references and historical assignment snapshots. Start the UI with one operation while retaining many stops/source lines. Use manual approved pricing and actual equipment checks.

**Test scenarios:**

1. Call-only owner-driver booking records verbal read-back with attributed staff entry.
2. A smaller replacement vehicle forces relevant reinspection without changing agreed fixed freight silently.
3. Unknown dimensions stay unknown; a known unsafe load cannot use a generic override.
4. Partial loading keeps native remainder and records actual pack/lot variance.

**Verification:** staff can prepare the engineering slice without mandatory zones, appointments, portal accounts, or false fit precision.

### U11. Release the verified native cargo and record departure

**Goal:** connect verified loading to one native result and one controlled physical departure.  
**Requirements:** R4/R5/R15–R17. **Dependencies:** U5/U10.

**Files:** new `plugins/shafique/logistics/src/Services/ReleaseWorkflow.php`, release batch/result models and migration, gate actions; existing Inventory hook from U4; new `plugins/shafique/logistics/tests/Feature/ReleaseIntegrityTest.php` and `plugins/shafique/logistics/tests/Feature/GateAuthorizationConcurrencyTest.php`.

**Approach:** implement KTD2 using the U4-proven boundary and KTD3 locks. Persist the exact selected operation set and canonical results; acquire no human/remote waits inside the transaction. Recover a lost response by canonical command lookup.

**Test scenarios:**

1. Covers AE4/AE5, including current holds, repeated release, and competing revisions.
2. Unselected operation lines remain unposted.
3. Posting succeeded but response was lost: retry returns the original result and no duplicate effect.
4. Covers AE12: onsite abort blocks release until correction; unauthorized actual departure remains an incident.

**Verification:** native stock/result/claim evidence agrees and gate authority cannot conceal partial, unknown, stale, or revoked execution.

### U12. Reconcile receiving observations into bounded events

**Goal:** keep low-tech reports usable without duplicate or fabricated fulfillment.  
**Requirements:** R18/R19/R27. **Dependencies:** U7/U8/U11; Q2/Q6/Q10.

**Files:** new observation/receiving-event/allocation models; `plugins/shafique/logistics/src/Services/ReceiptReconciler.php`; receiving actions; new `plugins/shafique/logistics/tests/Feature/ReceiptReconciliationTest.php` and `plugins/shafique/logistics/tests/Feature/ReceiptConcurrencyTest.php`.

**Approach:** implement KTD8 with explicit input intention, canonical event identity or cumulative scope, and source attribution. A cumulative attestation records its covered sources, cutoff, accepted total, reconciled prior allocations and verified difference under the same allocation locks. Several undocumented drops need no fictional single event. Later event-level evidence refines the covered allocations rather than adding them again; incompatible or ambiguous coverage remains owned reconciliation work. Permit a verified physical observation whose commercial attribution is unresolved. External GRN execution remains Q12-gated.

**Test scenarios:**

1. Covers AE6/AE7/AE13; stale cumulative deltas require review, and later evidence for several drops already covered by one attestation cannot add acceptance twice.
2. Two real partial receipts of 20 remain distinguishable from duplicate reports of one receipt.
3. Overage/unclear reports are retained without consuming excess cohort quantities.
4. Concurrent acceptance/return/loss cannot consume the same remaining portion.
5. Driver report, verified shortage, and accepted-damaged goods remain different valid facts.

**Verification:** observations remain intact, confirmed allocations reconcile, and receiving never reissues stock.

### U13. Preserve custody and controlled correction

**Goal:** resolve breakdowns, refusals, returns, loss, recovery, and dependent corrections without rewriting history.  
**Requirements:** R20–R22/R28. **Dependencies:** U5/U12; Q4/Q10.

**Files:** new `plugins/shafique/logistics/src/Services/CargoWorkflow.php`, `plugins/shafique/logistics/src/Services/CorrectionWorkflow.php`, cargo/outcome/exception models and migrations; new `plugins/shafique/logistics/tests/Feature/CargoConservationTest.php` and `plugins/shafique/logistics/tests/Feature/CorrectionDependenciesTest.php`.

**Approach:** create portions only when a real split requires them. Present physical, native-stock, and financial correction progress separately. Reassign work to an accountable role when a user becomes inactive.

**Test scenarios:**

1. Covers AE8/AE9 with leaf-only aggregation and no duplicate stock issue.
2. A correction that would exceed the reversible original quantity is rejected.
3. Correcting a paid/credited/returned receipt retains downstream reconciliation tasks.
4. Wrong-distributor delivery records custody without fulfilling the intended order.
5. Quarantined return remains unavailable until its native quality disposition allows release.

**Verification:** every dispatched portion has an explainable effective outcome or owned outstanding state.

### U14. Restore the existing Recon regression boundary

**Goal:** prevent Logistics money work from relying on a known failing accounting fixture boundary.  
**Requirements:** R1/R23/R24. **Dependencies:** U1.

**Files:** existing `tests/Feature/Recon/MatchAccountingIntegrationTest.php`, `tests/Feature/Recon/AutoMatchAtomicityTest.php`, `tests/Feature/Recon/MatchConcurrencyTest.php`, and `tests/Feature/Recon/ReconTestSupport.php`.

**Approach:** diagnose the recorded same-direction fixture/expectation mismatch. Correct synthetic debit/credit semantics first. A production change requires an independently reproduced accounting defect; do not weaken controls.

**Test scenarios:**

1. Correct-direction fixtures reach their intended match/residual assertions.
2. Late failure reaches the intended injection point and rolls back.
3. Two independent match workers establish the expected one-success/one-conflict result with stored events/residuals.

**Verification:** the selected existing integration, control, atomicity and concurrency boundary passes without relaxed accounting invariants.

### U15. Qualify Accounts advance and payment execution

**Goal:** protect native money effects and prove the real pre-bill/recipient contract.  
**Requirements:** R23–R25. **Dependencies:** U14; Q5.

**Files:** existing `plugins/webkul/accounts/src/Services/PaymentRegistrar.php`, `plugins/webkul/accounts/src/Services/Reconciler.php`, `plugins/webkul/accounts/src/Services/MoveWorkflow.php`, `plugins/webkul/accounts/src/Models/Payment.php`, `plugins/webkul/accounts/src/Models/PaymentRegister.php`, `plugins/webkul/accounts/src/Http/Requests/MovePaymentRequest.php` and native API/UI callers; owner-local idempotency/instruction schema if required; new `plugins/webkul/accounts/tests/Feature/Workflows/PaymentConcurrencyTest.php`, `plugins/webkul/accounts/tests/Feature/Workflows/SupplierAdvanceTest.php` and `plugins/webkul/accounts/tests/Feature/Workflows/PaymentRecipientTest.php`.

**Approach:** follow KTD9. Lock the actual obligation/advance authority and include required database listeners in its transaction. Use a real separate supplier/recipient fixture; preserve the supplier counterparty. Unknown manual or external execution requires evidence and reconciliation.

**Patterns:** existing `plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php`, `plugins/webkul/accounts/tests/Feature/Workflows/PaymentRegisterBankAccountTest.php` and company/currency guard suites.

**Test scenarios:**

1. Advance occurs before final bill, later applies once, and can be refunded/reallocated under approved policy.
2. Same-key replay and changed-payload conflict; competing 60+60 payment intents against 100 cannot both consume 60.
3. Wrong-company bank/journal/method and unauthorized recipient changes fail.
4. Late posting/reconciliation/listener failure leaves no partial payment.
5. Covers AE11; pending execution blocks blind replacement without pretending money is applied.

**Verification:** persisted native entries, reconciliations, residuals, payee evidence, and reversal references prove the approved contract on supported engines.

### U16. Link freight agreements and settlement evidence

**Goal:** explain operational freight and every native financial allocation.  
**Requirements:** R14/R23–R26. **Dependencies:** U10/U15; Q5.

**Files:** new `plugins/shafique/logistics/src/Services/FreightWorkflow.php`, charge/financial-allocation models and migrations, freight actions; new `plugins/shafique/logistics/tests/Feature/FreightSettlementTest.php` and `plugins/shafique/logistics/tests/Feature/FreightAllocationTest.php`.

**Approach:** match charges to native bill lines/advances/payments at amount level. Keep negotiated fixed amounts, approved extras, pending instructions, scoped holds, disputes and claims distinct. Native manual execution is acceptable only through the approved controlled and linked process.

**Test scenarios:**

1. Covers AE10, including different supplier/recipient and distributor-side accounting linkage.
2. One bill covers several trips and one trip several charges/payments without duplicate allocation.
3. Holds do not erase liability; overlapping holds or applied offsets are not deducted twice.
4. Currency rounding preserves total charge; zero/unknown allocation basis creates owned unallocated work.
5. Cancellation retains recoverable advance; no automatic transfer to replacement provider.

**Verification:** a reviewer can explain agreement, liability, instruction, payment, allocation and residual independently from native records.

### U17. Complete operator workspaces, documents, and reports

**Goal:** make the control loop usable without exposing the conceptual model as dozens of forms.  
**Requirements:** R27/R28/R31. **Dependencies:** U12/U13/U16; Q8.

**Files:** Logistics Filament resources/pages under `plugins/shafique/logistics/src/Filament`; document views under `plugins/shafique/logistics/resources/views` and language files under `plugins/shafique/logistics/resources/lang`; new `plugins/shafique/logistics/src/Queries/OperationalQueue.php`; new `plugins/shafique/logistics/tests/Feature/OperationalWorkspaceTest.php` and `plugins/shafique/logistics/tests/Feature/DocumentAccessTest.php`.

**Approach:** implement KTD11. Show meaningful owner/next-action queues and separate operational versus financial/evidence closure. Printed copies identify the revision; reprints do not create authority. Reports retain as-of basis and denominator definitions.

**Test scenarios:**

1. Unauthorized search/export/document access cannot leak another company.
2. Reprinting a superseded pass cannot authorize a changed load.
3. Inactive assignee work remains in the responsible queue; note edits do not reset incident age.
4. Terminal availability and distributor acceptance produce different service metrics.
5. Keyboard/basic-phone-assisted workflows complete with actionable validation messages.

**Verification:** role-based browser walkthroughs and realistic-volume query evidence meet Q8/Q9; no unapproved numerical SLO is claimed.

### U18. Complete all transport modes

**Goal:** extend the engineering slice to every mode required at the first live pilot.  
**Requirements:** R11/R12/R14/R20/R30. **Dependencies:** U11–U13/U16; Q6.

**Files:** extend trip/stop/release services; new `plugins/shafique/logistics/src/Services/ConsignmentWorkflow.php` and carrier/pickup associations where needed; new `plugins/shafique/logistics/tests/Feature/MultiStopReleaseTest.php`, `plugins/shafique/logistics/tests/Feature/CarrierConsignmentTest.php` and `plugins/shafique/logistics/tests/Feature/DistributorPickupTest.php`.

**Approach:** preserve the settled all-mode scope. Carrier booking and consignment are distinct from optional known vehicle movements. Retain issuer-scoped bilty references and native lineage through feeder/final-mile handovers.

**Test scenarios:**

1. Multi-operation/multi-stop load passes AE4 and accounts for rejected cargo or return pickup on later segments.
2. Carrier acceptance/terminal arrival does not imply distributor receipt; linked feeder service does not issue stock twice.
3. Same bilty number from unrelated carriers does not collide; ambiguous same-issuer references require review.
4. Covers AE14: authorized distributor collector can complete pickup with the approved custody/receipt responsibility and actual freight obligation.
5. Provider replacement and partial consignment transfer retain old terms, cargo and charge history.

**Verification:** every mode completes loading, receiving, freight where applicable, and recovery acceptance. No mode is deferred to make the first pilot appear ready.

### U19. Prepare opening adoption, outage, and restore recovery

**Goal:** make transition into and recovery of the module safe for goods and money already in flight.  
**Requirements:** R3/R6/R24/R29. **Dependencies:** U17/U18; Q7/Q9.

**Files:** new `plugins/shafique/logistics/src/Services/OpeningAdoption.php` and `plugins/shafique/logistics/src/Services/RecoveryCoordinator.php` only for the required native import/reconciliation paths; new `plugins/shafique/logistics/tests/Feature/OpeningAdoptionTest.php` and `plugins/shafique/logistics/tests/Feature/RecoveryReplayTest.php`; new `docs/logistics/CUTOVER-AND-RECOVERY.md` during implementation.

**Approach:** import stable native references and reconciled opening balances with explicit unknowns; fence duplicate-sensitive outgoing work during recovery. Define numbered fallback, controlled staging and catch-up authority. Rehearse restoration of SQL and protected bytes together.

**Test scenarios:**

1. Reimporting an in-transit shipment/advance creates no second posting or financial deduction.
2. Old backup versus later successful external/manual payout yields reconciliation, not a new payment.
3. Unknown online issue plus paper departure remains protected until one canonical result is established.
4. Missing evidence bytes and stale worker ownership prevent unsafe resume.
5. Code rollback preserves actual goods/money history and supports forward correction.

**Verification:** timed rehearsal meets approved recovery targets and leaves no unexplained opening or replay effect.

### U20. Qualify the all-mode pilot

**Goal:** obtain operational acceptance for the whole enabled control loop.  
**Requirements:** R30/R31. **Dependencies:** U19; Q1–Q10 closed.

**Files:** new `plugins/shafique/logistics/tests/Feature/OutboundControlLoopTest.php`; maintained evidence in `docs/logistics/TEST-BASELINE.md`; new `docs/logistics/PILOT-ACCEPTANCE.md` during implementation.

**Approach:** execute representative all-mode scenarios on approved isolated/staging infrastructure, then obtain permission for the scoped live cohort. Record sample mix, named owners, workload, exceptions and reconciliations. No blanket “all tests pass” claim substitutes for applicable evidence.

**Test scenarios:**

1. Each transport mode completes a normal and a relevant failure/recovery journey.
2. Basic-phone roles and backup coordinator complete follow-up without unavailable technology.
3. Native quantities, receipts, advances, bills/payments and evidence reconcile after controlled failures.
4. Cross-company, stale approval, duplicate intent, and unknown-outcome cases fail safely.

**Verification:** the launch matrix below is satisfied; business owners accept observed results and separately authorize live activation.

---

## Verification Contract

Verification is future implementation work. Source-pack models are examples to translate into repository tests, not a second application test framework to expand. Pest parallel suite execution does not prove two commands safely contending for one resource; see [Pest parallel testing](https://pestphp.com/docs/optimizing-tests#parallel-testing).

| Gate | Evidence required | Applicability |
| --- | --- | --- |
| V1 Isolated baseline | Proven disposable target/storage/communications; current selected regression results with exact revision/diff and fixture identity. | All implementation testing. |
| V2 Owner regressions | Native persisted-state assertions, outer rollback and critical listener failures; actual API/Filament/Barcode/direct-service entry points. | U3–U6/U9/U11/U14/U15. |
| V3 Concurrency and engine | Independently bootstrapped workers share committed synthetic records and synchronize contention; fresh-connection assertions; PostgreSQL/MySQL separately. | Quant, claims, transitions, returns, gate/revocation, receipt allocations, payment/residual changes. |
| V4 Access and evidence | Wrong-company parent/child IDs, revoked permission during retry retrieval, unauthorized upload/export/download, preserved bytes/lifecycle. | Every new surface; U2/U7/U8/U17. |
| V5 All-mode end-to-end | Dedicated/multi-stop/carrier/pickup normal and failure stories with native source results and actual-role walkthroughs. | U18/U20. |
| V6 Financial proof | Pre-bill advance, later allocation, separate supplier/recipient, distributor-paid case, scoped holds, concurrent payout, reversal. | U15/U16 and every pilot transaction with money obligations. |
| V7 Recovery/cutover | Opening-data idempotency, issued-onsite abort, paper catch-up, evidence restore, unknown payment/replay and worker fencing where applicable. | U5/U19/U20. |
| V8 Usability/performance | Call-only and keyboard flow, backup-owner handover, queue age, coordinator effort, paginated query behavior at approved volumes. | U17/U20; Q8/Q9 targets. |
| V9 Delivery quality | Relevant owner/plugin suites, required formatter for PHP changes, documentation updated, no abandoned code or unrelated edits. | Each unit. |

Test evidence records the engine/version, code revision plus local diff identity, exact test invocation, result, fixture scope, and exclusions. Use the existing isolated runner and native Pest conventions. Do not infer permission to bootstrap from a filename alone. Broaden test coverage when shared behavior changes, not merely to inflate test counts.

### Pilot launch matrix

| Area | Required all-mode evidence | No-go condition |
| --- | --- | --- |
| Scope/people | Approved sites, providers/distributors, mode responsibilities, named owner/backup and representative sample mix. | A mode is enabled without its collector/endpoint/receipt responsibility or no one owns recovery. |
| Goods | Exact conversion/source identity, safe native issue, remainder and cargo conservation, quality constraints. | Guessed conversion/lot, over-allocation, partial/unknown issue treated as released, or saleable return inferred from custody. |
| Physical execution | Current revision authority, material change/revocation race, onsite abort and unauthorized incident recording. | Stale paper authorizes exit or onsite correction is unproven. |
| Receiving | Canonical event matching, cumulative interpretation, bounded allocations, conflicts and pending attribution. | Duplicate acceptance, invented source attribution, or driver report automatically closes receipt. |
| Money | Approved Accounts contracts and controlled native linkage for every actual advance, liability, instruction, settlement and correction. | Dispatch-only software conceals untracked cash, unsupported recipient, duplicate payout or unexplained residual. |
| Evidence/access | Private authorized retrieval, protected byte restoration, suspension and dependency removal qualification. | Missing accepted evidence or cross-company/support bypass. |
| Recovery | Approved outage authority, coordinated opening adoption and replay/restore rehearsal. | Restored workers can repeat effects without lookup, or physical/financial opening position is unexplained. |
| Capacity to operate | Approved workload, response and recovery targets achieved on representative workloads. | Backlog/queue growth exceeds approved capacity or no backup coordinator can complete the work. |

---

## Definition of Done

A unit is done when its cited requirements, decision gates, named test scenarios, persistence effects, relevant entry points, and documentation are verified. Source inspection alone is not execution proof. Abandoned experimental code and test artifacts are removed without deleting unrelated work.

The first live pilot is ready only when:

1. Q1–Q10 have recorded owner decisions and evidence; the technical structural gates are resolved.
2. All R12 modes pass V1–V9 as applicable, including recovery and real freight/advance semantics.
3. Native demand, issued quantities, effective cargo outcomes, financial records and protected evidence reconcile.
4. No known critical duplicate quantity/payment, unauthorized access, stale release, or unknown-outcome bypass remains.
5. Operating staff and their backup demonstrate the call-only workflow and accept actual workload.
6. Cutover/restore/outage rehearsal is complete, and accountable owners authorize the limited live cohort.
7. Relevant repository documentation reflects implemented behavior and remaining deferred capabilities.
8. The implementation preserves unrelated work and follows explicit commit/push/production authorization.

**First recommended work:** U1 plus review of U2, while accountable owners resolve Q1–Q10 in dependency order. Inventory Q3 and Accounts Q5 have the highest structural risk and should receive early evidence. Full implementation readiness cannot be declared merely by approving this document's wording.

---

## Appendix

### Source requirement disposition

These mappings preserve source intent while consolidating wording. A's FR/NFR numbers and B's REQ numbers retain their original meanings; this plan's R/U IDs are separate. “Deferred” means retained for a later justified capability, not silently deleted. The mapping was checked against both supplied registries: all 89 A requirements and all 114 B requirements have a disposition, with no unknown requirement IDs.

| Source requirement family | Consolidated owner | Disposition |
| --- | --- | --- |
| A FR-001–008, 072; B REQ-PLN-001–009 | R7/R8/R9; U6/U9/U10/U11 | Retain demand, commitment, splits, human planning, and lineage. |
| A FR-009–016, 054, 074; B REQ-BKG-001–008 | R12–R14/R23/R27; U10/U16/U18 | Retain parties, booking/service, revisions and billing cardinalities. |
| A FR-017–024; B REQ-PKG-001–010 | R9/R10; U6/U10 | Adapt to native references and immutable breakdowns; open/mixed execution Q1-gated, general authoring deferred. |
| A FR-025–029; B REQ-VEH-001–008 | R11/R22; U10/U13/U18 | Retain actual equipment, segment capacity, safety and uncertainty. |
| A FR-030–033; B REQ-LOC-001–007 | R12/R14/R32; U10/U18 | Retain physical destinations/known routes/endpoints; automatic zone/rate/appointment machinery deferred under Q11. |
| A FR-034–039, 071; B REQ-EXE-001–004/008/009, REQ-CTL-009 | R4/R5/R15–R17; U4/U8/U11/U18 | Retain guarded release, real events, documents and onsite recovery. |
| A FR-040–043; B REQ-USR-001–008, REQ-EXE-005 | R17/R27/R28; U10/U12/U17 | Retain assisted, safe communication and provenance; optional digital channels Q12-gated. |
| A FR-044–049, 053, 073; B REQ-RCV-001–009, REQ-EXE-006/007 | R18–R22; U5/U12/U13/U18 | Retain receipt/condition/custody/return semantics; external GRN writes deferred under Q12. |
| A FR-050–052; B REQ-RCV-010/011, REQ-PLN-010 | R19–R21/R28; U12/U13/U17 | Retain canonical events, pending attribution, independent closure and bounded correction. |
| B REQ-EXE-010/011 | R17/R22; U13/U18 | Retain quality containment and wrong-recipient evidence; automatic in-transit commercial reassignment remains prohibited until approved. |
| A FR-055–062, 075; B REQ-FRT-001–008/010/011 | R23–R26; U14–U16 | Retain approved freight, advances, recipients, payer, claims/holds, pending money and exact allocations. |
| B REQ-FRT-009 | R32; Q11 | Defer automatic rates; explicit negotiated pilot bases retained. |
| A FR-063–066; B REQ-CTL-001–008, REQ-INT-001–004/008 | R1–R6/R15/R28; U2/U7/U8/U11/U15/U17 | Retain security/lifecycle/command guarantees; adapt generic integration to native services. |
| A FR-067/068; B REQ-RPT-001–007 | R28/R31/R32; U17/U20 | Retain honest operational/cost metrics and actionable queues; optional channels deferred. |
| A FR-069 | R23/R25 and product boundary | Product collection is separate from freight; no new cash-collection product in this plan. |
| A FR-070/076/077; B REQ-INT-005–007/009 | R3/R6/R29; U19/U20 | Retain continuity, opening adoption, owned recovery and replay checks. |
| A NFR-001/005/007/008; B REQ-QLT-004 | R4/R6/R29/R32; KTD1–KTD10 | Retain atomic/recoverable commands, time/provenance and maintainability. |
| A NFR-002/004/010/011; B REQ-QLT-001 | R2–R6/R27/R32; U2/U7/U8/U17 | Retain access, safe uploads, accessibility and controlled configuration/automation. |
| A NFR-003/006/009/012; B REQ-QLT-002/003/005 | R29–R31; U1/U17/U19/U20 | Retain isolated proof, approved performance/recovery and all-mode pilot evidence. |

### Distinctive scenario trace

| Consolidated examples | Plan A scenarios | Plan B scenarios / requirements |
| --- | --- | --- |
| AE1/AE2 | SC-012/013/018/064 | SCN-08/09/41; REQ-PLN-003/009 |
| AE3 | SC-003/005; FR-018/023 | SCN-03/51; REQ-PKG-010 |
| AE4/AE5 | SC-061–065/080/082 | SCN-43/44/59 |
| AE6/AE7/AE13 | SC-066/067 | SCN-46/47/50 |
| AE8/AE9 | SC-050/069/070 | SCN-19/48/52 |
| AE10/AE11 | SC-020/033/036/039/074/079 | SCN-26/38/53/54/58 |
| AE12 | SC-026/062 (adjacent posting/custody rules) | SCN-44 |
| AE14 | SC-021/051/057 | SCN-13/14; REQ-LOC-005/EXE-004 |
| AE15/AE16 | SC-040/042/060 | SCN-32/36/60; REQ-INT-005/CTL-004 |

Scenario references identify source examples, not evidence that these acceptance examples have passed. Unit tests add finer-grained native/caller coverage beyond either model.

### Source provenance

| Supplied source locator | SHA-256 |
| --- | --- |
| A `MANIFEST.json` | `9342b155cc91a91ef3e78bd22b51461f67cfaf6e896501c9c845131be8276431` |
| A `registry/requirements.json` | `f44b63010713fbfefab831ada889ac60e4b18a7feff5a8e8520237c00eeff507` |
| B `project-manifest.json` | `4dd89022ffeb0f7a8bb70d02f4a5ee46588f5f54015ad127d7ffe84d8707b547` |
| B `registers/requirements.json` | `531ccaf8fe6907b123510bebc8ae02b6dcfd64775a3f235cc23e25fd82c4f921` |

Hashes identify the inputs read; they are not a claim that either pack's validator or specification tests were executed. The supplied packs and stashed repository plan were not modified.
