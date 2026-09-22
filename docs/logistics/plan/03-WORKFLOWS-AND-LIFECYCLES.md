---
title: Logistics Workflows and Lifecycles
version: 1.1.0
date: 2026-09-20
---

# Logistics Workflows and Lifecycles

## State families

### Planning allocation

`draft -> tentative -> claim_active -> claim_partially_consumed -> claim_fully_consumed`

`claim_released`, `cancelled` and `superseded` are explicit endings. Partial consumption records the quantity consumed and leaves only the remainder active. An active planning claim is the quantity-consuming state of an allocation; it is not an Inventory reservation or permission to issue stock.

### Booking agreement

`proposed -> accepted`

`rejected`, `cancelled`, and `superseded` are explicit endings. Provider acceptance confirms the current agreement revision; it does not prove physical cargo acceptance.

### Load verification

`planned -> loading -> verified`

A material change creates `revalidation_required` and invalidates affected approvals. `cancelled`, `superseded` and `unloaded_before_issue` are explicit endings; they release or revise the related planning claim under F2 and the cancellation matrix. A verified load does not mean that Inventory has issued stock or that the vehicle has departed.

### Inventory execution

`not_started -> submitted -> succeeded`

`failed` and `outcome_unknown` are separate outcomes. A successful issue remains `succeeded` permanently. Any onsite restoration, return or other correction is a separately identified Inventory command and result linked to the original success; it never overwrites the original execution result. Inventory success is required before gate authority can be issued, but Inventory execution is not a gate-authority state.

### Gate authority

`draft -> approved -> issued -> consumed`

Alternative terminal states are `revoked` and `expired`. A material revision invalidates affected authority. Only `issued` for the current exact revision can be consumed at the gate.

### Physical journey

Current location and custody are derived from observed handover, departure, arrival, terminal, delivery-attempt, transfer, return and recovery events. The system does not force every transport mode through one route-state sequence.

### Cargo portion dimensions

A cargo portion does not have one combined status. The current facts progress independently:

- **Custody:** Company onsite, named custodian, intended recipient, wrong recipient, returned custodian, or explicitly uncertain.
- **Physical position:** Origin, in transit, service endpoint, destination, approved return destination, recovered location, or explicitly unknown.
- **Recipient acceptance:** Not presented, presented, accepted, refused, partly accepted, or disputed.
- **Condition and quality:** Reported condition plus any inspection, quarantine, release or disposal decision from the responsible quality process.
- **Outbound outcome allocation:** Accepted by the intended commercial recipient, physically returned without prior acceptance, approved final non-delivery resolution, or still outstanding.

A change to one dimension does not imply a change to another. There is no generic `resolved` cargo state.

### Receiving

Reported observations, cumulative receipt confirmations, reconciled receipt, source attribution and any external stock receipt progress independently. None substitutes for another.

### Protected evidence

`staged -> validated -> accepted`

`rejected`, `quarantined` and `orphaned_after_failed_association` require their defined cleanup or incident handling. When the approved rule and elapsed time make accepted evidence eligible, disposal progresses through `authorized_for_disposal -> disposal_pending -> bytes_removed -> disposed_per_retention_policy`. The immutable metadata and disposal tombstone remain. A crash or failure stays pending for reconciliation; it cannot report final disposal before byte removal is verified. Missing or changed bytes during required retention are an incident, not a lifecycle transition.

### Freight and financial state families

- **Freight agreement:** Proposed, approved, superseded or cancelled by Logistics.
- **Accounts obligation:** Not recorded, draft, posted, reversed or cancelled under Accounts rules.
- **Payment instruction and execution:** Proposed, approved, submitted, outcome unknown, confirmed failed or confirmed successful.
- **Settlement allocation:** Unallocated, partly allocated, fully allocated, reversed or corrected against confirmed native Accounts records.
- **Hold, dispute and claim:** Each has its own amount, authority, reason and open or released outcome; none erases the obligation.
- **Advance recovery:** Not required, recoverable, partly recovered, fully recovered, refunded or reallocated.

Financial progress does not close transport operations, and operational closure does not settle freight. Financial closure requires the Q10 rule and cannot be derived from a single state family.

Exceptions do not force unrelated lifecycles into one linear state. A trip may have an incident or correction requirement while receiving or finance remains open. Cancellation is allowed only after planning claims, booking consequences, advances, stock, custody, evidence and issued documents are treated explicitly.

## F1. Demand eligibility and planning claim

1. Sales/Inventory exposes eligible source lines with exact company, revision, quantity and restrictions.
2. Logistics creates tentative planning without consuming availability.
3. Activating a claim locks the source authority, checks suspension, permissions, revisions and aggregate active claims, then records the bounded claim.
4. Source reduction, cancellation, native completion, or backorder remaps or blocks claims under the shared contract.

Failure behavior: overclaim, stale source, unknown UoM/lot, or native outcome uncertainty creates no active excess and no gate authority.

## F2. Packing commitment and actual load

1. Preserve the commercial packing/conversion snapshot at the approved source commitment.
2. Warehouse records actual product, lot, quantity, outer packs, measured weight/volume and provenance.
3. Differences create a new load revision and invalidate affected checks/approvals.
4. Unsupported mixed/open/fractional/repacked contents remain blocked and assigned, not guessed.

Cancellation behavior: unloading before stock issue releases the planning claim through its normal owner; after stock issue use F6.

## F3. Booking and provider acceptance

1. Coordinator records provider, performing party, service endpoints, assignments, rates/terms and validity.
2. Provider acceptance evidence creates an accepted booking revision.
3. No-show, refusal, replacement vehicle/provider, changed endpoint or charge creates a new revision and revalidates affected safety, authority and freight terms.
4. Cancellation records fees, advance recovery and capacity release separately.

## F4. Release and native stock issue

1. Freeze the exact load revision and native operation/move set.
2. Validate Company, actor, approvals, holds, equipment, evidence, claims and native source state.
3. Execute all required local native Inventory effects and critical Logistics result records in one proven transaction boundary.
4. If any operation fails, roll back the whole departure set. If response is lost, reconcile by command/native result identity before retry.
5. Issue one current gate authority only after a known successful result.

## F5. Gate and physical departure

1. Gate operator verifies current vehicle/person/load revision and unconsumed authority.
2. Consume authority atomically with the departure event.
3. A revoked/stale/expired pass fails without effect.
4. An unauthorized actual exit is recorded as an incident against actual cargo; the system does not rewrite it as approved.

Offline behavior: paper/fallback authority has controlled numbering, limited validity, later catch-up, and duplicate fencing. It does not permit blind native replay.

## F6. Issued but not departed

1. Revoke unused gate authority and record onsite custody.
2. Unload and recount against the frozen load.
3. Execute the approved Inventory-owned correction under lock.
4. Preserve original issue and correction references.
5. Reassign only after availability and quality state are proven.

This flow is a release blocker until the native correction is demonstrated. A customer return is not a substitute.

## F7. Multi-stop and custody transfer

1. Create current cargo portions only when a real split or transfer occurs.
2. Verify segment-specific equipment/capacity and custodian.
3. Transfer custody without another stock issue.
4. Later-stop refusal, return pickup, or breakdown affects only identified portions while preserving the original cohort.

## F8. Carrier consignment

1. Separate factory-to-carrier handover, carrier service endpoint, optional feeder/final-mile custody, and distributor receipt.
2. Preserve issuer-scoped consignment reference and opaque network status without inventing internal itinerary.
3. Terminal arrival completes only the contracted endpoint condition; it does not imply customer acceptance.
4. Partial transfer or provider substitution preserves old terms, custody and charge history.

## F9. Distributor pickup

1. Verify collector identity and authority, pickup endpoint, payer/cost responsibility and receipt responsibility.
2. Native issue and custody handover remain controlled as for other modes.
3. Pickup may satisfy physical handover under approved policy but never silently changes freight/accounting responsibility.

## F10. Receipt reconciliation

1. Record reporter, recorder, occurrence/recording time, input intent and evidence.
2. Match reports to a physical event or cumulative attestation scope.
3. Verify testimony without destroying disagreements.
4. Record presented quantity, acceptance or refusal, physical condition, reported shortage and unmatched overage separately.
5. Allocate identified dispatched quantity only to accepted, returned and received without prior acceptance, approved final non-delivery resolution, or outstanding. Damage remains a condition, shortage remains the unexplained part of dispatched quantity, and overage remains unmatched until its source is established.
6. Preserve unresolved source attribution and assign it for resolution.

Retry behavior: repeated request returns the saved result only after access check. A different report about the same event joins reconciliation; it does not create additive receipt.

## F11. Return, refusal, loss and recovery

1. Refusal alone does not change custody. Record the actual custodian and location, then create disposition work.
2. Customer return references prior acceptance and enters native return/quality handling.
3. Unaccepted physical return references dispatched cargo and approved native destination.
4. An approved loss creates an exact final non-delivery allocation and related financial dependencies where applicable; it does not invent a physical loss event that nobody observed.
5. Recovery creates a known recovered cargo portion with recorded custodian, location, condition and quarantine state. It reverses only the eligible final non-delivery allocation and creates disposition and any required financial-correction work.

## F12. Freight advance, bill and settlement

1. Record approved freight agreement and supplier/recipient/payer roles.
2. Accounts creates a genuine advance authority where policy permits; Logistics links but does not post its own balance.
3. Final supplier document creates or confirms the native liability.
4. Apply advance, company payments, distributor-paid amounts, holds, offsets and reversals once.
5. Allocate resulting native amounts to trips/charges with currency-conserving rounding.
6. Unknown execution blocks replacement until lookup or reconciliation establishes the outcome.

## F13. Suspension, outage and recovery

1. Suspension serializes with new commitments and freezes added cargo/revisions after the approved cutoff.
2. Existing receipt, correction, evidence, settlement and recovery remain accessible to authorized roles.
3. During outage, controlled paper capture records identities and sequence without claiming native success.
4. Recovery fences outgoing workers, restores database and protected evidence, reconciles external/manual outcomes, catches up once, then resumes.

## F14. Opening adoption and historical correction

1. Import stable native references and observed physical/financial position without re-executing effects.
2. Classify unknown provenance explicitly.
3. Repeated import is idempotent.
4. Historical conflicts create owned reconciliation cases.
5. Corrections are forward facts; source history is not rewritten or merged away.

## Cross-flow cancellation matrix

The rows are cumulative. If several milestones have occurred, apply every relevant treatment. For example, cancellation after partial receipt and partial payment must address the remaining cargo, recorded receipts, booking terms, stock effects, advance, liability and payment separately.

| Cancellation point | Required treatment |
| --- | --- |
| Before claim | Cancel plan only. |
| After claim, before booking | Release claim under source lock. |
| After booking/advance | Resolve provider cancellation, fees, advance recovery and claim release independently. |
| After loading, before issue | Unload/revise actuals; invalidate release checks. |
| After issue, before departure | Execute F6; never fabricate customer return. |
| After departure, before receipt | Preserve actual cargo; route return/refusal/loss workflow. |
| Provider cancellation after custody begins | Preserve actual custody and cargo portions; arrange an approved transfer or return, record revised provider terms and settle cancellation charges separately. |
| After partial receipt or partial settlement | Preserve completed receipt and Accounts facts; apply the remaining cargo, correction, liability, advance, hold, payment and recovery treatments independently. |
| After receipt | Correct/return through linked original facts and dependent stock/financial work. |
| After payment | Preserve native payment; reverse/recover/reallocate through Accounts policy. |
