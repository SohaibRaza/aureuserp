# P0 transport-mode tabletop design review

Date: 2026-09-24. Status: documentation-based scenario review, not a facilitated operator exercise, executed application test, completed transaction audit or approval. No participant signoff is claimed.

This review checks all four modes against the [canonical workflows](plan/03-WORKFLOWS-AND-LIFECYCLES.md). The supplied [workbook](FACTORY-STOCK-PLANNING-WORKBOOK-REVIEW.md) provides planning and cross-factory-arrangement evidence only. It does not establish custody completion, customer acceptance, freight liability or payment. The examples below are deliberately illustrative and do not represent transactions found in the workbook.

## Dedicated hired vehicle

**Normal path:** Sales exposes remaining eligible demand. The planner assesses stock and deadlines, agrees provider/vehicle/endpoints and approved freight terms, and activates bounded demand claims. Warehouse records actual product, lot, packaging and quantity. Current load approval plus a successful native Inventory issue enables one gate departure. Custody and recipient acceptance are recorded separately. Accounts records advance, bill, payment and reconciliation as applicable; operational and financial closure are evaluated separately.

**Shortage example:** A load requires 100 approved units; 80 are usable now and 20 are expected after its deadline. The result is a 20-unit deadline shortfall. The planner may seek approved delay, partial loading or cross-site recovery; forecast does not authorize issue. If a revised 80-unit load is approved, the remaining 20 stays unfulfilled. Cancellation after an advance retains recovery/fee work. Cancellation after issue but before exit uses onsite correction, never a fictional customer return.

**Required proof:** Provider acceptance, real load/issue/departure references, receipt responsibility, partial remainder, and advance/cancellation treatment. **Missing:** attributable completed transaction documents and owner confirmation of endpoints, waiting fees and approval limits. Workbook vehicle entries do not supply this proof.

## Multi-stop delivery

**Normal path:** Split eligible quantities among named recipients and stops; verify whole-vehicle capacity and the load remaining on every segment. Issue once per actual factory departure. At each stop, record the affected cargo portion and supported acceptance, keeping the remaining cargo under its actual custodian. A stop completion cannot close undelivered later-stop portions. Freight allocation follows an approved basis and conserves Accounts totals.

**Exception example:** Of 100 units, the first recipient accepts 40; the second refuses 60. Preserve the first acceptance. Record where the refused 60 actually remain, approve return or redirection, and use Inventory return/quality operations only upon their applicable physical event. With dual-site pickup, failure to load at the second factory leaves the first factory's issue and custody unchanged; the second site's incremental release must not reissue cargo already aboard.

**Required proof:** Stop/recipient allocation, per-stop evidence, capacity after pickup, rejected-goods custody and freight allocation basis. **Missing:** completed multi-stop receipt/return records and role confirmation. `SF1+SF2` supports dual-origin arrangements; it does not prove multi-recipient delivery or its financial treatment.

## Carrier consignment

**Normal path:** Record a provider agreement and issuer-scoped consignment reference. Associate explicit shipment portions; record factory-to-feeder, terminal and carrier handovers only where observed. Each custody change preserves issued quantity without another stock issue. Carrier-network details may remain unknown. The contracted terminal endpoint, onward handover and distributor acceptance are separate facts. Link actual freight obligations and payer arrangements through Accounts.

**Exception example:** A 100-unit consignment reaches the terminal; only 90 are reported at the distributor. Terminal arrival does not fulfill the sale. Record the 90-unit report and investigate the missing 10 with source attribution and actual custodian/location uncertainty. Do not infer loss, create a return, or deduct freight automatically. A repeated carrier status update must not create a second receipt. Replacement carrier charges and custody require separate revisions.

**Required proof:** Consignment/booking distinction, contracted endpoint, handover documents, destination receipt, payer and claim responsibility. **Missing:** attributable carrier document, actual terminal/final-mile responsibilities and a reconciled exception/payment example. A carrier reference alone cannot close this mode.

## Distributor-arranged pickup

**Normal path:** Confirm the distributor's authorized collector and scope, pickup site and permitted vehicle. Record actual loading and native stock issue, then custody handover and departure. Apply the approved commercial acceptance rule separately from physical collection. Record who pays transport and whether the Company has any freight obligation; do not manufacture a Company payable solely to make the financial screen complete.

**Exception example:** The collector arrives with different identity or authority for only 60 of 100 units. Stop the unsupported handover; obtain independently verified revised authorization or approve a 60-unit load with a 40-unit remainder. A phone claim alone cannot change the authorized recipient. If the loaded vehicle never exits after issue, use onsite correction. Goods subsequently accepted and returned retain both acceptance and return histories.

**Required proof:** Collector authorization, handover/acceptance meaning, stock issue, no-charge or payer rule, and correction responsibility. **Missing:** completed pickup authorization/handover examples and owner-approved endpoint and payer rules.

## Shared checks and gaps

All four scenarios require exact Company authorization; source quantities and conversions; original and revised deadlines; current load and approval revisions; one native result per consequential command; attributed evidence; controlled paper/basic-phone operation; and separate operational/financial closure. Lost responses require lookup before retry. Independent reports of the same event reconcile rather than add blindly. Unknown quantity attribution, custody, production readiness or payment outcomes remain owned exceptions.

The walkthrough identifies design coverage, not acceptance. Q1/Q2/Q3/Q4/Q5/Q6/Q8/Q10 and applicable Q13 remain governed by the decision register. The [stock-readiness proposal](plan/03-WORKFLOWS-AND-LIFECYCLES.md#stock-readiness-before-loading-proposed-target-for-review) covers pre-loading supply assessment; it does not replace these mode-specific responsibility decisions.

## Bounded operational follow-ups

Create or update one existing mode-validation task per mode rather than one broad “validate logistics” ticket. Each task requires:

1. One representative normal transaction and one relevant exception, provided incrementally; list absent documents explicitly.
2. A named Operations owner plus Warehouse, Sales and Accounts participants as applicable; record actual participation, not assumed role availability.
3. A trace from source demand through quantities, issue, physical handover, receipt, remainder/correction and freight or evidenced no-charge treatment.
4. Recorded discrepancies, approved endpoint/approval/payer rules, and links to bounded implementation defects rather than silently changing the evidence.
5. Separate conclusions for design accepted, application tests executed and operational evidence verified. Complete the task only when its stated evidence and owner approvals exist.

Technical baseline repairs, caller/lock tracing and unaffected Inventory/Accounts integrity tests may proceed while these records arrive. No tabletop result authorizes the all-mode live pilot. Outstanding evidence must remain visible in P0 and the mode tasks.
