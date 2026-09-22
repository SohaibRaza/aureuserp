# Logistics Phase 1 Integration Baseline — Historical

Status: historical Phase 1 contract proposal at commit `45957cb763369e07ba46c702c170c09dab7b5a35`. It records source findings and decisions that led to the consolidated plan. It authorizes no production schema or posting change. The current target contracts are in [`plan/05-INTEGRATION-CONTRACTS.md`](plan/05-INTEGRATION-CONTRACTS.md), and unresolved business policy is in [`plan/12-OPEN-DECISIONS.md`](plan/12-OPEN-DECISIONS.md).

## Decisive boundaries

Logistics owns transport planning, role assignments, packing/loading snapshots, coordinator reports, distributor verification, discrepancy evidence, freight agreements/holds, and references to native financial documents. Sales owns commercial demand. Inventory owns reservation, lots/packages, stock moves, completion, backorders, and returns. Accounts owns obligations, advances, bills, payments, allocation, residuals, reversal, and accounting policy. Partners/Product/Support remain the master-data owners. Logistics must never post a second stock or money ledger.

The operating scope is the source transaction's exact `company_id`, including a child Company used as a branch. No parallel `branch_id` is introduced. Every referenced native record must match that company unless an existing native cross-company rule explicitly authorizes otherwise.

## Logical model and cardinalities

| Concept | Ownership and cardinality |
| --- | --- |
| Trip | Logistics aggregate; has one or more stops and may reference many native shipments/operations. A native operation may be split across multiple active claims/loads over time, but active claimed quantity is bounded. |
| Booking | Logistics transport terms; may cover one or more trips. It does not imply vehicle, payer, supplier, or final cost bearer. Goods-transporter bookings may have no known end-to-end vehicle. |
| Stop/shipment link | One stop belongs to one trip and references one destination snapshot plus one or more native operations/moves. Multi-stop is not forced into a one-operation trip. |
| Demand allocation | Many allocations may reference one native move. Active consuming allocations sum to no more than eligible remaining quantity. Planning claims are not native stock reservations. |
| Load line / packing snapshot | Belongs to an allocation and records commercial quantity basis, authoritative stock quantity/UoM, and outer physical packs separately. Actual lot/package lines reference native evidence. |
| Party role | Trip/booking has independent contracted-provider, vehicle-owner, driver, and authorized-recipient role bindings to native Partner/person records. One identity may fill several roles explicitly. |
| Report / receipt observation | Append-only facts for reporter, recorder, event time, recording time, channel, evidence state, quantities, and corrections. Verification confidence is separate from physical acceptance/disposition. |
| Freight agreement / charge | Agreement has components; many charges may belong to one trip and one charge may be allocated across native bills. One bill may cover several trips. Payments/advances are referenced through allocation records, not one mutable trip field. |
| Protected evidence | Logistics-owned association and retention metadata; Chatter may link narrative context but is not the preservation owner. |

## Quantity and snapshot rules

`UOM::computeQuantity()` uses current category/factor/rounding (`plugins/webkul/support/src/Models/UOM.php:100-135`). Product Packaging stores mutable `product_id + qty` with no version (`plugins/webkul/products/src/Models/Packaging.php:17-32`). Inventory move calculations also use current UoM conversion. Therefore a loading-time-only snapshot cannot reconstruct an order confirmed before master-data change.

The contract is:

1. Sales/Inventory stored `product_uom_qty`, source UoM, and authoritative stock/base quantity remain the demand truth; Logistics does not reinterpret them through today's Packaging row.
2. Capture conversion intent at the earliest logistics-visible commitment boundary, normally Sales confirmation or explicit release. Store source document/line IDs, original commercial quantity/UoM, packaging ID if present, applied numerator/denominator or factor, rounding rule/result, provenance timestamp, and whether provenance was native, user-approved revision, or missing legacy evidence.
3. Loading may choose a different physical pack configuration. Store actual outer-pack count, pack type/dimensions/basis, contained authoritative stock quantity, and variance/revision approval separately.
4. Parent outer packs and contained packets are alternative physical presentations of the same contents and cannot both increment physical load totals.
5. Unknown capacity/weight/volume is nullable/unknown, never zero. Bundle volume is an estimate with basis and tolerance, not a rigid fit guarantee.
6. Legacy records without conversion evidence keep known native quantities and `conversion_basis=unknown`; never backfill today's factor as historical fact.

## Mutating command contracts

### 1. Confirm eligible demand

Current verified behavior is state-transition replay safety: Sales owns one transaction, locks/reloads the order, rolls back late synchronous failure, and permits two racing callers to receive the same confirmed result while persisting one procurement operation. The request-key/fingerprint behavior below is a future contract, not an implemented API.

- Inputs: order ID, expected state/revision, request ID and canonical payload fingerprint, acting user/company.
- Authorization: native Sales update/confirm permission and access to the exact order company.
- Preconditions: draft/sent state validated after reload; referenced customer, ship-to, warehouse, product/UoM/packaging context is company-consistent.
- Owner/boundary: Sales `OrderWorkflow::confirm()` is authoritative (`plugins/webkul/sales/src/Services/OrderWorkflow.php:43-58`). It must own one DB transaction, lock the order before state validation, serialize procurement reconciliation, and deliver optional notifications after commit.
- Duplicate/conflict: currently, racing/repeated state transitions converge on one confirmed order and one procurement result. Proposed future behavior is same request ID + same fingerprint returns the prior result and same key + different fingerprint rejects; no request-key persistence exists yet.
- Result: one state transition, one coherent procurement/move chain, one confirmation fact. No partial procurement after late failure.
- Correction: native cancel/back-to-quotation only where current Sales rules permit; never direct status editing.
- Acceptance: repeated, competing independent-process confirmation; injected late listener failure; API/Filament/create-page parity; confirmed-line edit caller coverage.

### 2. Claim eligible demand for a load

- Inputs: native operation/move/order-line IDs, claim quantity in native move UoM, expected source revision/state, load ID, request ID/fingerprint.
- Authorization: logistics planning permission plus native company access.
- Preconditions: outgoing operation is confirmed/assignable; move is not canceled/done beyond eligible remainder; UoMs are compatible; claim company equals source company.
- Availability: `eligible = native demanded quantity - native completed/canceled disposition - sum(active consuming logistics claims)`, without decrementing stock. Consuming states are `held` and `committed`; `draft`, `released`, `canceled`, and superseded revisions do not consume. `posted` retains history but ceases to be a planning claim because native completion is authoritative.
- Owner/boundary: Logistics serializes the source move/version and all compatible active claims. Native reservation remains `MoveReserver`/ProductQuantity. A uniqueness constraint on `inventory_move_id` alone is forbidden.
- Duplicate/conflict: identical replay returns the claim; fingerprint mismatch rejects; under one lock, 60+60 against 100 cannot both remain 60, while 40+60 can both succeed.
- Result: versioned claim and immutable source quantity/conversion snapshot; no stock posting.
- Correction: release/cancel then reallocate with history; source move revision invalidates or requires explicit review of affected claims.
- Acceptance: 60+60, 40+60, cancel/reallocate, source quantity reduction, cross-company IDs, independent connections.

Before any stock-quant uniqueness migration, run an authorized read-only preflight grouped by the current identity used by `ProductQuantity`: `product_id`, `location_id`, nullable `lot_id`, nullable `package_id`, nullable `partner_id`. Decide whether `storage_category_id` and `user_id` belong to identity. Specify PostgreSQL/MySQL NULL-equality behavior and existing indexes. Duplicate rows must be reported for business-approved remediation; never silently delete or merge stock.

### 3. Record loading and post dispatch

- Inputs: trip/load, actual lines with native move IDs, actual stock quantities/UoMs, lot/package details, expected operation revision/state, variance approvals, request ID/fingerprint.
- Authorization: load-verification permission; separate variance approval where policy requires; native transfer permission.
- Preconditions: claims cover actual quantities; exact company; operation is ready; native lot/package rules satisfied; lock order is operation, moves in stable ID order, relevant quant buckets, then logistics claims.
- Owner/boundary: a thin Logistics command validates and snapshots, then calls a transaction-owning native `TransferWorkflow::complete()` once. Current completion mutates moves/quants/backorders and dispatches `OperationDone` (`plugins/webkul/inventories/src/Services/TransferWorkflow.php:64-92`) but has no shared transaction/lock contract.
- Duplicate/conflict: same fingerprint returns prior native operation/result; different payload for the key rejects; completion versus cancel/return under other keys permits exactly one coherent terminal result.
- Result: native completed quantities, stock buckets, operation/backorder/lot-package evidence plus Logistics handover reference. Distributor receipt never calls dispatch completion again.
- Correction: native return/reversal/adjustment only, linked to original move/order line; no deletion or negative shadow posting.
- Acceptance: retry, conflicting completion, partial/backorder exactness, injected `OperationDone` failure, source/destination stock once, API/Filament/Barcode parity.

### 4. Record report and verify receipt

- Inputs: trip/stop, report kind, reporter Partner/person, recorder user, event time, recording time generated independently, channel, evidence refs, observed accepted/rejected/unresolved quantities, request ID/fingerprint.
- Authorization: coordinator may record; verifier is a separate approved role.
- Preconditions: source dispatch exists; quantities reconcile to the dispatched basis; event time may precede recording time and is never rewritten to mimic DB order.
- Owner/boundary: finalize and verify required evidence bytes in protected storage before accepting their database association. Then persist the receipt observation, immutable evidence reference and any authoritative allocation in one database transaction. Database rollback may leave a provably unreferenced storage object for cleanup, but it must never leave an accepted receipt referring to missing bytes. Optional notifications run after commit.
- Duplicate/conflict: exact replay returns prior event; changed payload under same key rejects; corrections append a superseding event and retain the original.
- Result: driver report, coordinator record, distributor verification, physical condition, acceptance/disposition, and discrepancy/return links are separate dimensions. Verified shortage/damage is valid; verification does not mean full acceptance.
- Correction: append correction with actor/reason/evidence; native return only after separately authorized disposition.
- Acceptance: a driver report cannot verify receipt. Presented quantity is split into accepted and refused quantities, with condition recorded independently. Dispatched quantity is reconciled to accepted, returned and received without prior acceptance, approved final non-delivery resolution, and outstanding quantity. Unmatched overage remains outside dispatched allocation until its source is established. Repeated receipt does not post stock, and missing or late event times remain explicit.

### 5. Create return

- Inputs: completed source operation and explicit source move quantities/refund flags. Expected revision and request ID/fingerprint remain proposed, not implemented.
- Authorization: native return permission plus discrepancy/correction approval.
- Preconditions: API/Filament callers currently require a completed source operation, but the shared workflow does not yet enforce that state. Implemented shared validation requires a non-empty map of finite positive `int|float` quantities and rejects moves outside the source operation. Shared state enforcement and cumulative active-return limits remain item 5B.
- Owner/boundary: Inventory `TransferWorkflow::createReturn()` validates before persistence and owns one transaction on the source operation's connection around reservation release, operation/move creation, reserve, synchronous database event work, and cross-links. The event runs before Chatter message creation, so the tested late event failure schedules no follower notification callback. Source queries and replicated return models retain that connection. The Phase 1 empty-input orphan is repaired locally on the configured single database connection; alternate-connection downstream static queries are not proven. Chatter's application-termination callback is still not generally after-commit aware, so failures after message creation remain a shared boundary gap.
- Duplicate/conflict/result/correction: successful-request replay and cumulative concurrent over-return prevention are not implemented. A created return retains native `origin_returned_move_id`; corrections use another authorized native return/reversal, never rewrite posted stock.
- Acceptance: empty/foreign/malformed maps, explicit partial/full return, late failure rollback including links/reservations/chatter, retry after failure, ambient rollback, and current API error zero-effects pass. Cumulative sequential/concurrent returns remain item 5B.

### 6. Approve freight and create Accounts evidence

- Inputs: agreement/charge components, contracted supplier, authorized recipient, destination, company, approval evidence, hold scope, request ID/fingerprint.
- Authorization: separate charge, additional-charge, advance, payee-change, hold, and correction authorities as owner policy decides.
- Preconditions: supplier and recipient are explicit; company/reference consistency; charge approval does not imply payment approval; receipt discrepancy does not automatically create a hold.
- Owner/boundary: Logistics owns agreement/approval facts; Accounts owns recognition, advance, bill, payment, reconciliation, residual, reversal, tax, and liability policy.
- Native evidence: bill API payment requires a posted bill and pending state (`plugins/webkul/accounts/src/Http/Controllers/API/V1/BillController.php:320-378`). `PaymentRegistrar::register()` creates, posts, reconciles, then synchronously dispatches `MovePaid` (`plugins/webkul/accounts/src/Services/PaymentRegistrar.php:19-40`). Standalone supplier Payment provides a possible pre-bill primitive, but no approved advance purpose/allocation/idempotency contract is proven.
- Supplier/payee: native Payment has one accounting `partner_id` and optional `partner_bank_id`; it does not model a distinct authorized recipient. A driver receiving cash is not thereby the supplier.
- Duplicate/conflict: one approved intent cannot produce duplicate payment moves/reconciliations; same key/different payload rejects; competing keys against one residual serialize at the Accounts obligation/reconcile boundary.
- Result: separate agreement status, charge approval, bill references, advance/payment allocations, residual/payment state, dispute/hold scope, and reversal history. A hold never erases liability.
- Correction: native reversal/unreconcile/adjustment policy with durable references.
- Acceptance: genuine pre-bill advance then later allocation; owner-driver same-party case; different-recipient supported mapping or explicit block; partial 60+60 against 100; wrong-company journal/bank/method; retry/concurrency; after-commit notifications.

## Native advance qualification

The audit's “advance missing” statement is too absolute. Source inspection found a standalone vendor Payment UI/service that can post before a bill and native later reconciliation paths. What remains missing is a source-tested, policy-approved **supplier advance** contract: purpose/approval, payable account, company/currency, supplier versus recipient, durable Logistics reference, later allocation to the final bill, idempotency, tax/liability timing, reversal, and reporting. It remains on the critical path; testing a payment against an already posted bill is not proof.

## Suspension, uninstall, and evidence matrix

| State/action | New plan/booking | Existing dispatch/receipt/exception/return | Freight settlement/correction | History/evidence |
| --- | --- | --- | --- | --- |
| Active | allowed by permission | allowed | allowed by permission/policy | readable |
| Operationally suspended | blocked | allowed where safety/policy requires completion | allowed for existing obligations/corrections | readable |
| Uninstall requested with history | blocked before any `down()`/cleanup | n/a | n/a | preserved |
| Dependency removal requested | block while Logistics references Inventory/Accounts history; test each dependency path | n/a | n/a | preserved |

`is_active=false` is not this matrix. CLI uninstall rolls migrations/settings down and deletes plugin state; Inventory uninstall deletes transactional tables. Guard CLI and UI before destructive callbacks, preserve the migration ledger/flag on rejection, and test dependency removal separately. Disposable development reset remains allowed only inside proven ephemeral infrastructure.

Chatter attachment deletion removes stored bytes, and uninstall cleanup purges shared attachments. Critical evidence therefore needs Logistics-owned protected storage/association, company access, retention class, legal/operational deletion rule, and atomic event association. A hash detects changed bytes but cannot recover deleted bytes. Narrative Chatter links and optional notifications are secondary.

## Explicit unresolved policy

- Actual stock unit and allowed carton/bundle/packet/loose/fractional/mixed-SKU rules.
- Sufficient receipt evidence and authorized verifier.
- Authorities for load variance, additional freight, advances, recipient changes, corrections, and scoped holds.
- Native backorder/open remainder versus close-and-new-order practice.
- Accounts recognition, tax, pre-bill advance, different-recipient, and reversal policy.
- Per-mode service endpoint, documents, charges, pickup liability, feeder and final-mile responsibility, and receipt responsibility. Dedicated vehicles, multi-stop delivery, carrier consignments and distributor pickup are all mandatory for the first live pilot.
- Local document names/languages/numbering and meaning of `24x48`.

Synthetic tests may choose labeled fixture values, but none becomes a production default.
