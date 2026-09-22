---
title: Source Comparison and Traceability
version: 1.1.0
date: 2026-09-20
---

# Source Comparison and Traceability

## Sources reviewed

- **Plan A:** `shafique-foods-logistics-v0.2`, version 0.2.0 dated 2026-09-15; 45 Markdown documents, 89 registered requirements, 84 acceptance scenarios, decision/review/model registers and delivery templates.
- **Plan B:** `shafique-outbound-transport-v0.2.0`, version 0.2.0 dated 2026-09-16; 37 Markdown documents plus reading edition, 114 registered requirements, 64 acceptance scenarios, decision/review registers, illustrative data and delivery templates.
- **Repository evidence:** current source plus `docs/logistics/INTEGRATION-CONTRACTS.md`, `PHASE-2-BACKLOG.md`, implementation reports, repository audit, reuse map and test baseline.

Both source plans were reviewed across governance, business requirements, packaging/capacity, workflows, state models, architecture, integrations, delivery, assurance, decisions and registries. Their reference-model results remain specification evidence; they are not AureusERP application or operational evidence.

## Comparative result

| Area | Plan A stronger contribution | Plan B stronger contribution | Consolidated result |
| --- | --- | --- | --- |
| Native ERP fit | Source/backorder lineage, release batch, plugin lifecycle. | Platform-neutral capability questions expose hidden assumptions. | Native ownership with mandatory refactor where current code is wrong. |
| Quantities and packaging | Earliest commitment snapshot and physical packing detail. | Child-version identity, unknown lot and correction pressure. | Immutable commitment and actual-load snapshots; missing facts remain unknown rather than being inferred. |
| Departure | One controlled multi-operation release and recovery semantics. | Revision-bound authority, issued-onsite case and false-success challenges. | Atomic known result, single-use authority and explicit onsite correction. |
| Receiving | Explicit incremental/cumulative/corrected/unclear intent. | Raw observations, canonical events, conflicting reports and unattributed totals. | Physical events and cumulative attestations with bounded allocations. |
| Cargo/correction | Nonoverlapping portions, custody and recovery history. | Lost-then-found, downstream dependencies, wrong-recipient handling. | Active-leaf conservation and forward bounded correction. |
| Freight | Native Accounts ownership and real advance concept. | Pending execution, payer/recipient distinction, holds and third-party payer. | Accounts-owned instruction/posting/reconciliation with Logistics allocations. |
| Operations | Task workspaces, protected evidence and recovery. | Callback corroboration, workload/backup and capability activation. | Role queues, private evidence, runbooks and all-mode gate. |

## Conflicts resolved

- Plan B's dedicated-only first production pilot is rejected by the user-settled all-mode pilot. A dedicated slice remains a valid engineering proof only.
- Plan A's potentially ambiguous pending/posted payment descriptions are replaced by distinct agreement, liability, instruction, submitted/unknown, confirmed, allocation and residual states.
- Plan A's receipt intent and Plan B's canonical reconciliation are combined; neither is sufficient alone.
- Generic platform rediscovery from Plan B is removed where repository facts are known, while its challenges remain valid tests of the native contracts.
- A speculative per-operation fallback after partial stock issue is rejected. Failure to prove local atomicity and onsite correction blocks release activation.
- Arbitrary package/routing/integration frameworks are removed from the critical path. Exact snapshots and manual approved processes meet the current requirement.
- Existing AureusERP behavior does not win a conflict merely because it exists. The owner module must be refactored to the target behavior.

## Distinctive requirements retained

### Primarily from Plan A

- Native subset/backorder lineage and unselected-line protection.
- Release batch for one physical departure.
- Earliest commercial quantity/conversion snapshot.
- Receipt input intent and correction history.
- Agreement versus consignment versus known movement separation.
- Plugin suspension/removal and evidence survival.
- In-flight adoption and recovery ownership.

### Primarily from Plan B

- Issued-but-not-departed recovery.
- Observation versus canonical receiving event.
- Ambiguous combined-order attribution.
- Bounded reversal of loss/receipt/disposition.
- Journey-wide quality containment and wrong-recipient flow.
- Corroborated consequential phone requests.
- Pending money authority and restore/replay fencing.
- Pilot workload and backup-owner gate.

## Requirement-family disposition

Every registered source requirement has a target disposition: all 89 Plan A requirements and all 114 Plan B requirements are retained, adapted, explicitly deferred or excluded by product boundary. IDs remain source provenance and are never reused as target IDs.

| Source requirement families | Target ownership | Disposition |
| --- | --- | --- |
| A FR-001–FR-008 and FR-072; B REQ-PLN-001–REQ-PLN-010 | R9-R15; DM2-DM5; F1-F4 | Demand, splits, quantity meaning and planning retained. |
| A FR-009–FR-016, FR-054 and FR-074; B REQ-BKG-001–REQ-BKG-008 | R16-R18 and R31-R37; DM4; F3, F8, F9 and F12 | Parties, booking, service and billing cardinality retained. |
| A FR-017–FR-024; B REQ-PKG-001–REQ-PKG-010 | R11-R14; DM3; F2 | Immutable required packing retained; general authoring deferred. |
| A FR-025–FR-029; B REQ-VEH-001–REQ-VEH-008 | R15 and R30; F2 and F7; RK2 | Actual equipment, uncertainty and safety retained. |
| A FR-030–FR-033; B REQ-LOC-001–REQ-LOC-007 | R16, R18 and R40; F3 and F7-F9 | Endpoints and known routes retained; automatic zoning and routing deferred. |
| A FR-034–FR-039 and FR-071; B REQ-EXE-001–REQ-EXE-004, REQ-EXE-008, REQ-EXE-009 and REQ-CTL-009 | R4-R8 and R19-R22; F4-F6 | Controlled release, real events, documents and recovery retained. |
| A FR-040–FR-043; B REQ-USR-001–REQ-USR-008 and REQ-EXE-005 | R22, R37 and R38; F7 and F10 | Assisted operation and provenance retained; portals optional. |
| A FR-044–FR-053 and FR-073; B REQ-RCV-001–REQ-RCV-011, REQ-EXE-006, REQ-EXE-007, REQ-EXE-010 and REQ-EXE-011 | R23-R30; F7-F11 and F13 | Receiving, custody, quality, return and correction retained. |
| A FR-055–FR-062 and FR-075; B REQ-FRT-001–REQ-FRT-011 | R31-R36; F12 | Freight, advance, payer, hold, claim and allocation retained; automatic rating deferred. |
| A FR-063–FR-066; B REQ-CTL-001–REQ-CTL-008, REQ-INT-001–REQ-INT-004 and REQ-INT-008 | R1-R8, R20 and R38; KTD1-KTD14 | Security, lifecycle, commands and reliability adapted to native owners. |
| A FR-067–FR-068; B REQ-RPT-001–REQ-RPT-007 | R38-R40; V9 and V10 | Honest reports, queues and capacity retained. |
| A FR-069 | R31-R36 plus exclusion | Product cash collection uses separate Accounts authority and records. Sharing a driver or physical handover does not make customer collections part of freight settlement. |
| A FR-070 and FR-076–FR-077; B REQ-INT-005–REQ-INT-007 and REQ-INT-009 | R7, R8 and R39; F13 and F14; P7 | Cutover, outage, restore and lifecycle retained. |
| A NFR-001–NFR-012; B REQ-QLT-001–REQ-QLT-005 | R1-R8 and R37-R40; V1-V10 | Atomicity, access, recovery, usability, performance and maintainability retained. |

## Shared gaps corrected by this pack

- No prior single source-of-truth hierarchy made code explicitly subordinate to domain correctness.
- Neither source mapped concrete current AureusERP defects into owner-local refactoring work.
- Neither provided a complete per-phase contract covering database, APIs, permissions, validations, migration and exclusions.
- Both lacked a unified signal/queue/runbook operational contract.
- Both left cross-engine missing-stock-bucket uniqueness and direct native-writer coverage insufficiently concrete.
- Both needed a stronger private evidence byte/SQL lifecycle.
- Both needed an Accounts-owned payment instruction/recipient/unknown-outcome contract.
- Neither proved whether regulated food handling beyond ordinary lot/quality holds applies; Q13 is added.

## Remaining evidence gaps

- Approved role and threshold matrix, including which actions require an independent preparer and approver.
- Approved stock issue/onsite correction and accounting advance/recipient/tax policies.
- Representative packaging, carrier consignment or bilty, proof-of-delivery, advance, bill, return and exception documents.
- Evidence privacy/retention schedule and restore evidence.
- Supported-engine concurrency evidence and full native caller map at implementation time.
- Actual workload, history and attachment volume, response targets, approved recovery-point and recovery-time objectives, and support coverage.
- Historical field-level migration mapping and any approved external goods-receipt contract.
- Operator training, restore drill and all-mode staging acceptance.

## Traceability chain

Maintain this chain for every target requirement:

`source requirement(s) -> R requirement -> Q decision -> D invariant -> F workflow -> KTD/IC/DM/G design -> P phase/U unit -> V/AE evidence`

Changes to quantity, custody, approval, evidence or money semantics update every affected link. Source IDs are preserved and superseded mappings are recorded rather than silently reused.

## Target requirement crosswalk

The source-family table above supplies source provenance. This table supplies the implementation and evidence path for every consolidated requirement. “Specified” means the target behavior is defined; it does not mean an open Q decision is approved or implementation evidence exists.

| Requirement | Decision and domain | Workflow and design | Delivery | Evidence and current status |
| --- | --- | --- | --- | --- |
| R1 | S1; KTD1 | G17 | P0/U1 | V1; specified, current-source verification required. |
| R2 | S2-S3; D1-D18; KTD2/KTD12 | IC1-IC10; G1/G4-G14 | P0-P5/U1-U16 | V3-V8; specified, owner contracts and proof pending. |
| R3 | Q2; D15-D18; KTD11 | IC11; DM1-DM15; G15 | P2/U8 | V8; specified, Q2 and access evidence pending. |
| R4 | Q2; D15-D16; KTD7/KTD11 | IC11; DM6-DM7; G15 | P2/U8 | V3/V7/V8; AE7/AE21/AE23; Q2 open. |
| R5 | Q2; D15; KTD7 | IC3/IC8/IC11; DM6; G5/G11/G15 | P1A-P2/U4/U8/U15 | V3/V7/V8; AE8/AE21; implementation pending. |
| R6 | Q2; D16; KTD11 | F2-F5/F12; DM7; G15 | P2-P5/U8/U10-U16 | V3/V7/V8; AE7; Q2 open. |
| R7 | Q9; D18; KTD14 | F13; IC10; DM14; G2-G3 | P2/U2/U8 | V6-V8; AE23; Q9 cutoff open. |
| R8 | Q7; D17; KTD10 | F10/F13; IC9; DM13; G14 | P2/U7 | V5/V8/V10; AE24; Q7 open. |
| R9 | Q3; D1-D2/D19 | F1; IC1-IC2; DM2; G4/G6 | P3/U9 | V2-V7; AE1-AE2; Q3 open. |
| R10 | Q3; D1-D2/D19; KTD4-KTD5 | F1/F4; IC2-IC3; DM2/DM5; G5-G6 | P1A/P3/U4/U9 | V3/V6/V7; AE1-AE2/AE6; Q3 open. |
| R11 | Q1; D1-D2/D5/D19; KTD8 | F2; IC6; DM3; G10 | P3/U6/U10 | V2-V4; AE3/AE5; Q1 open. |
| R12 | Q1; D1/D5; KTD8 | F2; IC1/IC6; DM3; G4/G10 | P3/U6 | V2-V4; AE3; Q1 open. |
| R13 | Q1; D5; KTD8 | F2; IC6; DM3; G10 | P3/U6/U10 | V2/V3/V9; AE5; Q1 open. |
| R14 | Q1/Q4; D2/D5/D7-D8 | F2/F7/F11; IC5-IC6; DM3/DM8; G8-G10 | P1A/P3-P4/U5/U6/U10/U13 | V2-V4; AE3/AE5/AE15/AE18; Q1/Q4 open. |
| R15 | Q4; D5/D8 | F2/F7; IC6; DM3/DM8; G10 | P3/P6/U10/U18 | V2/V3/V9/V10; AE5/AE15/AE25; Q4 open. |
| R16 | Q6/Q13; D3/D19-D20; KTD14 | F7-F9; DM1/DM4/DM15 | P6-P8/U18/U20 | V10; AE25-AE27; Q6 and Q13 assessment open. |
| R17 | Q6; D4/D13/D19-D20 | F3/F8-F9; IC7; DM4/DM12/DM15 | P3/P5-P6/U10/U16/U18 | V3/V8-V10; AE20/AE27; Q6 open. |
| R18 | Q5/Q6; D4/D13/D20 | F3/F8/F12; IC7-IC8; DM4/DM12 | P3/P5-P6/U10/U16/U18 | V3/V7/V9; AE19-AE22/AE26; Q5/Q6 open. |
| R19 | Q1/Q4; D5/D19 | F2; IC1/IC6; DM3/DM15; G10 | P3/U6/U10 | V2-V4; AE2-AE3/AE5; Q1/Q4 open. |
| R20 | Q2-Q4; D6; KTD3-KTD5 | F4; IC3; DM5; G5-G6 | P4/U11 | V3-V7; AE6/AE8; Q2-Q4 open. |
| R21 | Q6/Q10; D6-D12 | F5/F8-F11; DM5/DM8-DM11 | P4-P6/U11-U13/U18 | V3/V7/V10; AE10-AE18/AE26; Q6/Q10 open. |
| R22 | Q2; D9/D12; KTD11 | F5; DM9/DM11; G15 | P4/U11-U12 | V3/V8; AE10; Q2 open. |
| R23 | Q3/Q4/Q6; D7-D8; KTD9 | F7; DM8 | P4/P6/U13/U18 | V2/V6/V7; AE15; Q3/Q4/Q6 open. |
| R24 | Q2/Q6/Q10; D9-D10 | F10; DM9 | P4/U12 | V3/V7/V8; AE11-AE14; decisions open. |
| R25 | Q6/Q10; D9-D11; KTD9 | F10; DM9-DM10 | P4/U12 | V2/V3/V6/V7; AE11-AE14; decisions open. |
| R26 | Q6/Q10; D11-D12 | F10; DM10-DM11 | P4/U12 | V2/V3/V6; AE14; decisions open. |
| R27 | Q4/Q10; D8-D12 | F10-F11; DM8-DM11 | P4/U12-U13 | V2-V3/V7; AE12/AE16-AE18; decisions open. |
| R28 | Q4/Q10; D11-D12 | F11/F14; DM10-DM11 | P4/P7/U13/U19 | V2/V3/V7/V10; AE16/AE18/AE28; decisions open. |
| R29 | Q3/Q4; D2/D6-D8 | F6; IC5; DM5/DM8; G9 | P1A/P4/U5/U11 | V3-V7; AE9; pilot-blocking decisions and proof pending. |
| R30 | Q4; D2/D8/D12 | F11; IC5-IC6; DM8/DM11; G8-G9 | P1A/P4/U5/U13 | V2-V4/V7; AE16-AE18; Q4 open. |
| R31 | Q5; D13-D14; KTD12 | F12; IC8; DM12; G11-G12 | P1B/P5/U15-U16 | V3-V7; AE19-AE22; Q5 open. |
| R32 | Q2/Q5/Q6; D13-D14 | F12; IC7-IC8; DM12; G12 | P1B/P5/U15-U16 | V3/V7/V8; AE20; decisions open. |
| R33 | Q5; D13-D14 | F12; IC8; DM12; G11-G12 | P1B/P5/U15-U16 | V3-V7; AE19; Q5 open. |
| R34 | Q5/Q10; D13-D15 | F12; IC8; DM6/DM12; G11-G12 | P1B/P5/U15-U16 | V3/V7; AE19-AE22; decisions open. |
| R35 | Q5/Q9; D14-D15; KTD7/KTD12 | F12-F13; IC8; DM6/DM12; G11 | P1B/P5/P7/U15-U16/U19 | V5-V7/V10; AE21/AE28; decisions open. |
| R36 | Q5; D13-D14 | F12; IC8; DM12 | P5/U16 | V2/V3/V7; AE19/AE22; Q5 open. |
| R37 | Q2/Q8; D9/D17; KTD13 | F10; IC11; G18 | P6/U17 | V8-V10; AE30 plus role walkthrough; Q2/Q8 open. |
| R38 | Q2/Q8-Q10; D12/D18; KTD13 | F13; DM11/DM14; G18 | P6-P7/U17/U19 | V8-V10; AE30; decisions open. |
| R39 | Q7/Q9/Q10; D15/D17-D18; KTD6/KTD7/KTD10/KTD14 | F13-F14; IC12; DM6/DM13-DM14 | P7/U19 | V5/V7-V10; AE24/AE28-AE30; decisions open. |
| R40 | Q1-Q10/Q13; all applicable domain and design IDs | F1-F14; KTD14 | P8/U20 | V1-V10; AE1-AE30 and live all-mode evidence; all required decisions/evidence pending. |

## Source fingerprints

| Source locator | SHA-256 |
| --- | --- |
| Plan A `MANIFEST.json` | `9342b155cc91a91ef3e78bd22b51461f67cfaf6e896501c9c845131be8276431` |
| Plan A `registry/requirements.json` | `f44b63010713fbfefab831ada889ac60e4b18a7feff5a8e8520237c00eeff507` |
| Plan B `project-manifest.json` | `4dd89022ffeb0f7a8bb70d02f4a5ee46588f5f54015ad127d7ffe84d8707b547` |
| Plan B `registers/requirements.json` | `531ccaf8fe6907b123510bebc8ae02b6dcfd64775a3f235cc23e25fd82c4f921` |
