---
title: Logistics Testing and Acceptance Strategy
version: 1.1.0
date: 2026-09-20
---

# Logistics Testing and Acceptance Strategy

PostgreSQL is the sole project database acceptance target under [ADR 0007](../../adr/0007-postgresql-project-baseline.md). Record the exact tested version. Historical exploratory results on other engines do not add release gates.

## Evidence classes

Keep these evidence classes separate:

1. Documentation/traceability validation proves internal plan consistency only.
2. Source-pack Python/reference-model checks prove their synthetic specification models only.
3. AureusERP application tests prove the exercised code, fixtures, engine and entry points.
4. Staging/live operational evidence proves observed people, documents, workload and recovery for the approved cohort.

A scenario link is coverage intent, not a passed result.

## Execution record

For every verification run record requirement/scenario/phase IDs, code revision and local-diff identity, DB engine/version, policy/config revision, fixture/source IDs, actor/role, command/business IDs, exact invocation, expected and actual persisted results, native Inventory/Accounts references, redacted logs, reviewer, failures, skips and exclusions.

P0 establishes the durable evidence registry. Unless an approved evidence system replaces it, non-sensitive indexes use `docs/logistics/evidence/<phase>/<record-id>/index.md`, where `<record-id>` is `LOG-<phase>-<UTC timestamp>-<short commit>-<sequence>`. The index records artifact versions, secure object references for sensitive evidence, hashes, status (`planned`, `executed`, `reviewed`, `accepted`, `rejected` or `superseded`), reviewer identities, approval time and the superseding record. Sensitive documents, credentials, personal information and production extracts are never committed to Git. Every later phase acceptance cites the exact record ID and immutable approval or rejection reference.

## Verification gates

| ID | Layer | Required proof |
| --- | --- | --- |
| V1 | Static and traceability | Run `python3 docs/logistics/plan/tools/validate_pack.py`; review its link, ID, crosswalk and phase-contract results. When the two source directories are available, pass `--source-a` and `--source-b` to verify the recorded source hashes. |
| V2 | Domain/unit | Quantity conservation, pack snapshots, transition guards, rounding and scope calculations. |
| V3 | Service/feature | Shared commands and persisted effects; rejected commands assert zero side effects. |
| V4 | Native-owner regression | Actual API, Filament, Barcode, Manufacturing/direct-service and Accounts entry points around owner changes. |
| V5 | Transaction/failure injection | Operation-two, listener, storage association and outer-transaction failures roll back the correct boundary; migration failure leaves explainable PostgreSQL schema and ledger state and resumes only through verified forward recovery. |
| V6 | True concurrency/engine | Independent processes/connections, shared committed fixture, controlled barrier and fresh-connection assertions on PostgreSQL. Pest parallel mode alone is insufficient. |
| V7 | Replay/integration | Same request, changed payload, lost response, stale revision, out-of-order event, unknown outcome, correction and reversal. |
| V8 | Security/access | Negative permission, cross-company/parent-child, separate admin/auditor duties, support/import/export/evidence access, replay after revocation, idempotency collision/enumeration/preemption, unsafe uploads and effective audit-history update/delete denial. |
| V9 | Browser/operator | Keyboard and call-assisted work, actionable errors, documents/reprints, primary/backup queue handover. |
| V10 | Recovery/all-mode | Opening/reimport, database plus evidence restore, worker fencing, paper catch-up, and normal/failure journey for every transport mode. |

## Acceptance scenarios

### Quantity and planning

- AE1. Two planners claim 60 each against 100 eligible units: both cannot retain 60; 60+40 can succeed.
- AE2. Dispatch 40 of 100 and create a native 60 backorder: successor lineage maps once and unselected lines remain unposted.
- AE3. A 6x12 commitment precedes a 6x18 master change: historical commitment remains 6x12; actual repack is a new approved fact.
- AE4. Two concurrent writers try to create the same Inventory balance record when optional lot, package or owner fields are empty: exactly one balance identity remains on PostgreSQL.
- AE5. An unknown lot, unsupported fractional pack or wrong-category UoM is not treated as a wildcard, full quantity or zero.

### Release and physical execution

- AE6. The second Inventory operation for one departure fails after the first operation has run: both effects roll back and no gate authority is issued.
- AE7. Vehicle/load changes while release approval races gate use: only current exact authority can be consumed.
- AE8. Issue succeeds but response is lost: retry returns the original canonical result without another issue.
- AE9. Goods are issued but truck never departs: authority is revoked, cargo unloaded/recounted and only approved native correction restores availability.
- AE10. Truck exits without valid authority: actual departure is recorded as an incident; no retrospective approval is manufactured.

### Receiving, cargo, return and correction

- AE11. Receiver previously accepted 60 then reports 100 total across several drops: recompute covered difference without inventing one event or counting later evidence twice.
- AE12. Driver reports 100, paper says 100 and receiver says 95: retain three observations and one reconciled result, never 295/latest/average.
- AE13. Two genuine receipts of 20 remain distinct from two reports of the same 20 receipt.
- AE14. One 180 total covers two 100 source orders without shortage attribution: preserve accepted total and unresolved allocation; do not invent FIFO.
- AE15. A 100-unit cargo cohort splits into portions of 60 and 40, and the 40-unit portion transfers after a breakdown: the currently effective nonoverlapping portions total 100, each has one known or explicitly uncertain custodian, and Inventory does not issue the goods again.
- AE16. Ninety units are accepted, ten receive an approved final non-delivery allocation, and six are later recovered: reverse six of the eligible allocation into a known recovered portion with recorded custodian, location, condition and quarantine before later disposition.
- AE17. Wrong distributor receives custody: record actual custody and recovery; original commercial order is not fulfilled automatically.
- AE18. Customer return arrives damaged: original acceptance remains, native return is linked and stock stays quarantined until quality release.

### Freight and money

- AE19. Advance 20 precedes final bill 63 and distributor later pays 10 on company behalf: approved native allocations leave 33 without goods-payment or duplicate company cash.
- AE20. Supplier differs from authorized recipient: supplier liability remains intact and recipient/bank authority is independently verified.
- AE21. Two 60 payment attempts race against 100 remaining authority: both cannot consume 60; unknown execution prevents blind replacement.
- AE22. One bill covers several trips, one trip has several charges/payments and a partial hold: allocations/rounding conserve totals and hold does not erase liability.

### Lifecycle, modes and recovery

- AE23. Suspension races adding cargo to an old trip: the action losing the serialized cutoff fails; existing receipt/correction work remains accessible.
- AE24. Evidence bytes finalize but SQL association fails, required retained bytes are missing after restore, or disposal fails before or after byte deletion: an effective record never silently points to missing bytes; a provable unreferenced orphan can be cleaned; approved disposal requires elapsed retention, no legal hold, authorization, verified byte removal and an immutable tombstone. Every disposal crash boundary remains pending or becomes an incident until reconciled and cannot report final disposal early.
- AE25. Dedicated multi-stop trip has later-stop refusal/return pickup: earlier receipt, remaining segment capacity and custody remain correct.
- AE26. Carrier reaches destination terminal: contracted terminal milestone is recorded but distributor receipt remains open until authorized final handover.
- AE27. Distributor collector mismatch: pickup is blocked while attempted/unauthorized event remains recordable.
- AE28. A restored backup lacks a payment or stock issue that may have succeeded later: workers remain paused until the original external or native operation identity is reconciled before replay.
- AE29. In-flight opening import runs twice: second run creates no shipment, stock or payment effect and all mappings remain identical.
- AE30. Assigned coordinator becomes inactive: team queue and backup retain ownership without resetting age.

## Required phase coverage

| Phase | Minimum gates |
| --- | --- |
| P0 | V1, isolated V3 baseline |
| P1A | V2-V7 on PostgreSQL, including every native writer |
| P1B | V2-V7 on PostgreSQL and Recon integration |
| P2 | V3, V5, V7, V8 |
| P3 | V2-V4, V6-V9 |
| P4 | V2-V8, V10 recovery cases |
| P5 | V2-V8 and native accounting reconciliation |
| P6 | V8-V10 for all modes and roles |
| P7 | V5, V7-V10 under restore/outage/opening scenarios |
| P8 | All V1-V10 plus live operational evidence |

## Phase acceptance evidence

The phase owner stores these artifacts with the execution record defined above. An approval means the named reviewer assessed the evidence; it is not satisfied by the implementer marking a checklist complete.

| Phase and units | Exact verification | Required acceptance artifact | Required approvers | Blocking defects |
| --- | --- | --- | --- | --- |
| P0 — U1, U2 qualification, U14 diagnosis | V1 and isolated V3; current baseline plus all-mode paper/basic-phone walkthroughs. | Revision and local-diff record, PostgreSQL version and configuration inventory, complete caller/lock map, baseline failures, representative documents, decision-owner register and walkthrough record. | Technical lead plus Sales, Inventory, Accounts, warehouse and operations owners for their facts. | Unexplained baseline failure; missing writer; unknown engine/version; no owner or evidence plan for a blocking decision. |
| P1A — U3-U5 | V2-V7; AE1-AE2, AE4, AE6, AE8-AE9 and AE18 on PostgreSQL. | Approved physical schema specification, duplicate preflight, transaction/lock diagram, every-writer matrix, persisted-state assertions and onsite-correction evidence. | Inventory technical owner and Inventory/Sales business owners; quality owner for return disposition. | Duplicate or lost stock effect; writer bypass; partial operation; unproven missing-balance identity; correction represented as customer return. |
| P1B — U14-U15 | V2-V7; AE19-AE22 and restore uncertainty from AE28 on PostgreSQL. | Corrected reconciliation fixtures, Accounts schema/command specifications, journal/reconciliation traces, recipient authority, race/lost-response/reversal evidence. | Controller or delegated Accounts owner plus Accounts technical owner and independent reviewer. | Wrong or duplicate financial effect; unexplained residual; fixture with incorrect economic direction; supplier/recipient ambiguity; blind retry after uncertainty. |
| P2 — U2, U7-U8 | V3, V5, V7 and V8; AE23-AE24. | Control/evidence/command schema and contracts, suspension race result, evidence byte/SQL failure matrix, access negatives, retention and restore approval. | Operations control owner, data/privacy owner, security reviewer and technical owner. | Activity crosses cutoff; replay bypasses access; accepted record lacks verifiable evidence; audit/control history is mutable. |
| P3 — U6, U9-U10 | V2-V4 and V6-V9; AE1-AE5. | Approved Q1/Q4/Q6 and Q13 applicability records, Sales/Logistics schema specifications, source-lineage and claim proof, verified-load walkthrough and phone-booking record. | Sales, Product/UoM, warehouse, operations and Inventory owners. | Historical meaning inferred from current master data; excess claim; unsupported conversion; load cannot explain native remainder. |
| P4 — U11-U13 | V2-V8 plus applicable V10 recovery; AE6-AE18. | Release/receipt/cargo command and schema specifications, atomic issue proof, gate race result, receipt reconciliation set, cargo conservation and correction-dependency report. | Warehouse/Inventory, dispatch, receipt reconciliation, quality and operations owners. | Partial issue; stale authority succeeds; receipt creates stock; overlapping cargo; invented attribution; unbounded correction. |
| P5 — U16 | V2-V8 and native accounting reconciliation; AE19-AE22. | Freight schema/command specifications, agreement-to-native-record trace, allocation conservation, aggregate bill, hold, timeout, reversal and cancelled-booking recovery evidence. | Operations freight owner, Controller or delegated Accounts owner, and independent control reviewer. | Logistics maintains a financial balance; agreement treated as posting; recipient or residual is unexplained; P4 correction dependency unresolved. |
| P6 — U17-U18 | V8-V10; AE25-AE27 and AE30. | Signed responsibility matrix, normal/failure/recovery evidence for all four modes, document/reprint and cross-company access results, primary/backup training and workload record. | Operations sponsor plus dispatch, warehouse, Accounts, receiving and support owners. | Any mode incomplete; terminal treated as customer receipt; collector authority unclear; inaccessible workflow; no capable backup. |
| P7 — U19 | V5 and V7-V10; AE24 and AE28-AE30. | Authorized read-only opening inventory, repeatable dry-run reconciliation, database/evidence restore report, worker-fencing proof, paper catch-up and every required runbook exercise. | Recovery coordinator, database/evidence owners, Inventory, Accounts and operations control owner. | Unexplained provisional opening balance; non-idempotent rehearsal; stale worker; required retained evidence is missing or changed; an approved disposal lacks a valid tombstone; unknown stock or payment outcome is replayed. |
| P8 — U20 | V1-V10 and AE1-AE30, plus observed pilot evidence for every activation-matrix row. | Cutoff/high-watermark record, reconciled late-change delta, applied final opening batch with zero-effect rerun, signed pilot activation record, daily reconciliations, incident and queue review, service/workload results by mode, hypercare roster and expansion decision. | Executive sponsor and accountable Sales, Inventory, Accounts, warehouse, quality, operations and technical owners. | Any opening difference, unfenced relevant writer, duplicate adoption, no-go defect, open required decision, missing mode/evidence/owner, exceeded operating limit, or unresolved critical incident. |

## All-mode launch matrix

Each row requires named owner and backup, normal scenario, mode-relevant failure and recovery, stock evidence, receipt evidence, freight evidence or approved no-charge basis, protected evidence and support route.

| Mode | Mandatory proof |
| --- | --- |
| Dedicated vehicle | Actual provider/vehicle/driver, booking terms, load/release/gate, receipt, onsite abort. |
| Multi-stop | One controlled release with multiple source/stop outcomes, later-stop capacity, refusal/return and partial recovery. |
| Carrier consignment | Issuer-scoped reference, factory/terminal/final endpoint responsibilities, feeder/final-mile custody, terminal-not-receipt case. |
| Distributor pickup | Collector authority, custody/receipt responsibility, payer/cost bearer, mismatch/refusal recovery. |

## No-go defects

- Duplicate, missing or unexplained stock/payment/receipt effect.
- Stale/revoked gate authority succeeds.
- Cross-company data or action leakage.
- An effective record references retained evidence whose bytes are missing or unrecoverable, or evidence was destroyed without an approved disposal tombstone.
- Unknown stock/payment outcome can be blindly replayed.
- Opening quantity, cargo, charge or residual does not reconcile.
- Any mandatory mode is incomplete.
- Required queue has no owner/backup or exceeds approved capacity.

Critical integrity, security and accounting gates cannot be waived for affected scope. A noncritical deviation needs named owner, compensating control, expiry, evidence and approval; it never changes the underlying physical or accounting fact.
