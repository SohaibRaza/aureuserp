# Bank Reconciliation Accounting Specification

**Status:** Canonical accounting contract
**Effective:** 2026-09-15
**Applies to:** AureusERP bank reconciliation U1-U18

## 1. Objective and authority

Bank reconciliation demonstrates that the bank's independent closing balance and the entity's posted cash-at-bank ledger balance agree after valid timing differences and bank-side errors. It is a control and evidence process; it does not replace journal posting, payment settlement, or receivable/payable reconciliation.

This document is the product accounting contract. The code is an implementation to be tested against it, not an authority that can redefine it.

## 2. Identity and cutoff

One reconciliation covers exactly one company, bank journal, company bank account, liquidity GL account, currency, period, and statement date. The statement date is the GL cutoff unless the product later supports an explicitly documented alternative.

- Opening book balance: sum of posted in-scope liquidity lines strictly before `period_start`.
- Closing book balance: sum of posted in-scope liquidity lines on or before `statement_date`.
- Draft/unposted moves, other companies, other liquidity accounts, and future-dated lines are excluded.
- For a foreign-currency bank account, the reconciliation uses the account transaction currency consistently; company-currency reporting remains separately traceable.

## 3. Required equations

### Statement arithmetic

`statement opening + sum(accepted statement lines) = declared statement closing`

This proves internal statement completeness only. It does not prove agreement to the books.

### Reconciliation equation

`adjusted bank = statement closing + deposits in transit - outstanding payments + signed bank errors`

`adjusted book = posted liquidity GL balance at cutoff`

`difference = adjusted bank - adjusted book`

Completion requires `difference = 0` at the currency precision. Posted bank fees, interest, returned-payment entries, transfers, FX entries, and book corrections already affect the GL and must not be added to adjusted book a second time.

## 4. Accounting treatments

| Scenario | Required treatment | GL effect in reconciliation |
|---|---|---|
| Outstanding payment/check | GL-backed timing item; carry forward until it clears | None |
| Deposit in transit | GL-backed timing item; carry forward until it clears | None |
| Bank fee/direct charge | Posted bank credit against valid expense or other counterpart | Included once through posted GL |
| Interest income | Posted bank debit against valid income counterpart | Included once through posted GL |
| Bank error | Signed bank-side reconciling item with approval/evidence | Adjusted-bank schedule only |
| Book error | Correcting or reversing journal entry | Included once through posted GL |
| Duplicate statement row | Exclude with reason and source evidence | None |
| Returned/failed payment | Dedicated reversal workflow preserving payment provenance | Included through posted reversal |
| Inter-account transfer | Balanced transfer between valid balance-sheet liquidity accounts | Included through posted GL on both accounts |
| FX difference | Posted gain/loss workflow using dated currency evidence | Included through posted GL |
| Unidentified bank activity | Remains open; classify and post only after sufficient support | Blocks completion |
| Voided/reversed item | Preserve original and reversal; never erase evidence | Net posted GL effect plus explicit provenance |

Bank errors and duplicates never create GL entries merely to force the reconciliation to zero. Timing items require actual posted liquidity-line evidence and an owner/reason when carried forward.

## 5. Matching

- The bank amount and ledger allocation must agree in direction and amount at supported precision.
- One-to-one, one-to-many, many-to-one, many-to-many, and partial allocations are supported only when residuals are exact and no source is over-consumed.
- `allocation_only` is the normal bank-clearing treatment and does not manufacture AR/AP partial reconciliation.
- A confirmed match is reversible only through the controlled unmatch path, which restores owned residuals and retains history.
- Concurrent attempts cannot consume the same residual twice.

## 6. Terminal treatment and completion

Every accepted bank line requires one valid terminal treatment and reference. Supported final references are confirmed match, posted non-reversed adjustment, approved bank error, or justified duplicate exclusion. Other dedicated workflows must ultimately create one of the supported evidence states defined by the service contract.

Completion also requires:

- at least one accepted statement line;
- valid statement arithmetic;
- no proposed matches;
- no unsupported or unsourced reconciling item;
- zero difference; and
- authorized review and completion.

## 7. Period corrections

Completed periods are immutable. A correction requires a reasoned reopen request and approval. Reopening or posting a historical correction invalidates affected later conclusions for the same liquidity identity; those periods become stale and require rework in chronological order.

## 8. Audit minimum

The retained evidence must identify the actor, time, company/bank identity, source statement and import hash, accepted/duplicate/quarantined rows, matches and allocations, accounting mode, adjustments and posted moves, exceptions and evidence, lifecycle transitions, completion calculation, immutable snapshot hash, and reopen/stale causality.

## 9. Non-claims

- A zero difference does not prove the absence of fraud or an incorrect-but-offsetting pair of entries.
- CSV import does not prove statement authenticity.
- Bank reconciliation does not replace bank confirmation, cash controls, segregation of duties, or AR/AP reconciliation.
- OFX/QFX, MT940, CAMT.053, and bank-specific CSV profiles are not supported until implemented and tested with representative fixtures.
