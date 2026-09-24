---
title: Logistics Decision Register
version: 1.1.0
date: 2026-09-20
---

# Logistics Decision Register

## Decision rules

An approved decision records accountable owner, evidence, approved rule, effective date/version, affected scope, superseded rule and review trigger. A meeting, recommendation, current code path or test fixture is not approval. Open mandatory decisions block only affected phases; Q1-Q10 and applicable Q13 must close before the all-mode pilot. Q11/Q12 are deferred because approved manual/internal paths exist.

## Settled decisions

| ID | Decision |
| --- | --- |
| S1 | Authority order is business reality, approved decisions, this pack, approved contracts/specifications, then existing AureusERP. |
| S2 | Extend the modular monolith, but refactor native owners where their implementation is wrong. |
| S3 | No shadow Inventory or Accounts ledger in Logistics. |
| S4 | All transport modes are included in the first live pilot. |
| S5 | Basic-phone and staff-assisted operation is mandatory; a driver app is not. |
| S6 | Engineering slices may be narrower than the live pilot. |
| S7 | Optional automation and external adapters are deferred until separately justified. |
| S8 | Documentation/reference-model results are not implementation or production evidence. |

## Open and deferred decisions

Each row states the business question, the current recommendation, the accountable participants and the implementation phases that cannot proceed without a decision. Terms such as approval, authority and reconciliation use the definitions in [`README.md`](README.md#terms-used-throughout-the-plan).

| ID | Decision and recommendation | Status | Owner/evidence | Blocks |
| --- | --- | --- | --- | --- |
| Q1 | **Quantity Units and Packaging Rules.** Decide which unit Inventory uses for stock, when the customer-facing quantity and packaging become committed, which whole, open, fractional or mixed packages are permitted, and how conversion and rounding work. Record the approved commercial commitment and the actual loaded configuration as immutable snapshots. Block only the affected operation when required conversion evidence is missing; do not interpret current master data as historical fact. | Open | Product, Sales and warehouse owners. Review representative orders and packages before and after master-data changes. | P3, P8 |
| Q2 | **Operational Roles, Approvals and Telephone Verification.** Approve the role and monetary or quantity thresholds for planning, loading, release, receipt verification, correction and payment. Define when one person may enter information for another and when an independent reviewer is required. Consequential telephone instructions must be confirmed through a trusted callback or read-back method; contact details supplied by the caller cannot establish identity by themselves. | Open | Operations, Accounts and security owners. Review the role matrix and call-only exercises. | P2-P6, P8 |
| Q3 | **Inventory Ownership and Stock Update Rules.** The business owners approve the stock-balance identity dimensions, partial-dispatch remainder and backorder meaning, permitted return quantities, and correction used when stock was issued but never left the site. Technical maintainers must then prove how empty optional identity fields compare, the uniqueness strategy, affected records, deterministic lock order, transaction participation, coverage of every stock writer, and correct behavior on PostgreSQL. The technical design implements the approved business meaning and cannot redefine it. Inventory services remain responsible for stock changes; Logistics may enforce a narrow precondition for cargo that it has claimed or frozen. | Open | Inventory and Sales business owners approve policy. Inventory technical maintainers provide duplicate-data preflight, caller and lock maps, PostgreSQL transaction tests and correction examples. | P1A, P3, P4, P8 |
| Q4 | **Load Safety, Product Quality, Returns and Loss Recovery.** Approve measurement tolerances, capacity limits, product, lot and expiry restrictions, quality holds, quarantine, returns, loss and recovered-goods disposition. Missing measurements do not prove that a load is safe or within capacity. A known prohibition cannot be bypassed by a general override. | Open | Warehouse and quality owners. Review measured loads and disposition procedures. | P1A, P3, P4, P6, P8 |
| Q5 | **Freight Charges, Advances and Accounting Treatment.** Approve freight pricing bases, recognition and allocation of advances, supplier documents, tax, withholding, rounding, payment recipient, payer, final cost bearer, distributor-paid freight, holds, offsets and claims. Accounts must create and reconcile all financial effects. Logistics records the agreement and links to Accounts records; it must not deduct shortages automatically or maintain a second payable or payment balance. | Open | Accounts owners. Review native pre-bill, separate-recipient and third-party-payer examples. | P1B, P5, P8 |
| Q6 | **Responsibilities by Transport and Delivery Mode.** For dedicated vehicles, multi-stop delivery, carrier consignments and distributor pickup, approve the service endpoints, authorized collector, payer, final cost bearer, custody at each handover, meaning of a carrier terminal milestone, attribution of combined-order receipts and responsibility for redirected goods. A signed mode matrix is required. Delivery to the wrong recipient does not fulfill the original sale automatically. | Open | Operations, Sales and distributor representatives. Complete one real walkthrough for every mode. | P3, P4, P6, P8 |
| Q7 | **Evidence, Privacy and Retention.** Approve the evidence types that may be collected, the minimum information required, who may view or export each type, retention and deletion periods, legal holds, backup protection and restoration checks. Accepted evidence must use protected private storage whose bytes cannot be changed without detection while retention is required. Approved disposal must preserve an immutable tombstone with identity, hash, metadata, authorization, disposal time and outcome. Identity documents and audio must not be collected by default. | Open | Data and operations owners. Approve the retention schedule and review backup, restore, legal-hold and disposal samples. | P2, P7, P8 |
| Q8 | **Operational Documents, Languages and Waiting-Time Rules.** Approve document names and numbering, revision and reprint labels, required spoken and written languages, telephone read-back wording, appointment rules and waiting-time terms. The pilot must support paper and basic-phone operation. Printing or reprinting a document does not create or renew permission to release goods. | Open | Dispatch and Accounts owners. Review real paper samples and role walkthroughs. | P6, P8 |
| Q9 | **Operating Limits, Recovery Targets and Pilot Measures.** Approve the pilot cohort, suspension cutoff, outage responsibilities, volume and concurrency limits, attachment limits, queue response targets, recovery-point and recovery-time objectives, representative repeated sample by transport mode, hypercare coverage, and service measures for commitments, departure, delivery windows, receipt confirmation and exception age. There is no implicit emergency override. Set thresholds from observed operating data. | Open | Sponsor, operations and technical owners. Review baseline measures, expected volumes, restore exercises and backup-owner drills. | P2 cutoff, P6-P8 |
| Q10 | **Operational Closure, Financial Closure and Correction Responsibility.** Approve the separate conditions for operational closure and financial closure, who resolves conflicting evidence, how recovered losses and already paid or credited transactions are corrected, how work is reassigned when an owner becomes inactive, and who may change the governing policy. The system must not mark a trip or financial obligation finally closed merely because one workflow completed. | Open | Operations, Accounts and quality owners. Review shortage, recovery and paid-correction exercises. | P4, P7, P8 |
| Q11 | **Routing, Rating and Capacity Automation.** Consider automated zone precedence, freight rates, route selection, dock scheduling and capacity planning only after stable operating rules and a measured need exist. The pilot uses approved manual planning and pricing. | Deferred | Operations owners. Provide stable rules and evidence of operational benefit. | Post-pilot only |
| Q12 | **External Systems and Communication Channels.** Approve writes to an external goods-receipt system, carrier or receiver portals, messaging, location services and any remote create, lookup, reversal or replay behavior only after a specific system is selected and its capabilities are verified. The pilot uses the internal staff-assisted workflow unless a separate adapter approval is completed. | Deferred | Owners of the AureusERP module and selected external system. Complete the capability worksheet and sandbox proof. | Adapter writes only |
| Q13 | **Regulated Food Transport Requirements.** Determine whether any pilot product requires temperature monitoring, regulated transport conditions, recall notifications or controls beyond ordinary lot, expiry and quality holds. Exclude an affected product from the pilot until those controls are approved and tested. | Open | Quality and compliance owners. Review product classifications and applicable legal and customer requirements. | Affected P3-P8 scope |

## Factory planning evidence requiring decisions

The [workbook review](../FACTORY-STOCK-PLANNING-WORKBOOK-REVIEW.md) informs existing decisions; it does not close them or create approved policy. Questions about current practice explain its purpose and constraints. Owners must approve the appropriate future rule, which may replace the manual process. Understanding the exact meaning of Seq is needed only if retained or migrated, not as a prerequisite to designing independent source identities.

- **Q3, with Sales, Inventory and the dispatch planner:** Confirm why orders enter the current Requirement set, how partial or already dispatched quantities are treated, and what Seq means. The formulas establish row-based inclusion, not a date, shift, vehicle-confirmation or management-selection rule. Record explicit selected quantities and a planning window in the target design. This blocks the affected P3 planning contract and P8 acceptance.
- **Q2 and Q6, with Operations and warehouse owners:** Approve who selects each shortage recovery option, the conditions for transfers and dual-factory collection, and whether complete loading-factory reassignment is permitted. Confirm factory-to-Company mapping; the workbook does not approve cross-company trips. This blocks the affected P3/P6 workflows and P8 acceptance.
- **Q1, Q3 and Q4, with production and warehouse owners:** Confirm production-rate units, duration, rounding and when projected output becomes physically available and quality-released. This blocks the affected P3 readiness calculation and P8 acceptance.

## Stock-readiness target rules for owner review

Status on 2026-09-24: **proposed, not approved**. The [target workflow](03-WORKFLOWS-AND-LIFECYCLES.md#stock-readiness-before-loading-proposed-target-for-review) makes these choices reviewable. They refine existing Q decisions rather than create another decision register. Approval must identify a named accountable owner; role names below are proposed participants, not confirmed assignments.

| Choice | Recommended target rule | Confirmation needed and affected work |
| --- | --- | --- |
| Quantity units — Q1 | Use the product's approved Inventory base unit for arithmetic; preserve commercial pack quantities with versioned conversions and approved precision. Bundle is a display unit only where its conversion is known. Reject unsupported fractional packs. | Product/Sales/warehouse owners approve representative unit mappings, allowable fractions and rounding. Blocks affected demand, readiness and load implementation. |
| Production availability — Q3/Q4 | Forecast output is future supply only. Its ready-at time includes completion, packing, cooling and quality release. Actual reservation requires native stock receipt and eligible quality state. Keep proposed output separate from production-owner-confirmed schedules. | Production/quality owners approve who confirms quantities/times and the source for remaining output. Machine-rate multiplication is optional estimation, not a required scheduler. Blocks future-supply coverage, not current-stock read design. |
| Factories and Companies — Q3/Q6 | A factory is an Inventory loading location under its actual legal Company. Same-company movement uses Inventory transfer and receipt. Different Companies require a separately approved intercompany flow; multi-company shared trips remain outside pilot scope. | Owner supplies the actual legal Company/location mapping for pilot sites. Do not infer it from SF1/SF2 labels. Blocks cross-site execution and data migration mapping. |
| Planning eligibility — Q3 | Propose remaining commercially eligible demand needed by the cutoff. Allow authorized planner inclusion, deferral and quantity splits with a reason. Vehicle confirmation is not required for tentative planning. Claims and release retain stricter native checks. | Sales/Operations approve which source dates/priorities define the cutoff and which restrictions allow tentative forecasting but forbid active claims. Blocks automated demand selection, not test-baseline repairs. |
| Approval responsibilities — Q2/Q6 | Planner proposes recovery; production confirms ready-at; warehouse verifies stock/load; dispatcher approves booking/deadline changes; Accounts approves financial effects. Apply independent approval to controlled exceptions and sensitive payments. | Operations/Accounts approve named primary/backup roles, thresholds, customer-promise change authority and permitted small-team role combinations. No generic override or implicit self-approval. Blocks consequential workflow activation. |

Existing S decisions remain in force. Approval of one row does not approve Q5 accounting treatment, all four mode responsibility matrices, or production activation. Unanswered rules remain explicit; dependency-independent baseline and owner-integrity work may continue.

## Decision record template

For each approval append:

- **Decision ID and version:**
- **Status:** Approved or Rejected
- **Accountable owner:**
- **Approved rule:**
- **Evidence reviewed:**
- **Effective date:**
- **Affected R/F/P/U IDs:**
- **Supersedes:**
- **Review trigger:**
- **Residual limitations:**

Do not delete the original open row. Link the approval below it or in an approved ADR and update its status so future agents can distinguish recommendation from policy.
