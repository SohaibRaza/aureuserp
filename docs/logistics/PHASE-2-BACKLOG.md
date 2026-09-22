# Logistics Phase 2 Backlog

Status: historical work list derived from Phase 1 evidence at `45957cb763369e07ba46c702c170c09dab7b5a35`. It records completed and pending work at that point in time. It is no longer the authoritative implementation sequence; use [`plan/08-IMPLEMENTATION-ROADMAP.md`](plan/08-IMPLEMENTATION-ROADMAP.md). Shared Sales, Inventory, and Accounts integrity work still precedes dependent Logistics posting.

| Historical item | Current roadmap location |
| --- | --- |
| 1. Disposable test harness | P0, U1 |
| 2. Reconciliation regression boundary | P0 and P1B, U14 |
| 3. Sales confirmation integrity | P0/P3 owner prerequisite, U6 |
| 4. Inventory quantity identity and reservation | P1A, U3 |
| 5A-5B. Inventory returns and transition safety | P1A, U4-U5 |
| 6-7. Supplier advances and payment recipients | P1B/P5, U15-U16 |
| 8. Logistics foundation | P0/P2, U2 |
| 9. Demand claims and packaging snapshots | P3, U6/U9 |
| 10. Receipt and protected evidence | P2/P4, U7/U12-U13 |
| 11. Dedicated dispatch proof | P3-P4 engineering proof only; it does not narrow the all-mode P8 pilot |
| 12. Freight settlement references | P5, U15-U16 |
| Operational release work | P6-P8 |

## 1. Finish the disposable test harness — implemented locally for PostgreSQL

- Objective: package the Phase 1 ephemeral PostgreSQL/storage environment and make destructive bootstrap refuse every unproven target.
- Dependencies: none.
- Likely locations: test runner/CI scripts, `phpunit.xml`, `plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php`.
- Scope: test-only; unique `_testing` database, private storage/cache, non-delivering mail and blank SMS credentials, explicit cleanup, 512 MiB runner decision, documented opt-in reuse. General outbound HTTP blocking remains a separate test-bootstrap decision.
- Acceptance: fresh PostgreSQL run; non-`_testing` rejection under `testing` and `local`; existing Webkul plus synthetic Shafique path; no repository storage changes; cleanup only task resources.
- Delivered: `scripts/run_logistics_phase2a_tests.sh` creates a unique private PostgreSQL cluster, synthetic `_testing` database/role/storage/cache, forces non-delivering integrations, verifies identity before bootstrap, runs only explicit selected tests with 512 MiB, and cleans up only a marker-validated task root.
- Remaining: exercise the shared-service subset on MySQL and wire the checked-in runner into CI only after review. Existing CI's unsuffixed database name is incompatible with the fail-closed bootstrap guard.
- Risk/recovery: never automate cleanup from an unresolved path; fail closed. MySQL service is a separate merge gate.

## 2. Repair the existing reconciliation regression boundary

- Objective: resolve current same-direction fixture/expectation failures before Logistics money tests depend on Recon signals.
- Dependencies: disposable harness.
- Likely locations: `tests/Feature/Recon/MatchAccountingIntegrationTest.php`, `AutoMatchAtomicityTest.php`, `MatchConcurrencyTest.php`, `ReconTestSupport.php`; production change only if an independently confirmed defect exists.
- Scope: current bank-reconciliation behavior only; no Logistics ownership copy.
- Acceptance: selected integration, atomicity, controls, and genuine two-process concurrency tests pass with stored residual/match/event assertions.
- Risk/recovery: do not loosen accounting direction or balance checks to make fixtures green. Correct synthetic debit/credit semantics first.

## 3. Make Sales confirmation atomic and replay-safe — implemented for state-transition replay

- Objective: fix the reproduced late-failure partial confirmation at the common service boundary.
- Dependencies: harness.
- Likely locations: `plugins/webkul/sales/src/Services/OrderWorkflow.php`, `ProcurementRequester.php`, model-hook callers, owner-local tests.
- Delivered: transaction, reload/row lock, in-lock state validation, repeat-confirm no-op, and API delegation to the shared boundary. A late-failure rollback test, sequential replay test, independent PostgreSQL two-process test, targeted API/Filament action, create-page, and confirmed-line workflow coverage pass.
- Remaining: the endpoint supplies neither request key nor payload. Changed-payload and different-key intent conflicts cannot be defined without an approved Sales API idempotency/fingerprint contract and migration. No Logistics code.
- Migration/data risk: add a Sales-owned idempotency record only after that contract is approved; no reinterpretation of existing orders. Forward recovery is retry with the approved fingerprint.

## 4. Define and harden Inventory quant identity/reservation

- Objective: serialize competing reservations without creating a parallel Logistics reservation ledger.
- Dependencies: harness; owner decision on quant identity fields.
- Likely locations: `MoveReserver`, `plugins/webkul/inventories/src/Models/ProductQuantity.php`, quant migration/index, owner-local concurrency tests.
- Scope: read-only duplicate preflight first; lock/create strategy and cross-engine null-safe uniqueness only after identity approval.
- Acceptance: independent 60+60 against 100 cannot over-allocate; 40+60 succeeds; no duplicate buckets; same results through native callers.
- Migration/data risk: group authorized staging data by product/location/lot/package/partner plus proposed storage-category/user semantics. Never delete/merge duplicates automatically. Roll forward with an additive validated constraint; rollback removes only the new constraint, not stock rows.

## 5A. Make one Inventory return request failure-atomic — implemented locally

- Objective: fix the reproduced orphan created by a failed return request at the shared return boundary.
- Dependencies: disposable harness.
- Likely locations: `TransferWorkflow::createReturn()`, Inventory facade/API callers, and focused Inventory tests.
- Delivered: strict non-empty/source-bounded finite positive quantities, pre-write validation, and one authoritative-connection transaction around release/create/reserve/link/event work. Completion and cancellation behavior is unchanged.
- Evidence: empty/foreign/malformed requests leave zero persisted database effects; explicit partial/full returns retain `origin_returned_move_id`; a late event failure restores operations, moves, lines, links, chatter rows, downstream reservation state, and quants; retry and outer rollback pass on disposable PostgreSQL.
- Remaining: the API has no quantity-map input and still returns an error for a done operation instead of creating a return. Do not invent an automatic full return. The tested late event failure occurs before Chatter message creation, but Chatter notification scheduling is not generally after-commit aware; alternate Eloquent connection behavior is also unproven. Track both as shared upstream boundaries rather than overclaiming this database test.

## 5B. Harden remaining Inventory transition and quantity safety — not implemented

- Objective: define canonical quant identity/reservation, completion-versus-cancel serialization, shared return-source state enforcement, cumulative concurrent-return limits, and any approved revision/idempotency contract.
- Dependencies: item 4 and explicit API/replay contracts.
- Likely locations: `TransferWorkflow::complete()`/`cancel()`, `MoveCompleter`, `MoveCanceller`, `MoveReserver`, `ProductQuantity`, and all direct callers.
- Acceptance: genuine independent-process completion/cancel conflicts, late-failure rollback, stock posts once, cumulative return bounds, partial/backorder correctness, API/Filament/Barcode parity, and PostgreSQL/MySQL evidence.
- Risk/recovery: do not infer a public request fingerprint or stock-bucket uniqueness rule. Posted corrections use native returns/adjustments; never edit historical quants/moves directly.

## 6. Approve and implement the Accounts-owned pre-bill advance contract

- Objective: prove or add a genuine supplier advance before a final freight bill, including later allocation.
- Dependencies: accounting owner decisions on recognition, tax, payable account, approval, cash/bank destination, and reversal.
- Likely locations: Accounts Payment/PaymentRegistrar/Reconciler/application service and owner-local tests; Logistics only receives durable references later.
- Scope: explicit advance purpose/reference, company/supplier, idempotency, allocation to later bill, residual/reversal. Do not create a fictitious final bill.
- Acceptance: standalone advance posts a balanced native move, later same-supplier bill allocation leaves exact residual and durable reconcile links, retry/concurrency posts once, wrong-company and unsupported recipient fail with zero effects.
- Migration/data risk: additive Accounts-owned purpose/reference fields or link table if required. No backfill of old payments as advances. Forward correction by native reversal/reallocation.

## 7. Decide and implement supplier versus authorized-recipient support

- Objective: represent the obligation counterparty separately from the person/destination authorized to receive funds.
- Dependencies: Accounts policy; Partner/bank-account constraints.
- Likely locations: Accounts payment request/application service and Partner bank/cash destination model; Logistics role reference later.
- Scope: same-party owner-driver supported; different-recipient is either a controlled mapping with approval/evidence or an explicit block. No validation bypass and no substitution of driver as supplier.
- Acceptance: same-party case passes; changed recipient needs authority and evidence; unsupported different-party case fails before posting; bank/cash destination and company/currency enforced.
- Risk/recovery: immutable authorization snapshot/reference on posted payment; corrections through reversal/new approved payment.

## 8. Create the minimal Logistics plugin foundation — implemented locally

- Objective: establish `plugins/shafique/logistics` discovery, dependency, permission, settings, test, and guarded lifecycle skeleton only.
- Dependencies: existing test harness; conservative foundation lifecycle rule authorized by the Phase 2B assignment.
- Likely locations: vendor-owned plugin directory, root Composer wildcard already present, Plugin Manager integration/tests.
- Scope: no transactional posting screens; dependencies on Sales/Inventory/Accounts/Partners/Products; suspension flag distinct from installation; CLI/UI uninstall and dependency-removal guards.
- Acceptance executed: fresh disposable ERP plus native Logistics install/reinstall and repeatable additive migrations; actual vendor discovery, settings action/ACL/Company isolation, atomic control/history, CLI/UI veto and unrelated-plugin control. Foundation/bootstrap: 22 tests / 166 assertions; unchanged Phase 2A regression selection: 177 / 516. [Evidence and limits](PHASE-2B-IMPLEMENTATION-REPORT.md).
- Lifecycle: installed Logistics and direct/transitive required dependencies are conservatively protected before ordinary UI/CLI cleanup, regardless of Company or history count. Inconsistent installed metadata also fails closed. Partners is native core; installable dependencies use real package identifiers. No stock, return API, Accounts, freight or dispatch implementation.
- Migration/data risk: only foundation tables/settings approved for this PR. Uninstall never deletes posted history; disposable test teardown remains explicit.

## 9. Add demand claims and historical packing snapshots

- Objective: model split claims plus immutable commercial/conversion/loading evidence without duplicating stock.
- Dependencies: Sales atomicity, reservation identity decision, packing policy decisions.
- Likely locations: Logistics domain/application layer and migrations; read adapter over Sales/Inventory.
- Scope: source order line/move/operation references, exact company, claim state/version, commercial snapshot, actual outer-pack plan, variance evidence. No full generic mixed-SKU container engine.
- Acceptance: 40+60 split, 60+60 conflict, master Packaging change after confirmation, legacy unknown provenance, outer pack counted once, unknown capacity remains null.
- Migration/data risk: additive Logistics tables only; snapshots immutable. Roll forward by superseding claim/revision, never rewriting source quantities.

## 10. Add coordinator reports, verified receipt, discrepancies, and protected evidence

- Objective: preserve phone-led facts and separate verification from acceptance and stock posting.
- Dependencies: plugin foundation; receipt evidence/authority policy.
- Likely locations: Logistics commands/policies/storage adapter/tests.
- Scope: append-only report/correction records, reporter/recorder and event/record times, evidence retention, accepted/rejected/unresolved, native return link. Minimal native Filament UI later, with business rules outside callbacks.
- Acceptance: driver report cannot verify; verified damaged/short receipt is allowed; replay does not post stock; evidence bytes/association survive ordinary Chatter deletion and suspension; authorized deletion policy tested.
- Risk/recovery: correction is a superseding event; native stock correction remains a referenced return.

## 11. Deliver the first dispatch-only vertical slice

- Objective: one hired vehicle, one distributor stop, one existing confirmed delivery operation, multiple lines/mixed outer packing, partial load, handover, coordinator updates, verified receipt/discrepancy.
- Dependencies: tasks 3-5 and 8-10.
- Likely locations: Logistics application commands/policies/native Filament resources plus thin Sales/Inventory adapters.
- Scope: no freight money until tasks 6-7 are green; no route optimization, GPS, driver app, portal, bidding, 3D packing, or owned-fleet maintenance.
- Acceptance: actual stock posts exactly once through native transfer; partial/backorder preserved; receipt never reposts dispatch; exact company/ship-to/source trace; retry/conflict and late-failure tests.
- Risk/recovery: suspend new work while allowing existing safe completion; posted corrections use native return.

## 12. Add freight advance, bill, and settlement references to the slice

- Objective: complete the money portion only after the native pre-bill advance and recipient contracts exist.
- Dependencies: tasks 2, 6, 7, and 11; owner approval of hold/recognition policies.
- Likely locations: Logistics freight agreement/allocation references; Accounts application contracts; Recon regression tests.
- Scope: agreement components, scoped holds, advance allocation, final bill(s), partial/final payment and reversal references. One bill may cover several trips; one trip may have several charges/payments.
- Acceptance: advance-before-bill, final allocation/residual, no duplicate money, supplier/recipient separation, discrepancy does not create an automatic hold, Recon consumes native evidence unchanged.
- Risk/recovery: no mutable single freight-status or amount/reference field; native reversal with preserved Logistics linkage.

## Operational-release work after the proof slice

Multi-stop trips and goods-transporter bookings are release backlog, not rejected scope. Add them without requiring an end-to-end vehicle or direct-to-distributor commitment. Distributor-arranged pickup remains policy-dependent. Vehicle body category, nominal length, usable dimensions, payload, and capacity estimates remain independent configuration/history fields; nominal length never determines capacity.

Historical next-step note: at the time of this backlog, item 9 was the proposed next increment. Current work must follow P0-P8 in the consolidated roadmap. Demand claims and posting remain blocked until their Inventory and packaging decisions and gates are satisfied. Operational deployment and MySQL, browser and CI gates recorded here were not passed, and freight settlement still requires the approved Accounts decisions.

Carry-forward gates (not fixed by Phase 2B): prove legitimate numeric-string values from the successful native Filament return action; resolve the return API's current HTTP 500/missing quantity-map contract; test later Chatter/alternate-connection failures, successful/cumulative return replay and completion/cancel concurrency. For claims, combine A=40/B=60 with A's partial dispatch/backorder move-identity change and B's retained traceability. For historical packing, prove actual stock posting after master-data changes. For receipt, separate dispatched reconciliation basis from physically presented quantities. None is silently approved by the foundation.
