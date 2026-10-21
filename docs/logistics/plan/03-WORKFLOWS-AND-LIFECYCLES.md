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

During tentative planning, compare each loading factory's item requirements with physical stock and projected production using the [factory requirements rules](01-SCOPE-AND-REQUIREMENTS.md#factory-requirements-and-projected-production). Show local shortages and cross-factory recovery options with their timing and unresolved assumptions. Record the coordinator's production or vehicle decision and re-evaluate affected plan revisions when inputs change. Neither projected production nor a proposed transfer constitutes stock available for reservation or release; actual stock operations remain subject to Inventory validation.

### Stock readiness before loading: proposed target for review

This design extends F1, not Inventory's stock ledger. It replaces spreadsheet mechanics with an explainable decision about whether the selected goods can be ready at a loading location by a deadline. On 2026-10-15, the project owner approved [parts A-D of the quantity, loading and first-journey rules](12-OPEN-DECISIONS.md#quantity-loading-and-first-journey-decision-proposal--2026-10-15) and confirmed that SF1 and SF2 are separate factory units and locations of one legal Company. Apply that scoped approval to this workflow. Actual product mappings, forecast inputs, capacity and quality profiles, operator assignments and the remaining Q decisions still require their stated validation. Existing prohibitions on shadow stock, excess claims, stale approvals and cross-company access continue to apply. Design approval does not establish implemented schema, configured sites or live operational acceptance.

1. **Form the demand set.** Read remaining commercially eligible Sales line quantities and their source revisions. Propose lines needed by the planning cutoff; allow an authorized planner to include, defer or split quantities with a recorded reason. A tentative plan may precede vehicle confirmation. Cancelled, completed or commercially blocked quantities cannot become active claims. Keep excluded and unresolved demand visible with reasons. A source line split across loading sites retains one original identity and cannot consume its remaining quantity twice.
2. **Establish time and quantity meaning.** Record Company, loading location, deadline and time zone, demand-policy version, source-line quantities and approved unit conversions. Preserve the original requested deadline alongside revisions. A missing deadline or conversion produces an unresolved assessment, not a positive readiness result. `Seq` may remain an imported display reference if useful; it is neither identity nor eligibility.
3. **Read current usable supply.** Inventory supplies eligible stock at the required location, with lot, owner, quality, expiry and reservation restrictions. For this load, usable quantity is eligible free stock plus eligible stock reserved specifically for this load. Stock reserved for other work and held stock are excluded once, using Inventory's definitions; never subtract overlapping exclusions twice. A manual count discrepancy creates Inventory count/reconciliation work, not a Logistics balance adjustment.
4. **Assess future supply separately.** A production forecast records the responsible owner, product/unit, remaining output, expected ready-at time, assumptions, update time and source revision. Ready-at includes completion, required cooling, packing and quality-release time. Count only output expected before the loading deadline and not already received or attributed to other demand. Keep proposed production distinguishable from owner-confirmed scheduled output. Neither category is reservable stock. When Inventory records production receipt, remove that quantity from remaining forecast so it cannot be counted twice.
5. **Show a result that explains the gap.** Display demand, usable quantity now, stock shortage now, eligible future production, planned inbound supply and deadline shortfall separately. A projected surplus is not ready stock. Use `ready from current stock`, `forecast dependent`, `shortfall`, or `unresolved inputs`, with quantities and reasons, rather than one unconditional green total. An assessment stores observation times and source revisions and becomes stale when its governing input changes. Even a current positive assessment does not reserve stock, verify loading or authorize departure.
6. **Resolve shortages explicitly.** Compare production change, partial loading, later departure, same-company transfer, dual-site pickup and revised loading location against deadlines, handling capacity, quality, existing allocations and booking consequences. Rank or calculate simple feasibility where inputs exist; do not automatically choose a recovery action. Local shortages remain visible when combined factory supply is positive. The responsible planner records the chosen option, affected quantities, owner, due time and required approvals; rejected alternatives need not become a separate workflow system.
7. **Commit through existing owners.** Activate demand claims under IC2; reserve through Inventory; record actual load under F2. Recheck current restrictions and revisions at every consequential operation. Production confirmation is not release authority. Changes after loading use F2; after issue but before departure use F6; after departure use custody/return rules rather than a planning cancellation.

For numerical assessment, `shortage now = max(0, selected demand − usable supply for this load)`. `deadline shortfall = max(0, selected demand − usable supply − nonoverlapping eligible future supply)`. Preserve the individual terms and explain whether future supply is proposed or confirmed. These are planning calculations only. Future supply attributed to competing plans must not be presented as confirmed coverage twice; tentative alternatives may overlap only when explicitly shown as alternatives.

#### Cross-factory recovery controls

| Option | Required treatment |
| --- | --- |
| Same-company stock transfer | Inventory owns source reservation, dispatch, in-transit treatment and destination receipt. Planned arrival must include handling/transport time. A proposed or dispatched transfer remains future supply at destination; only received eligible stock becomes usable there. Remove source availability once and do not count the same goods at both sites. A transfer that arrives late makes the assessment stale and opens replanning work. |
| Dual-site pickup | Split source-line quantities by origin. Each departure has its own verified incremental load, native stock issue and gate checks. Assess capacity and compatibility using cargo already aboard plus the new load. Arrival at the second site does not issue the first site's goods again. Refusal or delay at the second site preserves the first site's actual custody and stock history. |
| Change loading location | Before issue, revise allocations and booking, release or remap reservations through Inventory, and invalidate affected checks. Preserve commercial delivery commitments and old revisions. After any issue, use the appropriate physical correction or custody movement; changing a location field cannot relocate goods. |
| Different legal Companies | Do not treat the movement as a same-company transfer or enable a shared multi-company trip. The existing pilot exclusion applies. A separately approved intercompany commercial and accounting design is required before activation. |
| Partial load, delay or cancellation | Preserve the unfulfilled remainder, original deadline, revised promise and customer communication responsibility. Reapprove affected bookings and freight; Accounts handles fees and advance recovery. Negative readiness never automatically cancels a vehicle. |

#### Service contracts and failure behavior

Implement this as narrow extensions of the existing allocation and query services, not a scheduler or generic planning engine. The read assessment accepts Company, authorized location, plan revision and cutoff and returns source identities/revisions, quantity units, per-location terms, timestamps and reason codes. Reuse IC1/IC2 for demand and IC4/IC6 for eligible stock. Before implementation, the production owner must approve the remaining-output and ready-at contract; do not read mutable machine spreadsheets as an authoritative API.

Saving selection or recovery requires the expected plan revision and the standard command identity from IC contracts. Save the decision and any demand attribution in one local transaction under deterministic source/allocation locking. A concurrent 60-plus-60 selection against 100 remaining units cannot create 120 active claims. A query snapshot is never a lock: reservation and release revalidate through their owning commands. Future-output attribution, if used for confirmed planning coverage, serializes against the same forecast source and rejects excess; it does not create Inventory reservations. Actual Inventory availability remains decisive.

Same-key/same-intent replay returns the original authorized result; changed intent conflicts. Imports map stable source order/line identities and source revisions, not row positions. A missing stable identity is quarantined for mapping. Offline observations retain their observation time and are marked stale until reconciled; offline planning cannot fabricate a reservation or stock issue. Unknown native outcomes block replacement action until lookup establishes the original result.

#### Bounded implementation sequence

These are proposed subdivisions of U9/U10/U17, not new phase gates or permission to skip P1A/P2. Each task must cite the complete parent phase contract. Add only the missing fields to existing allocation/revision records; no duplicate stock or forecast ledger is authorized.

| Work unit | Dependency and scope | Completion evidence |
| --- | --- | --- |
| [Demand eligibility read contract](https://github.com/SohaibRaza/aureuserp/issues/65) | Q1/Q3 planning policy, U6; Sales read service exposes remaining quantity, restrictions, due date and source revision. No planning write or UI. | Cancelled/completed/held lines have explicit reasons; partial completion/backorder counted once; exact-company access and unknown conversion tests. |
| [Versioned planning selection](https://github.com/SohaibRaza/aureuserp/issues/66) | Prior read contract, U9 prerequisites; allocation service saves bounded selections, reason, loading site and deadline. No stock update. | Two independent workers cannot overclaim; split-site quantities conserve demand; stale revision, repeat import, same-intent replay and changed-intent conflict tests. |
| [Current-stock readiness query](https://github.com/SohaibRaza/aureuserp/issues/67) | Inventory integrity acceptance and approved usable-stock policy; read query and result DTO only. | Other-load reservations and holds excluded once; own reservation not double-subtracted; lots/expiry and cross-company negatives; stale observation explicit. |
| [Production readiness input](https://github.com/SohaibRaza/aureuserp/issues/68) | Approved production owner contract; native forecast read adapter or attributed approved manual forecast input, not both without need. | Partial production receipt reduces outstanding forecast; late/unknown ready-at cannot mark ready; conversion and competing attribution tests. No stock writes. |
| [Recovery revision command](https://github.com/SohaibRaza/aureuserp/issues/69) | Selection and stock/forecast contracts, Q2/Q6; record one chosen recovery and revise affected planning references. Execute stock/money only through accepted owner commands. | Transfer delay and dual-site second-stop failure preserve original effects; competing recovery cannot claim the same source twice; fee/advance dependency stays open. |
| [Readiness workspace](https://github.com/SohaibRaza/aureuserp/issues/70) | Accepted read and revision services, U17; accessible per-location deadline view and action history. | Keyboard and printed view show amounts and reasons without reliance on color; current-stock vs projected readiness remains distinct; permissions match service calls. |

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
