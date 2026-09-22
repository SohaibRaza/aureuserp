---
title: Logistics Data Model and Migration Plan
version: 1.1.0
date: 2026-09-20
---

# Logistics Data Model and Migration Plan

## Persistence families

These families define required persisted meaning. Physical tables may combine closely related records when constraints and lifecycle remain clear.

| ID | Family | Minimum persisted fields | Required constraints/indexes |
| --- | --- | --- | --- |
| DM1 | Trips/stops | company, mode, origin, ordered known endpoints, planned/actual revision, operational flags. | Company/state/date; no cross-company root. |
| DM2 | Demand allocations | source line/move, source revision, planned quantity, active claim quantity/state, successor lineage. | Source aggregate guarded under lock; active-claim indexes; a claim is the quantity-consuming state of the allocation. |
| DM3 | Commitment/load snapshots | product/UoM, commercial/native quantities, conversion, pack contents, lot, measures, provenance, revision. | Append-only source/revision identity. |
| DM4 | Booking agreement revisions and carrier consignments | Booking records preserve provider roles, contractual endpoints, terms, acceptance and cancellation. Separate consignment records preserve issuer-scoped identity, shipment portions assigned to the consignment, physical handovers into carrier custody with actor, time and evidence, the governing booking revision and optional known-trip links. | Stable booking identity; immutable prior revisions; consignment uniqueness is issuer and service scoped; assignment is not physical handover. |
| DM5 | Release batches/items | frozen load/native set, approvals, outcomes, authority, revocation/consumption. | One current authority per exact revision; unique native effect references. |
| DM6 | Command results | company/type/key, fingerprint, expected revision, actor context, result/uncertainty. | Unique company/type/key; root business locks still required. |
| DM7 | Approvals | scope, revision, approver, evidence, validity, invalidation/consumption. | At most one current authority for scoped action. |
| DM8 | Cargo cohorts/portions | source cohort, parent, effective quantity, custodian, segment, condition, retirement. | Nonnegative; parent/child conservation under cohort lock; active indexes. |
| DM9 | Receipt evidence | Reporting party, recording user, occurrence time and uncertainty, recording time, input intent, physical-event identity or cumulative-confirmation scope and cutoff, evidence state, conflict state and supersession link. | Request deduplication is separate from event matching; date and quantity alone are never unique identity. |
| DM10 | Effective outcome allocations | Dispatched cohort or portion, source observation or cumulative confirmation, exact quantity and unit, one approved outbound outcome, and correction or reversal link. Condition and unmatched overage are stored separately. | Outcomes are limited to accepted, returned and received without prior acceptance, approved final non-delivery resolution, or outstanding; their total is bounded by dispatched quantity under lock. |
| DM11 | Exception/correction cases | affected facts, quantity/amount, owner role/user, next action, age, dependencies, disposition. | Company/owner/state/age; inactive user cannot orphan ownership. |
| DM12 | Freight agreements/allocations | revisioned components, supplier/recipient/payer/cost bearer, basis, native record links, applied/reversed amount. | Company/currency/counterparty compatibility; charge conservation. |
| DM13 | Evidence | Private object key, hash, size, detected type, Company, provenance, retention rule, legal holds, immutable associations, lifecycle state and approved-disposal tombstone fields. | No silent loss or mutation of retained accepted bytes; disposal requires expired retention, no legal hold, authorized outcome and preserved tombstone; no destructive Chatter cascade. |
| DM14 | Operational controls | company status, effective cutoff, reason, actor and history. | One current company control; serialized change and authorization. |
| DM15 | Outbound shipment portions | Company, origin, destination, source allocations, commitment snapshot, recipient, planned transport mode, current load/release lineage and revision. | One source quantity cannot be active in overlapping shipment portions; a portion may link to a trip, consignment or pickup without requiring a synthetic trip. |

## Native-owner schema changes

- Sales stores immutable quantity/packing commitment snapshots at confirmation and approved confirmed-line revision.
- Inventory adds only the approved quant uniqueness/serialization support, enrolled execution hook data where necessary, owner command/result identity, cumulative return guards and onsite correction audit.
- Accounts adds only the approved advance/payment instruction, recipient authority, effect identity and uncertainty/reconciliation fields needed to protect native financial effects.
- Logistics stores transport coordination, evidence and cross-owner references. It does not copy native balances.

## Migration principles

1. Additive before constraining: deploy nullable/new structures and compatible code before enforcing new NOT NULL/unique/check constraints.
2. Preflight before uniqueness: report duplicate quant/business identities read-only and stop; never merge/delete historical stock automatically.
3. Explicit legacy state: classify missing provenance, conversion, evidence or source attribution as `legacy_unknown`, not a fabricated default.
4. Bounded backfill: use stable primary-key windows, resumable checkpoints and row counts/hashes; do not hold operational locks for a full historical table.
5. Dual-read only when necessary and time-bounded; name the retirement criterion. Avoid dual-write unless both writes share one transaction and invariant.
6. Forward correction over history rewrite: opening/adoption records reference existing native effects and physical position.
7. Cross-engine design: PostgreSQL and supported MySQL get equivalent business guarantees even when syntax differs.
8. Rollback preserves facts: code rollback may disable new commands, but migrations containing actual business history are not destructively rolled back.

## Required physical schema specification

The persistence families above define business meaning; they are not permission to invent tables while coding. Before authoring a migration for a unit, its owner must add a reviewed physical schema specification to that unit's implementation record. The specification must state:

- exact table and model names, owning module and business purpose;
- every column's database type, precision, nullability, default and whether it is mutable after use;
- foreign keys, deletion behavior and the treatment of parent or source removal;
- unique, check and lookup indexes, with equivalent PostgreSQL and supported MySQL behavior;
- the records and transitions that require transaction locks;
- legacy classification, backfill source, checkpoint and reconciliation totals;
- compatible application versions for expand, activate and contract steps; and
- rollback or forward-recovery behavior plus the condition for retiring compatibility code.

Schema review must link the applicable D, IC, DM, G, Q and AE identifiers. A migration cannot start while any required identity, precision, ownership, lifecycle or historical-data rule is unresolved.

## Migration sequence

### M1A. Nonproduction characterization

- Freeze a schema/source snapshot for relevant Sales, Inventory, Accounts, Support and Logistics tables.
- Run read-only duplicate/null/precision/source-orphan reports.
- Use representative, sanitized nonproduction data to design reports and classify possible legacy conditions during P0.

### M1B. Authorized opening inventory

- During P7, run approved read-only reports against the opening cohort to inventory in-flight shipments, issued moves, returns, unsettled freight, pending payments and evidence files.
- Record unsupported rows as owned migration cases. P0 does not authorize production access.

### M2A. Inventory owner integrity

- Add Inventory constraints/indexes only after Q3 identity decision and clean preflight.
- Deploy compatible owner services before activating constraints that old writers cannot satisfy.

### M2B. Accounts owner integrity

- Add Accounts instruction/result/recipient safeguards after Q5 approval.
- Deploy compatible Accounts services before activating constraints that old writers cannot satisfy.

### M2C. Sales commitment history

- Add Sales snapshot storage during P3 after Q1 approval.
- Backfill only facts supported by preserved source evidence; otherwise record legacy meaning as unknown.

### M3A. Control and evidence foundation schema

- Add command/approval, evidence and operational-control records during P2.
- Preserve current existing foundation rows after reconciliation; do not create a competing settings/control model.

### M3B. Planning and loading schema

- After P1A and P2 acceptance, add outbound shipment portions, trip/stop, allocation and booking/load revision records during P3.
- Do not scaffold policy-sensitive structures before their blocking Q decisions close.

### M4. Execution and reconciliation schema

- Add release batch/items, cargo cohorts/portions, observations/events/attestations, receipt/outcome allocations and correction cases.
- Backfill only stable references; keep unknown physical history explicit.

### M5A. Freight linkage schema

- Add freight agreement revisions and allocations to native Accounts identities.
- Do not import a synthetic payable balance. Validate native company/currency/supplier relationships.

### M5B. Mode and handover schema

- During P6, add only the endpoint, segment, collector, consignment and handover associations required by the approved rules for responsibilities in all four transport modes.
- Reuse DM1, DM4, DM8 and DM15 identities. Do not introduce a second cargo, trip or consignment balance for a transport mode.
- Activate each association through the same physical schema review, both-engine checks and expand/verify/activate/contract checkpoint used by earlier phases.

### M6. Opening adoption

- P7 builds and rehearses the opening mapping from an authorized read-only cohort extract. It must not apply the production opening batch.
- At the P8 cutover, establish one Company/cohort cutoff and durable batch identity. Fence new Logistics release and payment commands for the cohort, record late physical activity through the controlled fallback, and capture native high-watermarks for the source records.
- Re-extract and reconcile changes since the P7 rehearsal, then adopt current in-transit, onsite-issued, received-unresolved and financially open obligations once. Unrelated native work may continue; any cohort mutation that can change the opening position remains fenced until adoption and reconciliation finish.
- Produce reconciliation totals by Company, source, state, rehearsal batch, final batch and cutoff before activation. Re-running the same final batch must create zero new mappings or business effects.

### Per-phase activation and cleanup

Each phase uses an explicit expand, verify, activate and contract checkpoint. Enforce that phase's additive constraints only after compatible application code, data preflight and both-engine checks pass. Record the switch that disables old writers and the rollback or forward-recovery response before activation. Remove compatibility reads only after telemetry and row classification prove that no supported writer or record requires them. Retain audit and mapping data needed to explain historical results.

## Migration-to-phase map

| Migration work | Phase | Required evidence before activation |
| --- | --- | --- |
| M1A nonproduction characterization | P0 | Reproducible reports, sanitized fixtures and explained findings. |
| M2A Inventory integrity | P1A | Q3 approval, duplicate preflight, caller map and PostgreSQL/MySQL concurrency proof. |
| M2B Accounts integrity | P1B | Q5 approval, corrected reconciliation fixtures and native effect/reversal proof. |
| M3A controls and evidence | P2 | Q2/Q7/Q9 decisions, evidence failure tests and suspension race proof. |
| M2C plus M3B planning/loading | P3 | Q1/Q4/Q6 decisions, physical schema specification and source-lineage proof. |
| M4 execution/reconciliation | P4 | P3 acceptance, Q10 decision, Inventory transaction and correction proof. |
| M5A freight linkage | P5 | P4 and P1B acceptance, Q5/Q6 decisions and accounting reconciliation proof. |
| M5B mode and handover associations | P6 | Signed mode matrix, reviewed physical schema, both-engine checks and forward-recovery checkpoint. |
| M1B plus M6 opening rehearsal | P7 | Authorized read-only cohort extract, repeatable dry-run mappings, restore test and provisional reconciliation totals. |
| M6 final opening adoption | P8 cutover | Approved cutoff and batch identity, fenced cohort commands, late-change delta, final reconciliation and zero-effect rerun. |

## Rollback and recovery

- Before live activation, database backup and protected evidence snapshot must share a recoverable point or documented reconciliation procedure.
- Migration steps document statement boundaries and postconditions per engine. After any DDL failure, keep affected capabilities disabled, inspect actual schema state, and resume only through verified forward steps; never assume a multi-statement MySQL migration rolled back atomically.
- A failed backfill resumes from a verified checkpoint and never assumes a row failed merely because the client lost its response.
- A restored older backup fences release/payment/external workers until post-backup outcomes are reconciled.

## Migration acceptance

- Preflight counts are explained and approved; no silent repair occurred.
- Repeated backfill/adoption produces the same mapping and no duplicate effects.
- Native and Logistics totals reconcile by company and source identity.
- Both database engines enforce equivalent identity and aggregate behavior.
- Code rollback cannot erase evidence history or approved-disposal tombstones, physical history, or native financial and stock facts.
