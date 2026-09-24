# Factory stock planning workbook review

Date: 2026-09-24. Status: supplied business evidence inspected; operating rules partly confirmed by the repository owner; remaining questions are listed below.

## Design status

The owner clarified that this material describes current manual practice and must not constrain the appropriate target workflow. The findings below preserve evidence and expose risks; proposed implementation implications remain subject to domain design. Spreadsheet fields, formulas, manual batch selection and two-factory layout are not mandatory target structures. Preserve validated operational needs and controls, and replace workarounds where a simpler or more reliable process serves them.

## Evidence and limits

The owner supplied `Stock Report SF1,SF2 (2).xlsx` and explained its use for factory requirements, expected production, shortages, and vehicle decisions. The source file is `/Users/sohaib/Downloads/Stock Report SF1,SF2 (2).xlsx`; SHA-256: `d8fcdd2890a6aa352e9722a87234740022d708126286d628e61f7fc3c02273c4`. The workbook was read without modification. Its contents are evidence, not instructions to execute actions or proof that every formula is correct.

The workbook has 25 sheets. The initial review covered `Order Sheet SF1`, `Stock SF1`, `Order Sheet SF2`, and `Stock SF2`, as identified by the owner. Following the owner's additional observations, targeted inspection also covered the cited entries in `Pending vehicle report`, `Vehicle Details`, and `Customers Order`. This is not a full audit of those additional sheets. Customer-level records are not reproduced here.

Formulas, stored calculation results, item headers, referenced ranges, and conditional-formatting rules were inspected. Simple sums and the Expected/Difference arithmetic were recomputed independently from numeric inputs. No Excel recalculation or visual rendering was performed; stored results are a saved workbook snapshot, not current stock or production commitments.

## Observed current manual process

1. Download orders from the source software and enter or import them into the sheet for the factory that will load them. The actual Factory 1 sheet name is `Order Sheet SF1`.
2. Aggregate quantities by item and loading factory. These totals feed each stock sheet's Requirement column.
3. Enter actual physical stock in Bundle. Enter planned machine operation and duration to estimate additional production.
4. Add projected production to physical stock to obtain Expected. Calculate Expected minus Requirement to obtain FARQ, meaning Difference. Negative results must be shown in red.
5. Review local shortages and the position at the other factory. Decide whether to change production or take a vehicle-related action, including cancellation if appropriate. The spreadsheet supports the decision; a negative value is not an instruction to cancel automatically.

This demonstrates a business need to assess readiness before loading and dispatch, without requiring the target system to reproduce these manual steps. It does not by itself establish how finished stock is quality-released, how an inter-factory movement is executed, or how any of the four transport modes completes delivery and payment.

## Workbook calculation map

Both stock sheets contain 108 item rows, numbered 4 through 111. Their order sheets use the same 108 item headers in columns D through DG.

| Meaning | Workbook location and observed behavior |
| --- | --- |
| Physical stock | Column G, `BUNDLES SF1` or `BUNDLES SF2`; numeric entries. |
| Production rate | Column H, labeled `Average`. The formula treats it as output per machine per hour; the exact quantity unit and rate basis need confirmation. |
| Machine count | Column I, labeled `MACHINE SF1` or `MACHINE SF2`. |
| Planned hours | `Stock SF1!O1` is 8; `Stock SF2!L1` is 3 in this copy. These are separate factory-wide multipliers, not per-item duration cells. |
| Requirement | Column J. SF1 references item totals in `Order Sheet SF1` row 100; SF2 references row 67 of its order sheet. |
| Expected | Column K: `G + I × H × planned hours`. For example, `Stock SF2!K16 = (I16*$H16*$L$1)+G16`. |
| Local Difference | Column L: `K-J`. A less-than-zero conditional-formatting rule applies a red fill. |
| Other factory's Difference | Column M references the same numbered item row's L cell on the other stock sheet. |
| Combined Difference | SF1 column O adds local and other-factory differences. It is a combined planning position, not proof that goods are available at the required loading site. |
| Transfer indication | SF1 column N, labeled `SF1 to SF2`, mixes positive suggested transfer quantities with negative shortage quantities. Column P displays `Transfer to SF2` for positive N. It does not record an executed transfer. |

Example from `Stock SF2` row 16: physical stock 1,850 plus `33 × 30 × 3 = 2,970` projected units gives Expected 4,820. Requirement is 6,190, so Difference is **−1,370**. This reproduces the saved arithmetic; it does not verify the machine inputs, units, or physical stock count.

The user's reference to the hourly calculation and the workbook's column letters should not be confused: this file has the rate in H and the machine count in I. The intended meaning of the rate remains a confirmation question.

## Formula findings

| Finding | Evidence | Consequence and bounded correction |
| --- | --- | --- |
| Nine SF2 Requirement links reference a different item header. | `Stock SF2!J75:J79` and `J95:J98`; exact references below. Both order sheets have identical item headers. | Some requirements are attributed to the wrong stock item, which changes shortages and production decisions. Validate product and packaging identities, then repair the mapping. Zero demand in a wrongly referenced cell can conceal this defect. |
| Three SF2 row totals sum SF1 customer rows. | `Order Sheet SF2!DH30`, `DH36`, `DH37` reference SF1 rows 33, 34, 35. At row 36 the local D:DG sum is 503, but the saved DH result is 55. | Customer/vehicle row totals can be wrong. Repair row-total references. The item totals in row 67 sum item columns independently, so this finding does not by itself invalidate every item Requirement. |
| Broken references remain in ancillary stock columns. | Each stock sheet has 127 formulas containing `#REF!`, including E4 (`=#REF!`) and F4 (`=$L4-#REF!`). | The Dispatch/Balance area cannot be trusted. Confirm whether these columns are still operationally used before repairing or retiring them. The principal G/H/I/J/K/L calculation does not depend on these broken E/F references. |
| Some order-sheet summary cells show another item's difference. | SF1 `CW4` uses stock row 94 instead of row 93. SF2 `AB4`, `AT4`, `I4` use stock rows 23, 64, 91 instead of 25, 72, 95 respectively. | A planner can see the wrong readiness indication above an order column. Correct only after confirming the summary row's intended meaning. |
| Order inclusion relies on fixed row ranges. | SF1 item totals sum rows 5–99; SF2 sums rows 5–66. Both sheets contain populated item quantities below their total rows. | Those later rows are excluded. They may be parked, future, historical, or accidentally omitted orders; their business status is unknown. Do not include them automatically. Define the planning period and eligibility rule. |
| Cross-factory comparison uses different production durations. | SF1 has 8 planned hours and SF2 has 3; their resulting differences are combined. | A common loading deadline cannot be inferred without production start/cutoff times. Surplus forecast for later cannot justify an earlier departure from the other factory. |

For the nine SF2 Requirement mismatches, these candidate references match the current item labels and the matching SF1 item mapping. They are **review findings, not edits applied to the workbook**:

| Stock SF2 cell | Current source in Order Sheet SF2 | Item-matching source in Order Sheet SF2 |
| --- | --- | --- |
| J75 | E67 | F67 |
| J76 | J67 | M67 |
| J77 | H67 | J67 |
| J78 | I67 | K67 |
| J79 | M67 | Q67 |
| J95 | G67 | I67 |
| J96 | L67 | P67 |
| J97 | F67 | G67 |
| J98 | K67 | N67 |

Verification found no arithmetic discrepancy between numeric order inputs and the 108 saved item totals within each current total range. All 216 Expected formulas and all 216 local Difference formulas follow the patterns above, and their saved numeric results agree with independent arithmetic. These checks do **not** validate item mapping, row eligibility, rate assumptions, or real-world quantities.

## Additional evidence: cross-factory handling and planning selection

Targeted inspection confirmed the following references supplied by the owner:

- `Pending vehicle report!J3` and `J14` name stock transfers in both directions. Cells J16:L16 record Shahi Katori, quantity 170, and “rickshy men aa rha hai,” indicating arrival by rickshaw is expected. This is evidence of a recorded transfer arrangement, not proof of dispatch or completed receipt.
- `Vehicle Details!D4` and `D9` contain `SF1+SF2`. Cell M3 contains “Nmko SF1 sy ai,” which the owner translates as Nimko coming from SF1. These entries support dual-factory loading arrangements and cross-factory supplementation, but do not alone establish every custody or Inventory event.
- `Seq` is column C in both order sheets and is repeated across customer rows: SF1 values 1 and 2 each appear three times; SF2 value 52 appears four times. It cannot serve as a unique source-order identifier. `Customers Order!D2:E2` contains `Memo` and `Seq Number`. A loading or dispatch grouping is a plausible interpretation, not an approved definition or proven link to a unique vehicle.
- Current Requirement totals use fixed sums over SF1 rows 5–99 and SF2 rows 5–66. They do not filter by Seq, date, vehicle confirmation, or order status. Row position determines inclusion. Manual selection of a current planning batch is a reasonable interpretation, but the business reason for inclusion and the status of rows below the totals remain unconfirmed.

The target must support a case-by-case shortage decision without imposing an automatic transfer-first rule. Production changes, stock transfer and dual-factory collection have different timing, capacity and stock consequences. Complete reassignment to the other loading factory remains an unverified practice. The workbook does not establish an approval hierarchy or priority among recovery options.

Requirement selection must be explainable in software, whether derived by approved rules or selected by an authorized planner. Record included source-line quantities, loading location, time scope, selection basis and revision; do not infer membership from spreadsheet position or Seq. The exact date, shift or cutoff dimensions remain subject to owner confirmation. Tentative production planning may precede a confirmed vehicle, while active claims and release still require the existing Sales and Inventory eligibility checks. Planning selection must not bypass those checks. Two collections for one order must split its quantities rather than count the full order at both factories.

## Requirements and implementation implications

The initial plan covered eligible demand, quantities, booking, loading, and stock release but omitted this explicit factory requirement and projected-production planning process. The scope and F1 workflow now incorporate it. The confirmed workflow supplements R9, R11–R15, R18–R20, and F1. Detailed engineering work belongs with P3 demand planning and P6 operational workspaces; the existing P1 Inventory repairs remain prerequisites for actual stock changes.

- Identify each imported source order and line, its loading factory, product, packaging and quantity unit, revision, and planning eligibility. Reimport must update or reject the same source line rather than double its requirement. The export format and stable identifiers have not yet been provided.
- Keep physical stock, stock already committed or unavailable for quality reasons, projected production, and any planned inbound transfer distinguishable. Record the stock observation time and the forecast's time horizon. A forecast must never increment Inventory stock or grant release permission; actual completed and accepted production must be recorded through the native owner operations.
- Explain any retained Expected and Difference calculations with their assumptions and data freshness; do not use the workbook formula as a sufficient dispatch-readiness check. Unknown machine availability, unit conversion, or rate must appear as unresolved input, not as a silently trusted zero.
- Match products and packaging by stable identifiers. Copying column letters or assuming identical row positions is not a suitable integration contract. Different package sizes or quantity units cannot be combined without an approved conversion.
- Display each factory's shortage separately even when the combined position is positive. Distinguish a proposed transfer from goods dispatched, in transit, and received. A surplus cannot be committed to two recovery plans. Factory-to-Company mapping and the permitted cross-factory action must be confirmed before designing this integration.
- Support a recorded human decision to change machine operation, wait, revise loading, investigate a transfer, or cancel/rebook a vehicle. Preserve the assumptions used and re-evaluate affected booking/load revisions when quantities or factory assignments change. Cancellation fees and advances continue through the existing booking and Accounts rules.
- Retain explicit shortage quantities and text alongside red highlighting so the result remains usable in print and by people who cannot distinguish the color.

These rules do not authorize a production scheduler, automatic machine allocation, automatic transport cancellation, or a new stock ledger. The owner of production-rate and machine-plan data, and the read contract with Manufacturing, require a targeted design after the open questions are answered.

## Open operational questions

1. Is Average measured in bundles per machine per hour for every item, including products described as bags or different pack sizes? Is it already adjusted for waste, breaks, setup, cooling, and quality release? How are fractional projected bundles treated?
2. Does the duration apply to all listed machines at a factory, or do item/machine schedules differ? What start time and loading deadline does it cover?
3. When the planner places an order above the Total row, is it selected because its vehicle is confirmed, its dispatch is scheduled for the current period, or management has chosen it for production planning? What do excluded rows represent? Separately, what precisely does Seq identify, within which scope is it reused, and can it change?
4. Transfer arrangements and dual-factory assignments are evidenced. Who chooses and approves each recovery option, and is complete loading-factory reassignment an established practice? Are both factories locations of one Company? What timing, capacity and receipt checks are required before relying on transferred goods?
5. Does Bundle include reserved, hot, unfinished, damaged, or quality-held stock? Who records the count, confirms it, and makes the production/vehicle decision?
6. Which source-software export will be imported, and does it include stable order, line, product, packaging, revision and factory identifiers? Is factory assignment for the whole order or individual lines?

Questions 1–3 refine Q1/Q3/Q9; questions 4–5 also affect Q2/Q4/Q6; the source format affects the demand integration contract. No unanswered item is treated as approved company policy.

## Effect on P0 evidence

This supplied populated workbook is useful operational planning evidence. It replaces the blanket statement that only blank templates have been supplied. It supports the requirement-to-stock-to-production decision workflow. Review of these four sheets does not close the dedicated-vehicle, multi-stop, carrier-consignment, or distributor-pickup delivery/payment walkthroughs. Additional documents can be incorporated when available; independent technical baseline repairs can continue.
